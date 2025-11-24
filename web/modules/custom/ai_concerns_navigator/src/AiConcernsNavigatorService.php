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

Generate a JSON report with these sections:

1. concern_assessment:
   - primary_concerns: [3-4 items with {concern, severity, impact_on_action}]
   - underlying_themes: [2-3 strings identifying patterns in their concerns]
   - opportunity_mindset_score (0-100, based on threat_opportunity_scale and other signals)
   - readiness_indicators: {positive: [2-3 strings], barriers: [2-3 strings]}

2. ethical_positioning:
   - your_values_summary (2 sentences reflecting their ethical priorities)
   - concern_responses: [for each of their top concerns, {concern, why_valid, practical_response, empowerment_angle}]
   - trust_building_strategies: {
       general_approach (1 sentence),
       specific_actions: [3-4 actionable items with {action, benefit, how_to}]
     }
   - ethical_frameworks: [2-3 items with {framework, relevance_to_you, application}]

3. concern_to_advantage:
   - reframe_narrative (1 paragraph showing how their concerns are actually strengths)
   - competitive_advantages: [3-4 items with {advantage, why, how_to_leverage}]
   - positioning_statement (2 sentences - how to describe themselves in job market/professional context given their values)

4. future_positioning:
   - career_trajectory: {
       current_position (1 sentence),
       five_year_vision (based on their input, make it concrete),
       evolution_path: [3-4 milestones with {milestone, timeframe, actions}]
     }
   - skill_priorities: {
       immediate: [3-4 skills with {skill, why_critical, acquisition_path}],
       medium_term: [3-4 skills with {skill, why_valuable, acquisition_path}]
     }
   - ageism_response: {
       acknowledgment (1 sentence validating concern if present),
       countermeasures: [3-4 specific strategies],
       narrative_shift (how to position experience as advantage)
     }

5. overcoming_barriers:
   - barrier_analysis: [for each concern/barrier, {barrier, root_cause, resolution_strategy, first_step}]
   - mindset_shifts: [3-4 items with {from, to, practice}]
   - support_systems: [3-4 resources/communities/approaches with {what, how_helps, how_to_access}]

6. action_roadmap:
   - immediate_wins: {
       title: "This Week",
       actions: [3-4 specific, achievable actions that address concerns]
     }
   - thirty_day_plan: {
       title: "Building Momentum",
       actions: [3-4 actions that deepen skills and confidence]
     }
   - ninety_day_milestones: {
       title: "Measurable Progress",
       goals: [3-4 measurable outcomes with {goal, success_metric}]
     }
   - six_month_transformation: {
       title: "Positioned for Success",
       outcomes: [3-4 transformation indicators]
     }

7. personalized_strategies:
   - training_approach: {
       recommendation (based on their training_likelihood),
       specific_programs: [3-4 items with {program, why_suitable, how_addresses_concerns}],
       alternative_paths: [2-3 options if formal training isn't appealing]
     }
   - confidence_building: [3-4 exercises/practices with {practice, frequency, expected_impact}]
   - networking_guidance: {
       communities: [3-4 specific communities/groups],
       conversation_starters: [3-4 ways to engage with AI topics that align with their values],
       mentor_profile: "description of ideal mentor/peer group"
     }

8. ai_insights:
   - personalized_narrative (1 paragraph, 3-4 sentences - empowering and specific to their data)
   - hidden_strengths: [3-4 items with {strength, evidence_from_data, how_to_amplify}]
   - optimism_builders: [3-4 concrete reasons for optimism based on their specific profile]
   - watch_outs: [2-3 items with {risk, early_warning_sign, mitigation}]
   - success_indicators: [3-4 signs they'll know they're on the right track]

CRITICAL RULES:
- Be SPECIFIC to their data - avoid generic advice
- Address their actual concerns directly, don't dismiss them
- Show how concerns can become competitive advantages
- If they're pessimistic (threat_opportunity < 5), focus heavily on concern_to_advantage and optimism_builders
- If they're optimistic (threat_opportunity >= 7), focus on accelerating their momentum
- If they have high ethical concerns, emphasize how those are valuable differentiators
- Base opportunity_mindset_score on multiple signals: threat_opportunity_scale, ai_comfort, career_5_years
- All strategies must be actionable, not theoretical
- Tone: empowering, practical, evidence-based, respectful of concerns
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'concern_assessment',
      'ethical_positioning',
      'concern_to_advantage',
      'future_positioning',
      'overcoming_barriers',
      'action_roadmap',
      'personalized_strategies',
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
