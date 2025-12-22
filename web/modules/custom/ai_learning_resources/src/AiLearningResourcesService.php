<?php

namespace Drupal\ai_learning_resources;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered learning resources recommendations.
 */
class AiLearningResourcesService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'learning_resources';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_learning_resources';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['task_analysis', 'skills_gap'];
  }

  /**
   * Override to collect optional training_preferences data.
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

    // Optional: training_preferences (CRITICAL for this report!).
    $training_prefs = $this->getSubmissionData('training_preferences', $uid);
    if ($training_prefs) {
      $submission_data['training_preferences'] = $training_prefs;
      $submission_ids[] = $training_prefs['sid'];
    }

    // Optional: confidence (for tone calibration).
    $confidence = $this->getSubmissionData('confidence', $uid);
    if ($confidence) {
      $submission_data['confidence'] = $confidence;
      $submission_ids[] = $confidence['sid'];
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
    // Extract task analysis data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $skills_data = $submission_data['skills_gap']['data'] ?? [];

    // Extract role information.
    $role_category = $task_data['m2_q1_role_category'] ?? 'unknown';
    $role_other = $task_data['m2_q1_other'] ?? '';

    // Extract skills data.
    $current_skills = $skills_data['m5_q2_current_skills'] ?? 'not specified';
    if (is_array($current_skills)) {
      $current_skills = implode(', ', $current_skills);
    }
    $desired_skills = $skills_data['m5_q3_want_to_develop'] ?? 'not specified';
    if (is_array($desired_skills)) {
      $desired_skills = implode(', ', $desired_skills);
    }
    $learning_barrier = $skills_data['m5_q4_barriers'] ?? 'not specified';
    if (is_array($learning_barrier)) {
      $learning_barrier = implode(', ', $learning_barrier);
    }
    $learning_style = $skills_data['m5_q5_learning_preference'] ?? 'not specified';
    if (is_array($learning_style)) {
      $learning_style = implode(', ', $learning_style);
    }

    // Optional: Extract detailed training preferences.
    $training_prefs_data = $submission_data['training_preferences']['data'] ?? [];
    $learning_prefs_context = $this->buildLearningPreferencesContext($training_prefs_data);
    $learning_prefs_section = $learning_prefs_context['section'];

    // Optional: Extract confidence data for tone calibration.
    $confidence_data = $submission_data['confidence']['data'] ?? [];
    $emotional_context = $this->buildEmotionalContext($confidence_data);
    $emotional_section = $emotional_context['section'];
    $tone_rules = $emotional_context['tone_rules'];

    return <<<PROMPT
Generate personalized learning resources for this professional based on their skills gap and learning preferences.

PROFILE:
Role: {$role_category}{$role_other}
Current Skills: {$current_skills}
Want to Learn: {$desired_skills}
Learning Barriers: {$learning_barrier}
Learning Style: {$learning_style}

{$learning_prefs_section}

{$emotional_section}

{$tone_rules}

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

Generate JSON report matching this EXACT structure:

1. personalized_learning_path:
   - learning_style_assessment (2-3 sentences about their learning style and approach)
   - immediate_actions: [3-4 items with {action, time_required, expected_outcome}]
   - thirty_day_sprint: {
       success_metrics: [3-4 strings of what they'll achieve],
       week_by_week: [
         {week: 1, activities: [3-4 strings], milestone: "what they'll accomplish"},
         {week: 2, activities: [3-4 strings], milestone: "what they'll accomplish"},
         {week: 3, activities: [3-4 strings], milestone: "what they'll accomplish"},
         {week: 4, activities: [3-4 strings], milestone: "what they'll accomplish"}
       ]
     }
   - ninety_day_mastery: {
       target_competencies: [4-5 strings of competencies they'll develop],
       project_based_learning: [3-4 items with {project, portfolio_value}]
     }

2. learning_resources:
   - recommended_platforms: [6 items with {platform, why_recommended, specific_courses: [3-4 course names]}]
   - practice_projects: [6 items with {project, skills_developed: [strings], time_estimate, difficulty}]
   - community_resources: [4 items with {resource, value, how_to_engage}]

3. closing_message:
   - title (2-5 words, relevant to learning/execution)
   - message (2-3 sentences connecting structured learning to capability growth. Be constructive, not motivational. Reference specific learning barriers or timelines from this report.)

ENHANCED PERSONALIZATION RULES:
- All resources should be real, current, and accessible
- If training preferences are provided, ONLY recommend platforms offering their top learning styles
- If barrier "too_busy" is mentioned, emphasize micro-learning sessions (<30 min)
- If motivator "certification" is mentioned, prioritize certified programs
- If anxiety > 3, adjust timelines to be more achievable and reassuring
- Tailor recommendations to their specific learning style and barriers
- Include mix of free and paid resources
- Focus on practical, actionable learning paths
- Be specific to their role and desired skills
- Return ONLY JSON

TONE RULES for closing_message:
- Be direct and practical
- Connect learning investment to capability returns
- Focus on execution over intention, practice over theory
- Avoid "you've got this", "invest in yourself", "sky's the limit"
- Use language like "closes through practice", "returns compounding capability", "separates those who adapt"
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'personalized_learning_path',
      'learning_resources',
      'closing_message',
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
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_learning_resources.analysis_service';
  }

}
