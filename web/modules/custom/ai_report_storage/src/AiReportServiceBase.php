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
    CacheBackendInterface $cache
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->clientManager = $client_manager;
    $this->aiProvider = $ai_provider;
    $this->logger = $logger_factory->get($this->getModuleName());
    $this->cache = $cache;
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

    $entities = $this->reportStorage->loadByProperties([
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

    // Create the new report entity.
    $entity = $this->reportStorage->create([
      'type' => $this->getReportType(),
      'uid' => $uid,
      'version' => $version,
      'status' => 'published',
      'generated_at' => time(),
      'generation_time' => $duration,
      'model_used' => 'claude-sonnet-4-5-20250929',
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
    $entities = $this->reportStorage->loadByProperties([
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
   * Call the Anthropic API with retry logic.
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
  protected function callAnthropicApi(string $prompt, bool $retry = TRUE, int $timeout = 600): ?string {
    $max_attempts = $retry ? 2 : 1;
    $attempt = 0;

    while ($attempt < $max_attempts) {
      $attempt++;

      try {
        $this->logger->info('Calling Anthropic API (attempt @attempt of @max) with @timeout s timeout', [
          '@attempt' => $attempt,
          '@max' => $max_attempts,
          '@timeout' => $timeout,
        ]);

        // Set execution timeout to allow for long API calls.
        set_time_limit($timeout + 30);

        $provider = $this->aiProvider->createInstance('anthropic', ['timeout' => $timeout]);

        $system_prompt = 'You are an expert career analyst. Provide realistic, actionable career guidance based on AI displacement research. Always respond with valid JSON matching the exact structure requested. Be concise and specific - quality over quantity.';

        $messages = new ChatInput([
          new ChatMessage('user', $prompt),
        ]);
        $messages->setSystemPrompt($system_prompt);

        $response = $provider->chat(
          $messages,
          'claude-sonnet-4-5-20250929'
        );

        $this->logger->info('API call successful on attempt @attempt', ['@attempt' => $attempt]);
        return $response->getNormalized()->getText();
      }
      catch (\Exception $e) {
        $is_timeout = strpos($e->getMessage(), 'timeout') !== FALSE || strpos($e->getMessage(), 'timed out') !== FALSE;

        if ($is_timeout && $attempt < $max_attempts) {
          $this->logger->warning('API timeout on attempt @attempt, retrying...', ['@attempt' => $attempt]);
          sleep(2);
          continue;
        }

        $this->logger->error('Anthropic API call failed after @attempt attempts: @error', [
          '@attempt' => $attempt,
          '@error' => $e->getMessage(),
        ]);
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
        $this->setCachedReport($uid, $report_data);
        return $report_data;
      }
    }

    // Generate new report via AI.
    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($submission_data);
      $response = $this->callAnthropicApi($prompt, $retry);

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

}
