<?php

namespace Drupal\ai_breakthrough_strategies;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI Breakthrough Strategies - helps overcome barriers and shift mindsets.
 */
class AiBreakthroughStrategiesService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'breakthrough_strategies';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_breakthrough_strategies';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['ethics', 'future_vision'];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract ethics data.
    $ethics_data = $submission_data['ethics']['data'] ?? [];
    $future_data = $submission_data['future_vision']['data'] ?? [];

    // Optional: task analysis and current AI usage for additional context.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $ai_usage_data = $submission_data['current_ai_usage']['data'] ?? [];

    // Ethics questions.
    $ethical_importance = $ethics_data['m11_q1_importance'] ?? '3';
    $ethics_concerns = $ethics_data['m11_q2_ethics_concerns'] ?? [];
    if (is_array($ethics_concerns)) {
      $ethics_concerns = implode(', ', $ethics_concerns);
    }

    // Trust levels for various AI decisions.
    $trust_hiring = $ethics_data['m11_q3_trust_hiring'] ?? '3';
    $trust_medical = $ethics_data['m11_q3_trust_medical'] ?? '3';
    $trust_financial = $ethics_data['m11_q3_trust_financial'] ?? '3';
    $trust_creative = $ethics_data['m11_q3_trust_creative'] ?? '3';
    $trust_legal = $ethics_data['m11_q3_trust_legal'] ?? '3';

    // Future vision questions.
    $career_5_years = $future_data['m14_q1_career_5_years'] ?? 'not_sure';
    $valuable_skills = $future_data['m14_q2_valuable_skills'] ?? [];
    if (is_array($valuable_skills)) {
      $valuable_skills = implode(', ', $valuable_skills);
    }
    $threat_opportunity_scale = $future_data['m14_q5_threat_opportunity'] ?? '5';

    // Additional context if available.
    $role_category = $task_data['m2_q1_role_category'] ?? 'not specified';
    $ai_frequency = $ai_usage_data['m1_q2_frequency'] ?? 'not specified';
    $ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? 'not specified';

    return <<<PROMPT
Generate a personalized breakthrough strategies report for AI adoption.

PROFILE:
Role: {$role_category}
AI Usage: {$ai_frequency}, Comfort: {$ai_comfort}/5
Ethics importance: {$ethical_importance}/5, Concerns: {$ethics_concerns}
Trust (1-5): Hiring {$trust_hiring}, Medical {$trust_medical}, Financial {$trust_financial}, Creative {$trust_creative}, Legal {$trust_legal}
Career outlook: {$career_5_years}, Skills: {$valuable_skills}
AI view (1=threat, 9=opportunity): {$threat_opportunity_scale}/9

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

Structure (keep responses CONCISE - max 1-2 sentences per text field, 3 items per array):

1. fear_to_empowerment:
   - empowerment_narrative (2 sentences about moving from fear to empowerment)
   - fear_audit: [4 items: {fear, validity_check, reframe, action}]

2. overcoming_barriers:
   - mindset_shifts: [3 items: {from, to, practice, why_this_matters}]

3. confidence_building:
   - building_blocks: [4 items: {step, exercise, frequency, expected_outcome}]

4. breakthrough_insights:
   - transformation_narrative (2 sentences about their transformation potential)

RULES: Be specific and concise. Match advice to threat_opportunity score. Return ONLY JSON.
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'fear_to_empowerment',
      'overcoming_barriers',
      'confidence_building',
      'breakthrough_insights',
    ];

    $missing_fields = [];
    foreach ($required_fields as $field) {
      if (!isset($response[$field])) {
        $missing_fields[] = $field;
      }
    }

    if (!empty($missing_fields)) {
      $this->logger->error('AI response missing required fields: @fields', [
        '@fields' => implode(', ', $missing_fields),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Override to collect optional task_analysis and current_ai_usage data.
   */
  public function generateReport($uid = NULL, bool $force_regenerate = FALSE, bool $retry = TRUE) {
    // Check minimum data first.
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    if ($uid === NULL) {
      $uid = $this->currentUser->id();
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

    // Collect submission data (required + optional).
    $submission_data = [];
    $submission_ids = [];

    // Required webforms.
    foreach ($this->getRequiredWebforms() as $webform_id) {
      $result = $this->getSubmissionData($webform_id, $uid);
      if ($result) {
        $submission_data[$webform_id] = $result;
        $submission_ids[] = $result['sid'];
      }
    }

    // Optional: task_analysis for additional context.
    $task_result = $this->getSubmissionData('task_analysis', $uid);
    if ($task_result) {
      $submission_data['task_analysis'] = $task_result;
      $submission_ids[] = $task_result['sid'];
    }

    // Optional: current_ai_usage for additional context.
    $ai_usage_result = $this->getSubmissionData('current_ai_usage', $uid);
    if ($ai_usage_result) {
      $submission_data['current_ai_usage'] = $ai_usage_result;
      $submission_ids[] = $ai_usage_result['sid'];
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

      $this->logger->info('Generated AI Breakthrough Strategies report for user @uid in @duration seconds (version @version)', [
        '@uid' => $uid,
        '@duration' => $duration,
        '@version' => $entity->getVersion(),
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate AI Breakthrough Strategies report for user @uid: @error', [
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
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_breakthrough_strategies.service';
  }

  /**
   * Override callAnthropicApi to sanitize streaming response.
   */
  protected function callAnthropicApi(string $prompt, bool $retry = TRUE, int $timeout = 600): ?string {
    $response = parent::callAnthropicApi($prompt, $retry, $timeout);

    if ($response !== NULL) {
      // Use iconv to strip invalid characters - more reliable than regex
      $response = iconv('UTF-8', 'UTF-8//IGNORE', $response);

      // Remove control characters but keep newlines, tabs, carriage returns
      $response = preg_replace('/[^\PC\s]/u', '', $response);

      // Final aggressive cleanup - keep only safe characters
      $response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $response);
    }

    return $response;
  }

  /**
   * Override parseResponse to add debugging.
   */
  protected function parseResponse(string $response): ?array {
    // Log first and last 500 characters for debugging.
    $this->logger->info('Raw response preview - First 500 chars: @start', [
      '@start' => substr($response, 0, 500),
    ]);
    $this->logger->info('Raw response preview - Last 500 chars: @end', [
      '@end' => substr($response, -500),
    ]);

    // Extract JSON from markdown if present.
    $json_string = $response;

    // Try to extract from markdown code blocks - handle incomplete closing tags
    if (preg_match('/```json\s*(.*?)(\s*```|$)/s', $response, $matches)) {
      $json_string = $matches[1];
      $this->logger->info('Extracted JSON from markdown code block (matched with optional closing tag)');
    }
    elseif (preg_match('/```\s*(.*?)(\s*```|$)/s', $response, $matches)) {
      $json_string = $matches[1];
      $this->logger->info('Extracted content from generic code block (matched with optional closing tag)');
    }

    // Trim any remaining markdown artifacts
    $json_string = trim($json_string);
    $json_string = preg_replace('/^```json\s*/s', '', $json_string);
    $json_string = preg_replace('/\s*```$/s', '', $json_string);
    $json_string = trim($json_string);

    // Remove control characters that can break JSON parsing
    // This is more aggressive - removes all control chars except \n, \r, \t
    $json_string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $json_string);

    // Also try to fix any UTF-8 encoding issues
    if (!mb_check_encoding($json_string, 'UTF-8')) {
      $json_string = mb_convert_encoding($json_string, 'UTF-8', 'UTF-8');
    }

    // Decode JSON.
    $report = json_decode($json_string, TRUE);

    if ($report === NULL) {
      $json_error = json_last_error_msg();

      // Try one more aggressive cleanup for debugging
      $cleaned = preg_replace('/[[:cntrl:]]/', '', $json_string);
      $retry_decode = json_decode($cleaned, TRUE);

      if ($retry_decode !== NULL) {
        $this->logger->info('JSON decoded successfully after removing all control characters');
        return $this->validateResponse($retry_decode) ? $retry_decode : NULL;
      }

      $this->logger->error('JSON decode failed: @error. First 1000 chars of extracted string: @json', [
        '@error' => $json_error,
        '@json' => substr($json_string, 0, 1000),
      ]);
      return NULL;
    }

    // Validate.
    if (!$this->validateResponse($report)) {
      $this->logger->error('AI response failed validation');
      return NULL;
    }

    return $report;
  }

}
