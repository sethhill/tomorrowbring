<?php

namespace Drupal\ai_concerns_navigator;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI Concerns Navigator - addresses user concerns about AI.
 */
class AiConcernsNavigatorService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'concerns_navigator';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_concerns_navigator';
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
    $ageism_concern = $future_data['m14_q3_ageism_concern'] ?? '3';
    $training_likelihood = $future_data['m14_q4_training_credentials'] ?? 'not_sure';
    $threat_opportunity_scale = $future_data['m14_q5_threat_opportunity'] ?? '5';

    // Additional context if available.
    $role_category = $task_data['m2_q1_role_category'] ?? 'not specified';
    $ai_frequency = $ai_usage_data['m1_q2_frequency'] ?? 'not specified';
    $ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? 'not specified';

    return <<<PROMPT
Generate a personalized AI concerns navigation report. This person needs specific, actionable guidance on addressing their concerns about AI and positioning themselves for the future.

PROFILE:
Role: {$role_category}
AI Usage: {$ai_frequency}, Comfort Level: {$ai_comfort}/5

ETHICAL CONCERNS:
Importance of ethics: {$ethical_importance}/5
Top concerns: {$ethics_concerns}
Trust levels (1-5):
- Hiring decisions: {$trust_hiring}
- Medical advice: {$trust_medical}
- Financial advice: {$trust_financial}
- Creative work: {$trust_creative}
- Legal decisions: {$trust_legal}

FUTURE OUTLOOK:
Career vision (5 years): {$career_5_years}
Valuable skills for future: {$valuable_skills}
Ageism concern level: {$ageism_concern}/5
AI training likelihood: {$training_likelihood}
AI as threat vs opportunity (1=threat, 9=opportunity): {$threat_opportunity_scale}/9

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

Generate a JSON report with these sections (keep responses CONCISE):

1. concern_assessment:
   - opportunity_mindset_score (0-100, based on threat_opportunity_scale and other signals)

2. ethical_positioning:
   - your_values_summary (2 sentences reflecting their ethical priorities)
   - concern_responses: [3-4 items for their top concerns: {concern, why_valid, practical_response, empowerment_angle}]
   - trust_building_strategies: {
       general_approach (1 sentence),
       specific_actions: [3-4 items: {action, benefit, how_to}]
     }
   - ethical_frameworks: [2-3 items: {framework, relevance_to_you, application}]

3. ai_insights:
   - personalized_narrative (1 paragraph, 3-4 sentences - empowering and specific to their data)
   - hidden_strengths: [3-4 items: {strength, evidence_from_data, how_to_amplify}]

CRITICAL RULES:
- Be SPECIFIC to their data - avoid generic advice
- Address their actual concerns directly, don't dismiss them
- Show how concerns can become competitive advantages
- If they're pessimistic (threat_opportunity < 5), focus on empowerment angles
- If they're optimistic (threat_opportunity >= 7), focus on accelerating their momentum
- If they have high ethical concerns, emphasize how those are valuable differentiators
- Base opportunity_mindset_score on multiple signals: threat_opportunity_scale, ai_comfort, career_5_years
- All strategies must be actionable, not theoretical
- Tone: empowering, practical, evidence-based, respectful of concerns
- Return ONLY JSON
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'concern_assessment',
      'ethical_positioning',
      'ai_insights',
    ];

    foreach ($required_fields as $field) {
      if (!isset($response[$field])) {
        $this->logger->error('AI response missing required field: @field', ['@field' => $field]);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Get human-readable label for a concern key.
   *
   * @param string $key
   *   The concern key (e.g., 'bias', 'privacy').
   *
   * @return string
   *   The human-readable label.
   */
  protected function getConcernLabel(string $key): string {
    $labels = [
      'bias' => 'Bias in AI decisions',
      'privacy' => 'Privacy / data security',
      'transparency' => 'Transparency (not knowing how AI makes decisions)',
      'accountability' => 'Accountability when AI makes mistakes',
      'displacement' => 'Job displacement',
      'environment' => 'Environmental impact of AI',
      'misinformation' => 'Misinformation / deepfakes',
      'over_reliance' => 'Over-reliance on AI vs. human judgment',
      'surveillance' => 'AI being used for surveillance',
      'ip' => 'Intellectual property / copyright issues',
    ];

    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
  }

  /**
   * {@inheritdoc}
   */
  protected function parseResponse($response_text): ?array {
    $report = parent::parseResponse($response_text);

    if ($report === NULL) {
      return NULL;
    }

    // Post-process concern_responses to add human-readable labels.
    if (isset($report['ethical_positioning']['concern_responses'])) {
      foreach ($report['ethical_positioning']['concern_responses'] as &$response) {
        if (isset($response['concern'])) {
          // Check if the concern looks like a key (lowercase, underscores).
          $concern = $response['concern'];
          if (preg_match('/^[a-z_]+$/', $concern)) {
            // Replace with human-readable label.
            $response['concern'] = $this->getConcernLabel($concern);
          }
        }
      }
    }

    return $report;
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

      $this->logger->info('Generated AI Concerns Navigator report for user @uid in @duration seconds (version @version)', [
        '@uid' => $uid,
        '@duration' => $duration,
        '@version' => $entity->getVersion(),
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate AI Concerns Navigator report for user @uid: @error', [
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
    return 'ai_concerns_navigator.service';
  }

}
