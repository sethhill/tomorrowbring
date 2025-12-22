<?php

namespace Drupal\ai_report_storage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Base service class for AI-generated reports with unified entity storage.
 */
abstract class AiReportServiceBase {

  protected $entityTypeManager;
  protected $currentUser;
  protected $clientManager;
  protected $aiProvider;
  protected $logger;
  protected $cache;
  protected $configFactory;
  protected $reportStorage;

  /**
   * Constructs an AiReportServiceBase object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    WebformClientManager $client_manager,
    AiProviderPluginManager $ai_provider,
    LoggerChannelFactoryInterface $logger_factory,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->clientManager = $client_manager;
    $this->aiProvider = $ai_provider;
    $this->logger = $logger_factory->get($this->getModuleName());
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->reportStorage = $entity_type_manager->getStorage('ai_report');
  }

  /**
   * Get the report type identifier.
   *
   * @return string
   *   The report type (e.g., 'role_impact', 'career_transitions').
   */
  abstract protected function getReportType(): string;

  /**
   * Get the module name for logging.
   *
   * @return string
   *   The module name.
   */
  abstract protected function getModuleName(): string;

  /**
   * Get the list of required webforms.
   *
   * @return array
   *   Array of webform IDs required for this report.
   */
  abstract protected function getRequiredWebforms(): array;

  /**
   * Build the AI prompt from submission data.
   *
   * @param array $submission_data
   *   Associative array of webform_id => data.
   *
   * @return string
   *   The prompt to send to the AI.
   */
  abstract protected function buildPrompt(array $submission_data): string;

  /**
   * Validate the AI response has required fields.
   *
   * @param array $response
   *   The parsed response array.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  abstract protected function validateResponse(array $response): bool;

  /**
   * Safely load report entities by properties with error handling.
   *
   * @param array $properties
   *   Properties to filter by.
   *
   * @return array
   *   Array of entities, or empty array on error.
   */
  protected function safeLoadByProperties(array $properties): array {
    try {
      // Use entity query instead of loadByProperties for better error handling.
      $query = $this->reportStorage->getQuery()
        ->accessCheck(FALSE);

      foreach ($properties as $field => $value) {
        $query->condition($field, $value);
      }

      $ids = $query->execute();

      if (empty($ids)) {
        return [];
      }

      return $this->reportStorage->loadMultiple($ids);
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading reports: @error. Properties: @props', [
        '@error' => $e->getMessage(),
        '@props' => json_encode($properties),
      ]);
      return [];
    }
  }

  /**
   * Determine if this report is complex based on configuration.
   *
   * @return bool
   *   TRUE if complex, FALSE if simple.
   */
  protected function isComplexReport(): bool {
    $config = $this->configFactory->get('ai_report_storage.settings');
    $rules = $config->get('report_complexity_rules') ?? [];

    $report_type = $this->getReportType();
    foreach ($rules as $rule) {
      if ($rule['report_type'] === $report_type) {
        return $rule['complexity'] === 'complex';
      }
    }

    // Default to simple if not found in rules.
    return FALSE;
  }

  /**
   * Get the configured provider and model for this report.
   *
   * @return array
   *   Array with 'operation_type' and 'provider_model' keys.
   */
  protected function getProviderAndModel(): array {
    $config = $this->configFactory->get('ai_report_storage.settings');
    $complexity = $this->isComplexReport() ? 'complex_reports' : 'simple_reports';

    $operation_type = $config->get("{$complexity}.operation_type") ?? 'chat';
    $provider_model = $config->get("{$complexity}.provider_model") ?? 'anthropic__claude-sonnet-4-5-20250929';

    return [
      'operation_type' => $operation_type,
      'provider_model' => $provider_model,
    ];
  }

  /**
   * Get webform submission data for a user.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return array|null
   *   The submission data, or NULL if not found.
   */
  public function getSubmissionData($webform_id, $uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $submission_storage = $this->entityTypeManager->getStorage('webform_submission');
    $query = $submission_storage->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->condition('completed', 0, '>')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $sids = $query->execute();

    if (empty($sids)) {
      return NULL;
    }

    $submission = $submission_storage->load(reset($sids));
    if ($submission) {
      return [
        'data' => $submission->getData(),
        'sid' => $submission->id(),
      ];
    }
    return NULL;
  }

  /**
   * Check if user has minimum required data to generate report.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return bool
   *   TRUE if has minimum data, FALSE otherwise.
   */
  public function hasMinimumData($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    foreach ($this->getRequiredWebforms() as $webform_id) {
      $data = $this->getSubmissionData($webform_id, $uid);
      if (empty($data)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Load the latest published report entity for a user.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return \Drupal\ai_report_storage\Entity\AiReportInterface|null
   *   The report entity, or NULL if not found.
   */
  protected function loadLatestReport($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $entities = $this->safeLoadByProperties([
      'uid' => $uid,
      'type' => $this->getReportType(),
      'status' => 'published',
    ]);

    if (empty($entities)) {
      return NULL;
    }

    // Sort by version descending to get latest.
    usort($entities, function ($a, $b) {
      return $b->getVersion() - $a->getVersion();
    });

    return reset($entities);
  }

  /**
   * Save a new report entity.
   *
   * @param int $uid
   *   The user ID.
   * @param array $data
   *   The report data.
   * @param float $duration
   *   Generation time in seconds.
   * @param array $submission_ids
   *   Array of webform submission IDs used.
   * @param string $source_hash
   *   MD5 hash of source data.
   *
   * @return \Drupal\ai_report_storage\Entity\AiReportInterface
   *   The created report entity.
   */
  protected function saveReport($uid, array $data, float $duration, array $submission_ids = [], string $source_hash = '') {
    // Get the next version number.
    $latest = $this->loadLatestReport($uid);
    $version = $latest ? $latest->getVersion() + 1 : 1;

    // Get model used from configuration.
    $provider_config = $this->getProviderAndModel();
    $model_used = str_replace('__', ' / ', $provider_config['provider_model']);

    // Create the new report entity.
    $entity = $this->reportStorage->create([
      'type' => $this->getReportType(),
      'uid' => $uid,
      'version' => $version,
      'status' => 'published',
      'generated_at' => time(),
      'generation_time' => $duration,
      'model_used' => $model_used,
      'source_data_hash' => $source_hash,
    ]);

    // Use setters for complex data types.
    $entity->setReportData($data);
    $entity->setSourceSubmissions($submission_ids);

    $entity->save();

    $this->logger->info('Saved report version @version for user @uid', [
      '@version' => $version,
      '@uid' => $uid,
    ]);

    return $entity;
  }

  /**
   * Archive old report versions, keeping only the most recent N.
   *
   * @param int $uid
   *   The user ID.
   * @param int $keep_count
   *   Number of versions to keep (default: 5).
   */
  protected function archiveOldReports($uid, int $keep_count = 5): void {
    $entities = $this->safeLoadByProperties([
      'uid' => $uid,
      'type' => $this->getReportType(),
      'status' => 'published',
    ]);

    if (count($entities) <= $keep_count) {
      return;
    }

    // Sort by version descending.
    usort($entities, function ($a, $b) {
      return $b->getVersion() - $a->getVersion();
    });

    // Archive older versions.
    $to_archive = array_slice($entities, $keep_count);
    foreach ($to_archive as $entity) {
      $entity->setStatus('archived');
      $entity->save();
      $this->logger->info('Archived report version @version for user @uid', [
        '@version' => $entity->getVersion(),
        '@uid' => $uid,
      ]);
    }
  }

  /**
   * Call the AI API with retry logic and streaming support.
   *
   * @param string $prompt
   *   The prompt to send.
   * @param bool $retry
   *   Whether to retry on timeout.
   * @param int $timeout
   *   Timeout in seconds (default: 600).
   *
   * @return string|null
   *   The response text, or NULL on failure.
   */
  protected function callAiApi(string $prompt, bool $retry = TRUE, int $timeout = 600): ?string {
    $max_attempts = $retry ? 2 : 1;
    $attempt = 0;

    while ($attempt < $max_attempts) {
      $attempt++;

      // TROUBLESHOOTING: Log detailed timing information
      $timing = [
        'start' => microtime(TRUE),
        'checkpoints' => [],
      ];

      try {
        $this->logger->info('Calling AI API with streaming (attempt @attempt of @max) with @timeout s timeout', [
          '@attempt' => $attempt,
          '@max' => $max_attempts,
          '@timeout' => $timeout,
        ]);

        // TROUBLESHOOTING: Log using custom debug function if available
        if (function_exists('ai_debug_log')) {
          ai_debug_log('API Call Started (Streaming)', [
            'attempt' => $attempt,
            'max_attempts' => $max_attempts,
            'timeout' => $timeout,
            'prompt_length' => strlen($prompt),
            'timestamp' => date('Y-m-d H:i:s'),
          ]);
        }

        // Set execution timeout to allow for long API calls.
        set_time_limit($timeout + 30);
        $timing['checkpoints']['execution_timeout_set'] = microtime(TRUE) - $timing['start'];

        // Get provider and model configuration.
        $provider_config = $this->getProviderAndModel();
        $operation_type = $provider_config['operation_type'];
        $provider_model = $provider_config['provider_model'];

        // Load provider instance from Drupal AI.
        $provider = $this->aiProvider->loadProviderFromSimpleOption($provider_model);
        if (!$provider) {
          throw new \Exception('Failed to load AI provider from configuration');
        }

        $model_id = $this->aiProvider->getModelNameFromSimpleOption($provider_model);
        $timing['checkpoints']['provider_loaded'] = microtime(TRUE) - $timing['start'];

        // Get configuration settings.
        $config = $this->configFactory->get('ai_report_storage.settings');
        $system_prompt = $config->get('system_prompt') ?? 'You are an expert career analyst. Provide realistic, actionable career guidance based on AI displacement research. Always respond with valid JSON matching the exact structure requested. Be concise and specific - quality over quantity.';
        $max_tokens = $config->get('max_tokens') ?? 8096;

        // Get the configured timeout from ai_report_storage config or use default.
        $configured_timeout = $config->get('timeout') ?? 600;

        // Build ChatInput with messages array.
        $messages = [new ChatMessage('user', $prompt)];
        $chat_input = new ChatInput($messages);
        $chat_input->setSystemPrompt($system_prompt);

        // Configure provider with timeout and other settings.
        $provider->setConfiguration([
          'max_tokens' => $max_tokens,
          'temperature' => 0.7,
          'timeout' => $configured_timeout,
        ]);

        $timing['checkpoints']['provider_configured'] = microtime(TRUE) - $timing['start'];

        // TROUBLESHOOTING: Log before making the actual API call
        if (function_exists('ai_debug_log')) {
          ai_debug_log('Making Streaming API Request via Drupal AI', [
            'elapsed_ms' => round((microtime(TRUE) - $timing['start']) * 1000, 2),
            'model' => $model_id,
            'provider_model' => $provider_model,
            'timeout_configured' => $configured_timeout,
          ]);
        }

        // Make the API call with streaming through Drupal AI.
        // Pass timeout in the options array for the provider to use.
        $response = $provider->chat($chat_input, $model_id, [
          'report_type' => $this->getReportType(),
          'timeout' => $configured_timeout,
          'stream' => TRUE,
        ]);

        $timing['checkpoints']['api_called'] = microtime(TRUE) - $timing['start'];

        // Handle streaming response.
        $full_response = '';
        $chunk_count = 0;

        $normalized = $response->getNormalized();

        // Check if response is streamed (implements StreamedChatMessageIteratorInterface).
        if ($normalized instanceof \Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface) {
          // Iterate over streamed chunks.
          foreach ($normalized as $chunk) {
            $chunk_count++;

            // Log progress periodically.
            if ($chunk_count % 10 === 0 && function_exists('ai_debug_log')) {
              ai_debug_log('Stream Progress', [
                'chunks_received' => $chunk_count,
                'elapsed_seconds' => round(microtime(TRUE) - $timing['start'], 2),
                'response_length' => strlen($full_response),
              ]);
            }

            $full_response .= $chunk->getText();
          }

          $this->logger->info('Stream completed');
        }
        else {
          // Non-streamed response - normalized is a ChatMessage.
          $full_response = $normalized->getText();
          $this->logger->info('Non-streamed response received');
        }

        $timing['checkpoints']['stream_completed'] = microtime(TRUE) - $timing['start'];

        // TROUBLESHOOTING: Log successful response
        if (function_exists('ai_debug_log')) {
          ai_debug_log('API Call Completed', [
            'chunks_received' => $chunk_count,
            'elapsed_ms' => round($timing['checkpoints']['stream_completed'] * 1000, 2),
            'total_seconds' => round($timing['checkpoints']['stream_completed'], 2),
            'response_length' => strlen($full_response),
          ]);
        }

        $this->logger->info('AI API call successful on attempt @attempt (took @seconds seconds, @chunks chunks)', [
          '@attempt' => $attempt,
          '@seconds' => round($timing['checkpoints']['stream_completed'], 2),
          '@chunks' => $chunk_count,
        ]);

        // TROUBLESHOOTING: Log final timing breakdown
        if (function_exists('ai_debug_log')) {
          ai_debug_log('API Call Complete', [
            'total_seconds' => round($timing['checkpoints']['stream_completed'], 2),
            'response_length' => strlen($full_response),
            'timing_breakdown' => $timing['checkpoints'],
          ]);
        }

        return $full_response;
      }
      catch (\Exception $e) {
        $elapsed = microtime(TRUE) - $timing['start'];
        $is_timeout = strpos($e->getMessage(), 'timeout') !== FALSE || strpos($e->getMessage(), 'timed out') !== FALSE;

        // TROUBLESHOOTING: Log detailed error information
        if (function_exists('ai_debug_log')) {
          ai_debug_log('API Call Failed', [
            'attempt' => $attempt,
            'elapsed_seconds' => round($elapsed, 2),
            'is_timeout' => $is_timeout,
            'error_message' => $e->getMessage(),
            'error_type' => get_class($e),
            'error_code' => $e->getCode(),
            'timing_at_failure' => $timing['checkpoints'],
          ]);
        }

        $this->logger->error('API call exception (attempt @attempt, @elapsed s elapsed): @error | Type: @type | Code: @code', [
          '@attempt' => $attempt,
          '@elapsed' => round($elapsed, 2),
          '@error' => $e->getMessage(),
          '@type' => get_class($e),
          '@code' => $e->getCode(),
        ]);

        if ($is_timeout && $attempt < $max_attempts) {
          $this->logger->warning('API timeout on attempt @attempt, retrying...', ['@attempt' => $attempt]);

          if (function_exists('ai_debug_log')) {
            ai_debug_log('Retrying After Timeout', [
              'next_attempt' => $attempt + 1,
              'max_attempts' => $max_attempts,
            ]);
          }

          sleep(2);
          continue;
        }

        $this->logger->error('AI API call failed after @attempt attempts: @error', [
          '@attempt' => $attempt,
          '@error' => $e->getMessage(),
        ]);

        if (function_exists('ai_debug_log')) {
          ai_debug_log('API Call Giving Up', [
            'attempts_made' => $attempt,
            'final_error' => $e->getMessage(),
          ]);
        }

        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Parse AI response from JSON (with markdown code block handling).
   *
   * @param string $response
   *   The raw response text.
   *
   * @return array|null
   *   The parsed array, or NULL on failure.
   */
  protected function parseResponse(string $response): ?array {
    try {
      $json_string = $response;

      // Extract JSON from markdown code blocks if present.
      if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
        $json_string = $matches[1];
      }
      elseif (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
        $json_string = $matches[1];
      }

      $report = json_decode($json_string, TRUE);

      if ($report === NULL) {
        $this->logger->error('Failed to parse AI response as JSON');
        return NULL;
      }

      if (!$this->validateResponse($report)) {
        $this->logger->error('AI response failed validation');
        return NULL;
      }

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing AI response: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Generate source data hash from submissions.
   *
   * @param array $submission_data
   *   Array of submission data arrays.
   *
   * @return string
   *   MD5 hash of the combined data.
   */
  protected function getSourceDataHash(array $submission_data): string {
    $combined = '';
    foreach ($submission_data as $webform_id => $data) {
      $combined .= $webform_id . ':' . json_encode($data['data'] ?? []);
    }
    return md5($combined);
  }

  /**
   * Get cached report data.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array|null
   *   The cached report data, or NULL if not found.
   */
  protected function getCachedReport($uid): ?array {
    $cid = $this->getCacheId($uid);
    if ($cache = $this->cache->get($cid)) {
      $this->logger->info('Returning cached report for user @uid', ['@uid' => $uid]);
      return $cache->data;
    }
    return NULL;
  }

  /**
   * Set cached report data.
   *
   * @param int $uid
   *   The user ID.
   * @param array $data
   *   The report data to cache.
   */
  protected function setCachedReport($uid, array $data): void {
    $cid = $this->getCacheId($uid);
    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, [
      "user:{$uid}",
      'webform_submission_list',
      'ai_report_list',
      "ai_report_list:user:{$uid}",
      "{$this->getModuleName()}:{$uid}",
      "ai_report:{$this->getReportType()}:{$uid}",
    ]);
  }

  /**
   * Get the cache ID for a user's report.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return string
   *   The cache ID.
   */
  protected function getCacheId($uid): string {
    return "{$this->getModuleName()}:report:{$uid}";
  }

  /**
   * Clear cache for a user's report.
   *
   * @param int $uid
   *   The user ID.
   */
  public function clearCache(int $uid): void {
    $cid = $this->getCacheId($uid);
    $this->cache->delete($cid);
    $this->logger->info('Cleared report cache for user @uid', ['@uid' => $uid]);
  }

  /**
   * Generate a report for a user.
   *
   * Main workflow:
   * 1. Check for existing entity (unless forcing regenerate)
   * 2. Check cache (fast path)
   * 3. Validate minimum data
   * 4. Check if source data changed (via hash comparison)
   * 5. Generate via AI if needed
   * 6. Save as entity with versioning
   * 7. Archive old versions
   * 8. Update cache
   * 9. Return report data
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   * @param bool $force_regenerate
   *   Force regeneration even if cached/entity exists.
   * @param bool $retry
   *   Whether to retry API calls on timeout.
   *
   * @return array|null
   *   The report data array, or NULL/error array on failure.
   */
  public function generateReport($uid = NULL, bool $force_regenerate = FALSE, bool $retry = TRUE) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Check minimum data first.
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    // Check cache first (unless forcing regenerate).
    if (!$force_regenerate) {
      $cached = $this->getCachedReport($uid);
      if ($cached !== NULL) {
        return $cached;
      }

      // Check for existing entity.
      $latest_entity = $this->loadLatestReport($uid);
      if ($latest_entity) {
        $report_data = $latest_entity->getReportData();
        $report_data['generated_at'] = $latest_entity->getGeneratedAt();
        $report_data['uid'] = $uid;
        $report_data['generation_time'] = $latest_entity->getGenerationTime();
        $report_data['version'] = $latest_entity->getVersion();
        $report_data['model_used'] = $latest_entity->getModelUsed();

        // Populate cache from entity.
        $this->setCachedReport($uid, $report_data);
        return $report_data;
      }
    }

    // Collect submission data.
    $submission_data = [];
    $submission_ids = [];
    foreach ($this->getRequiredWebforms() as $webform_id) {
      $result = $this->getSubmissionData($webform_id, $uid);
      if ($result) {
        $submission_data[$webform_id] = $result;
        $submission_ids[] = $result['sid'];
      }
    }

    // Check if we should regenerate based on source data hash.
    if (!$force_regenerate && $latest_entity = $this->loadLatestReport($uid)) {
      $current_hash = $this->getSourceDataHash($submission_data);
      if ($latest_entity->getSourceDataHash() === $current_hash) {
        // Source data unchanged - return cached/entity version.
        $report_data = $latest_entity->getReportData();
        $report_data['generated_at'] = $latest_entity->getGeneratedAt();
        $report_data['uid'] = $uid;
        $report_data['generation_time'] = $latest_entity->getGenerationTime();
        $report_data['version'] = $latest_entity->getVersion();
        $report_data['model_used'] = $latest_entity->getModelUsed();
        $this->setCachedReport($uid, $report_data);
        return $report_data;
      }
    }

    // Generate new report via AI.
    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($submission_data);
      $response = $this->callAiApi($prompt, $retry);

      if ($response === NULL) {
        return [
          'error' => 'API_TIMEOUT',
          'message' => 'The AI service timed out. This analysis requires complex processing. Please try again in a moment.',
        ];
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        return [
          'error' => 'PARSE_ERROR',
          'message' => 'Unable to parse AI response. Please try regenerating the analysis.',
        ];
      }

      $duration = round(microtime(TRUE) - $start_time, 2);

      // Add metadata to report.
      $report['generated_at'] = time();
      $report['uid'] = $uid;
      $report['generation_time'] = $duration;

      // Save as entity with versioning.
      $source_hash = $this->getSourceDataHash($submission_data);
      $entity = $this->saveReport($uid, $report, $duration, $submission_ids, $source_hash);
      $report['version'] = $entity->getVersion();

      // Archive old versions (keep last 5).
      $this->archiveOldReports($uid, 5);

      // Update cache.
      $this->setCachedReport($uid, $report);

      $this->logger->info('Generated report for user @uid in @duration seconds (version @version)', [
        '@uid' => $uid,
        '@duration' => $duration,
        '@version' => $entity->getVersion(),
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate report for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return [
        'error' => 'EXCEPTION',
        'message' => 'An unexpected error occurred. Please try again later.',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Get existing report without generating a new one.
   *
   * Only returns cached or database reports. Never triggers generation.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return array|null
   *   The report data array, or NULL if no report exists.
   */
  public function getExistingReport($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Check minimum data first.
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    // Check cache first.
    $cached = $this->getCachedReport($uid);
    if ($cached !== NULL) {
      return $cached;
    }

    // Check for existing entity.
    $latest_entity = $this->loadLatestReport($uid);
    if ($latest_entity && $latest_entity->getStatus() === 'published') {
      // Mark report as viewed on first access.
      $this->markReportAsViewed($latest_entity);

      $report_data = $latest_entity->getReportData();
      $report_data['generated_at'] = $latest_entity->getGeneratedAt();
      $report_data['uid'] = $uid;
      $report_data['generation_time'] = $latest_entity->getGenerationTime();
      $report_data['version'] = $latest_entity->getVersion();
      $report_data['model_used'] = $latest_entity->getModelUsed();

      // Populate cache from entity.
      $this->setCachedReport($uid, $report_data);
      return $report_data;
    }

    return NULL;
  }

  /**
   * Queue a report for background generation.
   *
   * Creates a pending report entity and queues it for processing.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return array
   *   Array with 'status' => 'pending' and 'entity_id'.
   */
  public function queueReportGeneration($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Check minimum data first.
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    // Check if there's already a pending report for this user and type.
    $existing_pending = $this->safeLoadByProperties([
      'uid' => $uid,
      'type' => $this->getReportType(),
      'status' => 'pending',
    ]);

    if (!empty($existing_pending)) {
      // Return the existing pending report.
      $entity = reset($existing_pending);
      return [
        'status' => 'pending',
        'entity_id' => $entity->id(),
        'queued_at' => $entity->getGeneratedAt(),
      ];
    }

    // Collect submission data for source hash.
    $submission_data = [];
    $submission_ids = [];
    foreach ($this->getRequiredWebforms() as $webform_id) {
      $result = $this->getSubmissionData($webform_id, $uid);
      if ($result) {
        $submission_data[$webform_id] = $result;
        $submission_ids[] = $result['sid'];
      }
    }

    $source_hash = $this->getSourceDataHash($submission_data);

    // Create a pending report entity.
    $entity = $this->reportStorage->create([
      'uid' => $uid,
      'type' => $this->getReportType(),
      'status' => 'pending',
      'report_data' => json_encode([]),
      'version' => 1,
      'generated_at' => time(),
      'source_data_hash' => $source_hash,
      'source_submissions' => json_encode($submission_ids),
    ]);
    $entity->save();

    // Queue the generation task.
    $queue = \Drupal::queue('generate_ai_report');
    $queue->createItem([
      'service_id' => $this->getServiceId(),
      'uid' => $uid,
      'entity_id' => $entity->id(),
    ]);

    $this->logger->info('Queued @type report generation for user @uid (entity @id)', [
      '@type' => $this->getReportType(),
      '@uid' => $uid,
      '@id' => $entity->id(),
    ]);

    return [
      'status' => 'pending',
      'entity_id' => $entity->id(),
      'queued_at' => $entity->getGeneratedAt(),
    ];
  }

  /**
   * Generate report in background (called by queue worker).
   *
   * @param int $uid
   *   The user ID.
   * @param int $entity_id
   *   The pending report entity ID.
   *
   * @return array|null
   *   The report data array, or NULL/error array on failure.
   */
  public function generateReportInBackground($uid, $entity_id) {
    // Load the entity.
    $entity = $this->reportStorage->load($entity_id);
    if (!$entity) {
      $this->logger->error('Entity @id not found for background generation', ['@id' => $entity_id]);
      return NULL;
    }

    // Collect submission data.
    $submission_data = [];
    $submission_ids = [];
    foreach ($this->getRequiredWebforms() as $webform_id) {
      $result = $this->getSubmissionData($webform_id, $uid);
      if ($result) {
        $submission_data[$webform_id] = $result;
        $submission_ids[] = $result['sid'];
      }
    }

    // Generate new report via AI.
    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($submission_data);
      $response = $this->callAiApi($prompt, TRUE);

      if ($response === NULL) {
        $entity->setStatus('failed');
        $entity->save();
        return [
          'error' => 'API_TIMEOUT',
          'message' => 'The AI service timed out. This analysis requires complex processing. Please try again in a moment.',
        ];
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        $entity->setStatus('failed');
        $entity->save();
        return [
          'error' => 'PARSE_ERROR',
          'message' => 'Unable to parse AI response. Please try regenerating the analysis.',
        ];
      }

      $duration = round(microtime(TRUE) - $start_time, 2);

      // Calculate version number.
      $latest = $this->loadLatestReport($uid);
      $version = $latest ? $latest->getVersion() + 1 : 1;

      // Get model used from configuration.
      $provider_config = $this->getProviderAndModel();
      $model_used = str_replace('__', ' / ', $provider_config['provider_model']);

      // Update the entity with the generated report.
      $entity->setReportData($report);
      $entity->setStatus('published');
      $entity->setGenerationTime($duration);
      $entity->setGeneratedAt(time());
      $entity->setModelUsed($model_used);
      $source_hash = $this->getSourceDataHash($submission_data);
      $entity->setSourceDataHash($source_hash);
      $entity->setSourceSubmissions($submission_ids);
      $entity->setVersion($version);

      $entity->save();

      // Archive old versions (keep last 5).
      $this->archiveOldReports($uid, 5);

      // Add metadata to report for return value.
      $report['generated_at'] = $entity->getGeneratedAt();
      $report['uid'] = $uid;
      $report['generation_time'] = $duration;
      $report['version'] = $version;

      // Update cache.
      $this->setCachedReport($uid, $report);

      $this->logger->info('Generated report in background for user @uid in @duration seconds (version @version)', [
        '@uid' => $uid,
        '@duration' => $duration,
        '@version' => $version,
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $entity->setStatus('failed');
      $entity->save();

      $this->logger->error('Failed to generate report in background for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return [
        'error' => 'EXCEPTION',
        'message' => 'An unexpected error occurred. Please try again later.',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Check if there is a pending report for the user.
   *
   * This includes both 'pending' (queued) and 'processing' (actively generating) reports.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return \Drupal\ai_report_storage\Entity\AiReportInterface|null
   *   The pending or processing report entity, or NULL if none exists.
   */
  public function getPendingReport($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Check for processing reports first (higher priority).
    $processing_reports = $this->safeLoadByProperties([
      'uid' => $uid,
      'type' => $this->getReportType(),
      'status' => 'processing',
    ]);

    if (!empty($processing_reports)) {
      return reset($processing_reports);
    }

    // Then check for pending (queued) reports.
    $pending_reports = $this->safeLoadByProperties([
      'uid' => $uid,
      'type' => $this->getReportType(),
      'status' => 'pending',
    ]);

    return !empty($pending_reports) ? reset($pending_reports) : NULL;
  }

  /**
   * Get the service ID for dependency injection.
   *
   * This must match the service defined in the module's services.yml file.
   *
   * @return string
   *   The service ID.
   */
  abstract protected function getServiceId(): string;

  /**
   * Mark a report as viewed by the current user.
   *
   * Only sets the viewed_at timestamp if it hasn't been set already.
   *
   * @param \Drupal\ai_report_storage\Entity\AiReportInterface $report
   *   The report entity to mark as viewed.
   */
  protected function markReportAsViewed($report) {
    // Only mark as viewed if not already viewed.
    if (!$report->isViewed()) {
      $report->setViewedAt(time());
      try {
        $report->save();

        // Invalidate dashboard cache tags for this user.
        $uid = $report->getOwnerId();
        $tags = [
          'ai_report_list',
          "ai_report_list:user:{$uid}",
        ];
        \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);

        $this->logger->info('Report @type marked as viewed for user @uid, cache invalidated', [
          '@type' => $report->getType(),
          '@uid' => $uid,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to mark report as viewed: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Build emotional context and tone calibration rules from confidence data.
   *
   * This helper method extracts emotional state information from the confidence
   * webform and generates tone calibration rules for use in AI prompts.
   * Implements a moderate approach to tone calibration - adjusting pacing,
   * reassurance level, and step sizes based on user's emotional state.
   *
   * @param array $confidence_data
   *   Data from confidence webform submission.
   *
   * @return array
   *   Array with keys:
   *   - 'section': Prompt section with emotional state data
   *   - 'tone_rules': Tone calibration instructions
   *   - 'state': Parsed emotional state for logging
   */
  protected function buildEmotionalContext(array $confidence_data): array {
    if (empty($confidence_data)) {
      return ['section' => '', 'tone_rules' => '', 'state' => NULL];
    }

    $anxiety = $confidence_data['m7_q1_anxiety'] ?? NULL;
    $excitement = $confidence_data['m7_q1_excitement'] ?? NULL;
    $confidence = $confidence_data['m7_q1_confidence'] ?? NULL;
    $describes = $confidence_data['m7_q2_describes_you'] ?? NULL;
    $support_needed = $confidence_data['m7_q5_support_needed'] ?? [];
    $leadership_support = $confidence_data['m7_q3_leadership_support'] ?? NULL;
    $company_trust = $confidence_data['m7_q4_trust_company'] ?? NULL;

    // Build emotional state section.
    $section = "EMOTIONAL STATE:\n";
    $section .= "Anxiety: {$anxiety}/5, Excitement: {$excitement}/5, Confidence: {$confidence}/5\n";
    $section .= "Self-Description: {$describes}\n";
    $section .= "Leadership Support: {$leadership_support}/5, Company Trust: {$company_trust}/5\n";
    if (!empty($support_needed)) {
      $section .= "Support Needed: " . implode(', ', $support_needed) . "\n";
    }

    // Build tone calibration rules (moderate approach).
    $tone_rules = "TONE CALIBRATION (Moderate Approach):\n";

    if ($anxiety > 3 && $confidence < 3) {
      $tone_rules .= "- Use reassuring, step-by-step tone\n";
      $tone_rules .= "- Break recommendations into smaller, achievable steps\n";
      $tone_rules .= "- Emphasize progress over perfection\n";
    }

    if ($describes === 'overwhelmed' || $describes === 'resistant') {
      $tone_rules .= "- Validate their concerns without dismissing them\n";
      $tone_rules .= "- Focus on small wins and quick victories\n";
      $tone_rules .= "- Reduce pressure, increase support\n";
    }

    if ($describes === 'energized' || $excitement > 4) {
      $tone_rules .= "- Match their energy and enthusiasm\n";
      $tone_rules .= "- Offer accelerated timelines where appropriate\n";
      $tone_rules .= "- Include stretch goals alongside foundational ones\n";
    }

    if ($describes === 'cautious_optimistic') {
      $tone_rules .= "- Balance optimism with practical risk mitigation\n";
      $tone_rules .= "- Provide both 'safe bet' and 'stretch' options\n";
    }

    if (in_array('permission', $support_needed)) {
      $tone_rules .= "- Include strategies for gaining leadership buy-in\n";
      $tone_rules .= "- Provide conversation starters for manager discussions\n";
    }

    if (in_array('understand_why', $support_needed)) {
      $tone_rules .= "- Provide business case evidence and ROI reasoning\n";
      $tone_rules .= "- Explain the 'why' behind recommendations\n";
    }

    if (in_array('job_security', $support_needed)) {
      $tone_rules .= "- Emphasize how actions increase job security\n";
      $tone_rules .= "- Focus on becoming irreplaceable through adaptation\n";
    }

    if ($leadership_support < 3) {
      $tone_rules .= "- Include strategies for working without top-down support\n";
      $tone_rules .= "- Emphasize peer networks and grassroots approaches\n";
    }

    return [
      'section' => $section,
      'tone_rules' => $tone_rules,
      'state' => [
        'anxiety' => $anxiety,
        'excitement' => $excitement,
        'confidence' => $confidence,
        'describes' => $describes,
      ],
    ];
  }

  /**
   * Build learning preferences context from training_preferences data.
   *
   * This helper method extracts learning style preferences, format preferences,
   * motivators, and barriers from the training_preferences webform and formats
   * them into a prompt section with personalization rules.
   *
   * @param array $training_prefs_data
   *   Data from training_preferences webform submission.
   *
   * @return array
   *   Array with keys:
   *   - 'section': Prompt section with learning preferences
   *   - 'top_styles': Array of top 3 learning styles
   *   - 'barriers': Array of barriers
   *   - 'motivators': Array of motivators
   */
  protected function buildLearningPreferencesContext(array $training_prefs_data): array {
    if (empty($training_prefs_data)) {
      return ['section' => '', 'top_styles' => [], 'barriers' => [], 'motivators' => []];
    }

    // Parse Likert rankings to get top styles.
    $style_rankings = $training_prefs_data['m6_q1_learning_style_rank'] ?? [];
    $top_styles = $this->parseTopLearningStyles($style_rankings);

    $training_time = $training_prefs_data['m6_q2_training_time'] ?? NULL;
    $format_pref = $training_prefs_data['m6_q3_format'] ?? [];
    $practice_pref = $training_prefs_data['m6_q4_practice'] ?? NULL;
    $motivators = $training_prefs_data['m6_q5_motivation'] ?? [];
    $barriers = $training_prefs_data['m6_q6_barriers'] ?? [];

    $section = "LEARNING PREFERENCES:\n";
    if (!empty($top_styles)) {
      $section .= "Top Learning Styles: " . implode(', ', $top_styles) . "\n";
    }
    $section .= "Preferred Training Time: {$training_time}\n";
    if (!empty($format_pref)) {
      $section .= "Ideal Formats: " . implode(', ', $format_pref) . "\n";
    }
    $section .= "Practice Preference: {$practice_pref}\n";
    if (!empty($motivators)) {
      $section .= "Top Motivators: " . implode(', ', $motivators) . "\n";
    }
    if (!empty($barriers)) {
      $section .= "Learning Barriers: " . implode(', ', $barriers) . "\n";
    }

    $section .= "\nPERSONALIZATION RULES:\n";
    if (!empty($top_styles)) {
      $section .= "- Recommend platforms offering: " . implode(', ', $top_styles) . "\n";
    }
    if (in_array('too_busy', $barriers)) {
      $section .= "- Emphasize micro-learning sessions (<30 min)\n";
    }
    if (in_array('no_support', $barriers)) {
      $section .= "- Include self-directed, low-permission options\n";
    }
    if (in_array('certification', $motivators)) {
      $section .= "- Prioritize certified programs and credentials\n";
    }
    if (in_array('impact', $motivators)) {
      $section .= "- Show immediate, visible impact on daily work\n";
    }
    if ($practice_pref === 'real_work') {
      $section .= "- Recommend learning-by-doing approaches with real projects\n";
    }
    if ($practice_pref === 'sandbox') {
      $section .= "- Include safe practice environments without real-world consequences\n";
    }

    return [
      'section' => $section,
      'top_styles' => $top_styles,
      'barriers' => $barriers,
      'motivators' => $motivators,
    ];
  }

  /**
   * Parse Likert ranking data to extract top 3 learning styles.
   *
   * @param array $rankings
   *   Associative array of style => score from Likert field.
   *
   * @return array
   *   Array of top 3 learning style labels.
   */
  protected function parseTopLearningStyles(array $rankings): array {
    if (empty($rankings)) {
      return [];
    }

    $style_labels = [
      'video' => 'Video tutorials',
      'labs' => 'Hands-on labs',
      'live' => 'Live instructor-led sessions',
      'peer' => 'Peer learning circles',
      'documentation' => 'Written documentation',
      'coaching' => 'One-on-one coaching',
      'micro' => 'Microlearning',
      'trial' => 'Trial and error',
      'case' => 'Case studies',
      'certification' => 'Certification programs',
    ];

    // Likert scale: 1 = least preferred, 3 = highly preferred.
    $scored = [];
    foreach ($rankings as $style => $score) {
      if ($score >= 3) {
        $scored[$style] = $score;
      }
    }

    arsort($scored);
    $top_keys = array_slice(array_keys($scored), 0, 3);

    return array_map(function ($key) use ($style_labels) {
      return $style_labels[$key] ?? $key;
    }, $top_keys);
  }

}
