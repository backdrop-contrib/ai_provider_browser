(function ($) {
  'use strict';

  Backdrop.behaviors.aiBrowser = {
    attach: function (context, settings) {
      if (!settings.aiBrowser) {
        return;
      }

      if (context !== document || window.__aiBrowserAttached) {
        return;
      }
      window.__aiBrowserAttached = true;

      const config = settings.aiBrowser;
      let isPolling = false;

      const withTimeout = (promise, ms) => Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error('Timeout after ' + ms + 'ms')), ms))
      ]);

      /**
       * Get the modern AI API objects if they exist.
       */
      const getAiApi = () => {
        return window.ai || null;
      };

      /**
       * Check availability for a specific AI feature.
       */
      const getAvailability = async (feature) => {
        const ai = getAiApi();
        if (!ai) {
          // Fallback to legacy names if present.
          if (feature === 'languageModel' && typeof LanguageModel !== 'undefined') {
            try {
              return await LanguageModel.availability();
            } catch (e) {
              return 'no';
            }
          }
          return 'no';
        }

        try {
          if (ai[feature] && typeof ai[feature].availability === 'function') {
            return await ai[feature].availability();
          }
          // Some versions use capabilities()
          if (ai[feature] && typeof ai[feature].capabilities === 'function') {
            const caps = await ai[feature].capabilities();
            return caps.available || 'no';
          }
        } catch (e) {
          console.error(`AI Browser ${feature} availability error:`, e);
        }
        return 'no';
      };

      const poll = async () => {
        if (isPolling) return;

        // Check foreground only setting.
        if (config.foregroundOnly && document.visibilityState !== 'visible') {
          return;
        }

        isPolling = true;

        try {
          const response = await fetch(config.pollUrl, {
            credentials: 'same-origin',
            cache: 'no-store'
          });
          const requests = await response.json();

          if (requests && Object.keys(requests).length > 0) {
            for (const uuid in requests) {
              const request = requests[uuid];
              // Check if the specific operation is enabled and allowed.
              if (request.operation === 'chat' && !config.enabledFeatures.chat) continue;
              if (request.operation === 'translate' && !config.enabledFeatures.translate) continue;
              if (request.operation === 'summarize' && !config.enabledFeatures.summarize) continue;

              await processRequest(request);
            }
          }
        } catch (e) {
          console.error('AI Browser poll error:', e);
        } finally {
          isPolling = false;
        }
      };

      const processRequest = async (request) => {
        try {
          let result;
          if (request.operation === 'chat') {
            result = await runChat(request);
          } else if (request.operation === 'translate') {
            result = await runTranslate(request);
          } else if (request.operation === 'summarize') {
            result = await runSummarize(request);
          }

          await submitResult(request.uuid, result);
        } catch (e) {
          console.error('AI Browser process error:', e);
          await submitResult(request.uuid, null, e.message);
        }
      };

      const runChat = async (request) => {
        const availability = await getAvailability('languageModel');

        if (availability === 'no') {
          throw new Error('LanguageModel API not available in this browser');
        }

        if ((availability === 'after-download') && !config.allowDownload) {
          throw new Error('Language model download is required but not allowed on this page');
        }

        if (availability === 'after-download' && !confirm(config.downloadMessages.chat)) {
          throw new Error('Download cancelled by user');
        }

        const messages = Array.isArray(request.messages) ? request.messages : [];

        const getTextContent = (content) =>
          typeof content === 'string' ? content :
          (Array.isArray(content) ? content.map(p => p.text || '').join(' ') : '');

        // Separate the system prompt, conversation history, and final user message
        // so we can use the LanguageModel API's native parameters instead of
        // manually concatenating them as plain text (which forces the model to
        // re-parse the history on every turn and produces the SYSTEM:/A: ugliness).
        let systemPrompt = '';
        const initialPrompts = [];
        let userPrompt = '';

        messages.forEach((message) => {
          if (!message || typeof message.content === 'undefined') {
            return;
          }
          const role = message.role || 'user';
          const text = getTextContent(message.content);
          if (!text) return;

          if (role === 'system') {
            systemPrompt = text;
          } else if (role === 'assistant') {
            initialPrompts.push({ role: 'assistant', content: text });
          } else {
            // Each user message either adds to initialPrompts or becomes the
            // final prompt — the last user message is the one to respond to.
            if (userPrompt) {
              initialPrompts.push({ role: 'user', content: userPrompt });
            }
            userPrompt = text;
          }
        });

        if (!userPrompt) {
          throw new Error('No user prompt found in browser request');
        }

        const ai = getAiApi();
        let session;
        const createOptions = {};
        if (systemPrompt) createOptions.systemPrompt = systemPrompt;
        if (initialPrompts.length) createOptions.initialPrompts = initialPrompts;

        if (ai && ai.languageModel) {
          session = await ai.languageModel.create(createOptions);
        } else if (typeof LanguageModel !== 'undefined') {
          session = await LanguageModel.create(createOptions);
        } else {
          throw new Error('LanguageModel API not found');
        }

        try {
          // Use a JS-side timeout shorter than the PHP server timeout so the
          // browser can submit its error before PHP gives up waiting.
          // Reserve: pollingInterval (time until JS picks up the request) +
          // 2s (PHP check interval) + 8s network/processing buffer = 13s total.
          const jsTimeoutMs = Math.max(10000, ((config.timeout || 60) - (config.pollingInterval || 5) - 13) * 1000);
          return await withTimeout(session.prompt(userPrompt), jsTimeoutMs);
        }
        finally {
          if (session && typeof session.destroy === 'function') {
            session.destroy();
          }
        }
      };

      const runTranslate = async (request) => {
        const availability = await getAvailability('translator');
        if (availability === 'no') {
          throw new Error('Translator API not available in this browser');
        }

        if (availability === 'after-download' && !confirm(config.downloadMessages.translate)) {
          throw new Error('Download cancelled by user');
        }

        const ai = getAiApi();
        if (!ai || !ai.translator) {
          throw new Error('Translator API not found');
        }

        // Extract source and target language if possible.
        // The request might have it in various places depending on how it was called.
        const sourceLanguage = request.source_language || 'en';
        const targetLanguage = request.target_language || (document.documentElement.lang || 'en').split('-')[0];
        
        const translator = await ai.translator.create({
          sourceLanguage: sourceLanguage,
          targetLanguage: targetLanguage,
        });

        try {
          const text = typeof request.input === 'string' ? request.input : 
                       (request.messages && request.messages[0] ? request.messages[0].content : '');
          if (!text) throw new Error('No text to translate');
          
          const jsTimeoutMs = Math.max(10000, ((config.timeout || 60) - (config.pollingInterval || 5) - 13) * 1000);
          return await withTimeout(translator.translate(text), jsTimeoutMs);
        } finally {
          if (translator && typeof translator.destroy === 'function') {
            translator.destroy();
          }
        }
      };

      const runSummarize = async (request) => {
        const availability = await getAvailability('summarizer');
        if (availability === 'no') {
          throw new Error('Summarizer API not available in this browser');
        }

        if (availability === 'after-download' && !confirm(config.downloadMessages.summarize)) {
          throw new Error('Download cancelled by user');
        }

        const ai = getAiApi();
        if (!ai || !ai.summarizer) {
          throw new Error('Summarizer API not found');
        }

        const summarizer = await ai.summarizer.create();

        try {
          const text = typeof request.input === 'string' ? request.input : 
                       (request.messages && request.messages[0] ? request.messages[0].content : '');
          if (!text) throw new Error('No text to summarize');

          const jsTimeoutMs = Math.max(10000, ((config.timeout || 60) - (config.pollingInterval || 5) - 13) * 1000);
          return await withTimeout(summarizer.summarize(text), jsTimeoutMs);
        } finally {
          if (summarizer && typeof summarizer.destroy === 'function') {
            summarizer.destroy();
          }
        }
      };

      const submitResult = async (uuid, result, error = null) => {
        await fetch(config.submitUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            uuid: uuid,
            result: result,
            error: error,
            status: error ? 'failed' : 'completed'
          }),
        });
      };

      poll();
      setInterval(poll, config.pollingInterval * 1000);
    }
  };
})(jQuery);
