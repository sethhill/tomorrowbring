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
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract key data points from the submission data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $skills_data = $submission_data['skills_gap']['data'] ?? [];

    $role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_skills = $skills_data['m5_q2_current_skills'] ?? [];
    $desired_skills = $skills_data['m5_q3_desired_skills'] ?? [];

    return <<<PROMPT
Analyze this professional's skill development needs. Be concise.

Role: {$role}
Current Skills: {$current_skills}
Desired Skills: {$desired_skills}

Respond with JSON (keep it concise, limit arrays to 2-3 items):

{
  "skill_trajectory": {
    "current_strengths": [{"skill": "name", "why_valuable": "brief", "leverage_opportunities": "brief"}],
    "critical_gaps": [{"skill": "name", "why_critical": "brief", "impact_without": "brief", "acquisition_difficulty": "easy|moderate|challenging"}],
    "emerging_opportunities": [{"skill": "name", "why_emerging": "brief", "time_to_relevance": "brief"}]
  },
  "personalized_learning_path": {
    "learning_style_assessment": "1-2 sentences",
    "immediate_actions": [{"skill": "name", "action": "brief", "time_required": "brief", "expected_outcome": "brief"}],
    "thirty_day_sprint": {
      "focus_skill": "primary skill",
      "week_by_week": [{"week": 1, "activities": ["activity 1", "activity 2"], "milestone": "brief"}],
      "success_metrics": ["metric 1", "metric 2"]
    },
    "ninety_day_mastery": {
      "target_competencies": ["comp 1", "comp 2"],
      "project_based_learning": [{"project": "name", "skills_practiced": ["skill 1", "skill 2"], "portfolio_value": "brief"}]
    }
  },
  "learning_resources": {
    "recommended_platforms": [{"platform": "name", "why_recommended": "brief", "specific_courses": ["course 1", "course 2"]}],
    "practice_projects": [{"project": "idea", "skills_developed": ["skill 1"], "difficulty": "beginner|intermediate|advanced", "time_estimate": "brief"}],
    "community_resources": [{"resource": "name", "value": "brief", "how_to_engage": "brief"}]
  },
  "skill_synergies": {
    "powerful_combinations": [{"skills": ["A", "B"], "why_powerful": "brief", "application": "brief"}],
    "ai_augmentation_opportunities": [{"current_skill": "skill", "ai_tool": "tool", "multiplier_effect": "brief"}]
  },
  "barrier_strategies": {
    "identified_barriers": ["barrier 1"],
    "overcoming_strategies": [{"barrier": "specific", "strategies": ["strategy 1"], "mindset_shift": "brief"}]
  },
  "motivational_insights": {
    "unique_advantages": ["advantage 1"],
    "quick_wins": ["win 1"],
    "long_term_vision": "1-2 sentences"
  }
}
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'skill_trajectory',
      'personalized_learning_path',
      'learning_resources',
      'skill_synergies',
      'barrier_strategies',
      'motivational_insights',
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

}
