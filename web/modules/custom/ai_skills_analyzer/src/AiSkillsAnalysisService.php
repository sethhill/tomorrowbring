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
  "motivational_insights": {
    "unique_advantages": [{"insight": "inspirational statement about adapting existing skills", "evidence": "supporting detail"}]
  }
}

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

  /**
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_skills_analyzer.analysis_service';
  }

}
