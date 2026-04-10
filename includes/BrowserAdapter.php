<?php

/**
 * @file
 * Browser adapter for OpenAI integration.
 *
 * This adapter queues requests in the user session so that they can be
 * picked up and processed by the browser's native AI APIs.
 */

class BrowserAdapter implements AIClientInterface {

  /** @var OpenAIApi|null */
  protected $api;

  /**
   * Constructor.
   */
  public function __construct($apiKey, $api = NULL) {
    $this->api = $api;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    $provider_config = $this->getProviderConfig();
    if (function_exists('openai_browser_is_enabled') && !openai_browser_is_enabled($provider_config)) {
      return [];
    }

    return [
      'chrome' => t('Google Chrome (window.ai)'),
    ];
  }

  /**
   * Get normalized browser provider config when the module helper is available.
   *
   * @return array
   *   Browser provider configuration.
   */
  protected function getProviderConfig(): array {
    if (function_exists('openai_browser_get_provider_config')) {
      return openai_browser_get_provider_config();
    }

    return [];
  }

  /**
   * Get models by capability.
   *
   * The browser provider only exposes chat/text generation through the OpenAI
   * provider architecture. Translation and summarization are browser-native
   * features too, but they are not modeled as OpenAI image/embedding/
   * moderation-style capabilities.
   *
   * @param string $capability
   *   Capability name.
   *
   * @return array
   *   Array of model IDs => labels.
   */
  public function getModelsByCapability($capability): array {
    $provider_config = $this->getProviderConfig();

    if (function_exists('openai_browser_is_enabled') && !openai_browser_is_enabled($provider_config)) {
      return [];
    }

    $capability = strtolower((string) $capability);

    switch ($capability) {
      case 'text':
      case 'chat':
      case 'vision':
        if (function_exists('openai_browser_user_can_use') && openai_browser_user_can_use('chat', $provider_config)) {
          return $this->getModels();
        }
        return [];

      case 'translate':
      case 'translation':
        if (function_exists('openai_browser_user_can_use') && openai_browser_user_can_use('translate', $provider_config)) {
          return $this->getModels();
        }
        return [];

      case 'summarize':
      case 'summary':
        if (function_exists('openai_browser_user_can_use') && openai_browser_user_can_use('summarize', $provider_config)) {
          return $this->getModels();
        }
        return [];
    }

    return [];
  }

  /**
   * Get chat-capable models.
   *
   * @return array
   *   Array of model IDs => labels.
   */
  public function getChatModels(): array {
    return $this->getModelsByCapability('text');
  }

  /**
   * Get image generation models.
   *
   * @return array
   *   Browser provider does not support image generation.
   */
  public function getImageModels(): array {
    return [];
  }

  /**
   * Get vision models.
   *
   * @return array
   *   Browser provider does not support vision inputs in the OpenAI adapter.
   */
  public function getVisionModels(): array {
    return $this->getModelsByCapability('vision');
  }

  /**
   * Get embedding models.
   *
   * @return array
   *   Browser provider does not support embeddings.
   */
  public function getEmbeddingModels(): array {
    return [];
  }

  /**
   * Get moderation models.
   *
   * @return array
   *   Browser provider does not support moderation.
   */
  public function getModerationModels(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    // For completions, we convert it to a single chat message.
    return $this->chat($model, [['role' => 'user', 'content' => $prompt]], $temperature, $max_tokens, $stream_response);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE) {
    if ($stream_response) {
      throw new \Exception('Streaming is not yet supported in Browser provider for Backdrop.');
    }

    $provider_config = $this->getProviderConfig();
    if (function_exists('openai_browser_user_can_use') && !openai_browser_user_can_use('chat', $provider_config)) {
      throw new \Exception('Browser AI chat is not enabled or you do not have permission.');
    }
    if (function_exists('openai_browser_ensure_session')) {
      openai_browser_ensure_session();
    }

    // Use Backdrop core's Uuid class.
    $uuid_service = new Uuid();
    $uuid = $uuid_service->generate();
    $request = [
      'uuid' => $uuid,
      'operation' => 'chat',
      'messages' => $messages,
      'model' => $model,
      'temperature' => $temperature,
      'max_tokens' => $max_tokens,
      'created' => REQUEST_TIME,
      'browser_seen' => 0,
    ];

    // Store request in session.
    if (!isset($_SESSION['openai_browser_requests'])) {
      $_SESSION['openai_browser_requests'] = [];
    }
    $_SESSION['openai_browser_requests'][$uuid] = $request;

    // Release session lock so poller can work.
    session_write_close();

    return $this->waitForResult($uuid, $provider_config);
  }

  /**
   * Translate text using the browser's native API.
   */
  public function translate(string $input, string $source_language = 'en', string $target_language = 'en') {
    $provider_config = $this->getProviderConfig();
    if (function_exists('openai_browser_user_can_use') && !openai_browser_user_can_use('translate', $provider_config)) {
      throw new \Exception('Browser AI translate is not enabled or you do not have permission.');
    }
    if (function_exists('openai_browser_ensure_session')) {
      openai_browser_ensure_session();
    }

    $uuid_service = new Uuid();
    $uuid = $uuid_service->generate();
    $request = [
      'uuid' => $uuid,
      'operation' => 'translate',
      'input' => $input,
      'source_language' => $source_language,
      'target_language' => $target_language,
      'created' => REQUEST_TIME,
      'browser_seen' => 0,
    ];

    if (!isset($_SESSION['openai_browser_requests'])) {
      $_SESSION['openai_browser_requests'] = [];
    }
    $_SESSION['openai_browser_requests'][$uuid] = $request;

    session_write_close();

    return $this->waitForResult($uuid, $provider_config);
  }

  /**
   * Summarize text using the browser's native API.
   */
  public function summarize(string $input) {
    $provider_config = $this->getProviderConfig();
    if (function_exists('openai_browser_user_can_use') && !openai_browser_user_can_use('summarize', $provider_config)) {
      throw new \Exception('Browser AI summarize is not enabled or you do not have permission.');
    }
    if (function_exists('openai_browser_ensure_session')) {
      openai_browser_ensure_session();
    }

    $uuid_service = new Uuid();
    $uuid = $uuid_service->generate();
    $request = [
      'uuid' => $uuid,
      'operation' => 'summarize',
      'input' => $input,
      'created' => REQUEST_TIME,
      'browser_seen' => 0,
    ];

    if (!isset($_SESSION['openai_browser_requests'])) {
      $_SESSION['openai_browser_requests'] = [];
    }
    $_SESSION['openai_browser_requests'][$uuid] = $request;

    session_write_close();

    return $this->waitForResult($uuid, $provider_config);
  }

  /**
   * Poll for a result from the browser client.
   */
  protected function waitForResult(string $uuid, array $provider_config) {
    // Poll for result.
    $start_time = time();
    $timeout = !empty($provider_config['browser_timeout']) ? (int) $provider_config['browser_timeout'] : 60;
    // Use a fixed 2-second PHP check interval regardless of the browser
    // polling interval. The browser_polling_interval setting controls how often
    // the JS client polls for new requests — it is NOT the right value here.
    // Using the same interval (e.g., 5s) means PHP's last cache check can land
    // at the same moment the JS submits its timeout error, causing PHP to miss
    // the result and throw a spurious "timed out" exception.
    $interval = 2;

    $seen = FALSE;
    $result_data = NULL;

    while (time() - $start_time < $timeout) {
      // Check cache first as it is more reliable for concurrent signaling.
      if (!$seen && ($cache_seen = cache_get('openai_browser_seen:' . $uuid, 'cache'))) {
        $seen = $cache_seen->data;
      }
      if ($cache_result = cache_get('openai_browser_result:' . $uuid, 'cache')) {
        $result_data = $cache_result->data;
      }

      // Fallback to session if cache failed or is not used.
      if (!$result_data) {
        // Re-open session to check for result.
        if (session_status() !== PHP_SESSION_ACTIVE) {
          if (function_exists('openai_browser_ensure_session')) {
            openai_browser_ensure_session();
          }
          else {
            backdrop_session_start();
          }
        }

        if (isset($_SESSION['openai_browser_results'][$uuid])) {
          $result_data = $_SESSION['openai_browser_results'][$uuid];
        }

        if (!$seen && !empty($_SESSION['openai_browser_requests'][$uuid]['browser_seen'])) {
          $seen = $_SESSION['openai_browser_requests'][$uuid]['browser_seen'];
        }

        // Close session again before sleeping to avoid blocking other requests.
        session_write_close();
      }

      if ($result_data) {
        // Clean up.
        cache_clear_all('openai_browser_seen:' . $uuid, 'cache');
        cache_clear_all('openai_browser_result:' . $uuid, 'cache');

        if (session_status() !== PHP_SESSION_ACTIVE) {
          if (function_exists('openai_browser_ensure_session')) {
            openai_browser_ensure_session();
          }
          else {
            backdrop_session_start();
          }
        }
        unset($_SESSION['openai_browser_results'][$uuid]);
        unset($_SESSION['openai_browser_requests'][$uuid]);
        session_write_close();

        if ($result_data['status'] === 'completed') {
          return $result_data['result'];
        }
        else {
          throw new \Exception('Browser AI error: ' . ($result_data['error'] ?? 'Unknown error'));
        }
      }

      sleep($interval);
    }

    // Clean up if timed out.
    cache_clear_all('openai_browser_seen:' . $uuid, 'cache');
    cache_clear_all('openai_browser_result:' . $uuid, 'cache');

    if (session_status() !== PHP_SESSION_ACTIVE) {
      if (function_exists('openai_browser_ensure_session')) {
        openai_browser_ensure_session();
      }
      else {
        backdrop_session_start();
      }
    }
    $timed_out_request = isset($_SESSION['openai_browser_requests'][$uuid]) ? $_SESSION['openai_browser_requests'][$uuid] : NULL;
    unset($_SESSION['openai_browser_requests'][$uuid]);
    session_write_close();

    if (!$seen && (empty($timed_out_request) || empty($timed_out_request['browser_seen']))) {
      throw new \Exception('Browser AI request timed out before any browser client picked it up. Ensure the page has the browser provider JavaScript loaded and that the same logged-in browser session can reach the polling endpoint.');
    }

    throw new \Exception('Browser AI request timed out after a browser client picked it up but before a result was submitted. Check the browser console for LanguageModel errors.');
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    throw new \Exception('Image generation is not supported by Browser provider.');
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    throw new \Exception('Text-to-speech is not supported by Browser provider.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    throw new \Exception('Speech-to-text is not supported by Browser provider.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    throw new \Exception('Moderation is not supported by Browser provider.');
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    throw new \Exception('Embeddings are not yet supported by Browser provider.');
  }
}
