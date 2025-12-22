<?php

namespace Drupal\ai_skills_analyzer;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered skills analysis.
 */
class AiSkillsAnalysisService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'skills';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_skills_analyzer';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['skills_gap', 'task_analysis'];
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

    // Optional: training_preferences for learning-aligned skill recommendations.
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
    // Extract key data points from the submission data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $skills_data = $submission_data['skills_gap']['data'] ?? [];

    $role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_skills = $skills_data['m5_q2_current_skills'] ?? [];
    $desired_skills = $skills_data['m5_q3_desired_skills'] ?? [];

    // Optional: Extract training preferences for learning recommendations.
    $training_prefs_data = $submission_data['training_preferences']['data'] ?? [];
    $learning_prefs_context = $this->buildLearningPreferencesContext($training_prefs_data);
    $learning_prefs_section = $learning_prefs_context['section'];

    return <<<PROMPT
Analyze this professional's skill development needs. Be concise.

Role: {$role}
Current Skills: {$current_skills}
Desired Skills: {$desired_skills}

{$learning_prefs_section}

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

Respond with JSON (keep it concise, limit arrays to 2-3 items):

{
  "skill_trajectory": {
    "current_strengths": "2-sentence summary of their strongest skills and why they matter",
    "critical_gaps": [{"skill": "name", "why_critical": "brief", "impact_without": "brief", "acquisition_difficulty": "easy|moderate|challenging"}],
    "emerging_opportunities": [{"skill": "name", "why_emerging": "brief", "time_to_relevance": "brief"}]
  },
  "skill_synergies": {
    "introduction": "1-2 affirming sentences about their skill combinations",
    "powerful_combinations": [{"skills": ["A", "B"], "why_powerful": "brief", "application": "brief"}],
    "ai_augmentation_opportunities": [{"current_skill": "skill", "ai_tool": "tool", "multiplier_effect": "brief"}]
  },
  "ai_advantage": {
    "how_ai_helps": "1-2 sentences on how AI can amplify their existing strengths",
    "ai_skills_needed": ["skill 1", "skill 2", "skill 3"]
  },
  "closing_message": {
    "title": "2-5 words, relevant to skill adaptation",
    "message": "2-3 sentences connecting their existing skills to AI-era value. Be constructive, not inspirational. Reference specific skill synergies or advantages from this report."
  }
}

ENHANCED PERSONALIZATION RULES:
- If learning preferences provided, tailor skill acquisition recommendations to preferred learning styles
- If barrier "too_busy": emphasize micro-learning opportunities and quick wins in critical_gaps
- Adjust acquisition_difficulty timing based on available learning time and format preferences

TONE RULES for closing_message:
- Be direct and specific
- Connect their existing skills to competitive positioning
- Focus on skill compound effects, not motivation
- Avoid "believe in yourself", "you're unique", "endless growth"
- Use language like "skills compound", "combinations position", "advantages multiply"

Return ONLY JSON.
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'skill_trajectory',
      'skill_synergies',
      'ai_advantage',
      'closing_message',
    ];

    foreach ($required_fields as $field) {
      if (!isset($response[$field])) {
        $this->logger->error('AI response missing required field: @field', [
          '@field' => $field,
        ]);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_skills_analyzer.analysis_service';
  }

}
