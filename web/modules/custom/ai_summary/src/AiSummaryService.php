<?php

namespace Drupal\ai_summary;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI Summary - summarizes all reports with optimistic AI enablement message.
 */
class AiSummaryService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'summary';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_summary';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    // Require all major webforms to ensure comprehensive summary.
    return [
      'current_ai_usage',
      'task_analysis',
      'ethics',
      'future_vision',
    ];
  }

  /**
   * Override to collect optional confidence and training_preferences data.
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

    // Optional: confidence for emotional context.
    $confidence_result = $this->getSubmissionData('confidence', $uid);
    if ($confidence_result) {
      $submission_data['confidence'] = $confidence_result;
      $submission_ids[] = $confidence_result['sid'];
    }

    // Optional: training_preferences for learning context.
    $training_prefs = $this->getSubmissionData('training_preferences', $uid);
    if ($training_prefs) {
      $submission_data['training_preferences'] = $training_prefs;
      $submission_ids[] = $training_prefs['sid'];
    }

    // Check if we should regenerate based on source data hash.
    if (!$force_regenerate && $latest_entity = $this->loadLatestReport($uid)) {
      $current_hash = $this->getSourceDataHash($submission_data);
      if ($latest_entity->getSourceDataHash() === $current_hash) {
        // Source data hasn't changed, return existing report.
        $report_data = $latest_entity->getReportData();
        $report_data['generated_at'] = $latest_entity->getGeneratedAt();
        $report_data['uid'] = $uid;
        $report_data['generation_time'] = $latest_entity->getGenerationTime();
        $report_data['version'] = $latest_entity->getVersion();

        $this->setCachedReport($uid, $report_data);
        return $report_data;
      }
    }

    // Generate the report.
    return $this->generateReportFromData($uid, $submission_data, $submission_ids, $retry);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract data from all modules for comprehensive summary.
    $ai_usage_data = $submission_data['current_ai_usage']['data'] ?? [];
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $ethics_data = $submission_data['ethics']['data'] ?? [];
    $future_data = $submission_data['future_vision']['data'] ?? [];

    // Current AI Usage.
    $ai_frequency = $ai_usage_data['m1_q2_frequency'] ?? 'not specified';
    $ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? '3';
    $ai_tools = $ai_usage_data['m1_q1_tools_used'] ?? [];
    if (is_array($ai_tools)) {
      $ai_tools = implode(', ', $ai_tools);
    }

    // Task Analysis.
    $role_category = $task_data['m2_q1_role_category'] ?? 'not specified';
    $tasks_described = $task_data['m2_q2_describe_tasks'] ?? '';

    // Ethics.
    $ethical_importance = $ethics_data['m11_q1_importance'] ?? '3';
    $ethics_concerns = $ethics_data['m11_q2_ethics_concerns'] ?? [];
    if (is_array($ethics_concerns)) {
      $ethics_concerns = implode(', ', $ethics_concerns);
    }

    // Future Vision.
    $career_5_years = $future_data['m14_q1_career_5_years'] ?? 'not_sure';
    $valuable_skills = $future_data['m14_q2_valuable_skills'] ?? [];
    if (is_array($valuable_skills)) {
      $valuable_skills = implode(', ', $valuable_skills);
    }
    $threat_opportunity_scale = $future_data['m14_q5_threat_opportunity'] ?? '5';

    // Optional: Extract confidence and learning preferences.
    $confidence_data = $submission_data['confidence']['data'] ?? [];
    $emotional_context = $this->buildEmotionalContext($confidence_data);
    $emotional_section = $emotional_context['section'];
    $tone_rules = $emotional_context['tone_rules'];

    $training_prefs_data = $submission_data['training_preferences']['data'] ?? [];
    $learning_prefs_context = $this->buildLearningPreferencesContext($training_prefs_data);
    $learning_prefs_section = $learning_prefs_context['section'];

    return <<<PROMPT
Generate a comprehensive summary report that synthesizes the user's journey through all AI analysis reports.

PROFILE SUMMARY:
Role: {$role_category}
Tasks: {$tasks_described}
Current AI Usage: {$ai_frequency}, Tools: {$ai_tools}, Comfort: {$ai_comfort}/5
Ethics importance: {$ethical_importance}/5, Concerns: {$ethics_concerns}
Career outlook: {$career_5_years}, Skills valued: {$valuable_skills}
AI view (1=threat, 9=opportunity): {$threat_opportunity_scale}/9

{$emotional_section}

{$learning_prefs_section}

{$tone_rules}

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

This report should:
1. Summarize key insights from all previous reports (role impact, skills, tasks, career transitions, industry insights, learning, concerns, strategies)
2. End with an optimistic, empowering message about becoming more enabled with AI
3. Emphasize the journey from uncertainty to capability
4. Be concise but comprehensive

Structure (keep responses CONCISE - max 2-3 sentences per text field, 3-4 items per array):

1. journey_recap:
   - where_you_started (2 sentences about initial position with AI)
   - key_discoveries: [4 items - brief insights from different reports]
   - transformation_achieved (2 sentences about growth/change)

2. comprehensive_summary:
   - role_evolution_summary (2 sentences)
   - skills_development_summary (2 sentences)
   - automation_opportunities_summary (2 sentences)
   - career_possibilities_summary (2 sentences)

3. path_forward:
   - immediate_next_steps: [3 items - concrete actions to take now]
   - medium_term_goals: [3 items - goals for next 3-6 months]
   - long_term_vision (2 sentences about future potential)

4. ai_enablement_message:
   - empowerment_statement (3-4 sentences - optimistic, inspiring message about how AI will enhance their capabilities, not replace them)
   - unique_advantages: [3 items - their specific strengths with AI]
   - closing_thought (1-2 sentences - powerful, hopeful conclusion)

ENHANCED PERSONALIZATION RULES:
- journey_recap.where_you_started should reference their initial emotional state if available
- ai_enablement_message tone should be matched to current emotional state
- path_forward should align with learning preferences and barriers if provided
- If describes "overwhelmed": emphasize transformation from overwhelmed to capable
- If describes "energized": celebrate their momentum and accelerate the vision

TONE: Optimistic, empowering, forward-looking. Emphasize AI as a tool for enhancement and growth.
RULES: Be specific and personal. Reference their actual data. Make them feel capable and excited. Return ONLY JSON.
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'journey_recap',
      'comprehensive_summary',
      'path_forward',
      'ai_enablement_message',
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
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_summary.service';
  }

}
