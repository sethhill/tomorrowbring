<?php

namespace Drupal\ai_career_transitions;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered career transition analysis.
 */
class AiCareerTransitionsService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'career_transitions';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_career_transitions';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['task_analysis', 'skills_gap'];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract task analysis data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $skills_data = $submission_data['skills_gap']['data'] ?? [];

    $current_role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_skills = $skills_data['m5_q2_current_skills'] ?? 'not specified';
    $desired_skills = $skills_data['m5_q3_desired_skills'] ?? 'not specified';

    return <<<PROMPT
Analyze career transition opportunities in the AI era. Be concise.

Current Role: {$current_role}
Current Skills: {$current_skills}
Desired Skills: {$desired_skills}

Respond with JSON (limit to 3-4 viable transitions):

{
  "transition_readiness": {
    "overall_score": "1-100",
    "summary": "1-2 sentences about their readiness to pivot",
    "key_strengths": ["strength 1", "strength 2"],
    "key_gaps": ["gap 1", "gap 2"]
  },
  "viable_transitions": [
    {
      "target_role": "Specific role title",
      "fit_score": "1-10",
      "why_viable": "1-2 sentences why this is a good fit",
      "transferable_skills": ["skill 1", "skill 2", "skill 3"],
      "skills_to_acquire": [
        {
          "skill": "skill name",
          "priority": "critical|important|helpful",
          "time_to_learn": "estimate (e.g., '2-3 months')"
        }
      ],
      "timeline": "Realistic timeline (e.g., '6-12 months')",
      "first_steps": ["step 1", "step 2", "step 3"]
    }
  ],
  "ai_advantage": {
    "how_ai_helps": "1-2 sentences on how AI makes this transition easier now",
    "ai_skills_needed": ["AI skill 1", "AI skill 2"]
  },
  "market_insights": {
    "demand_trend": "growing|stable|declining",
    "why": "1 sentence explanation",
    "salary_outlook": "Brief outlook"
  },
  "action_plan": {
    "immediate": "What to do this week",
    "month_1_3": "Focus for months 1-3",
    "month_4_6": "Focus for months 4-6",
    "beyond": "Long-term focus"
  },
  "career_confidence": [
    {
      "insight": "Inspiring statement about their career transition potential",
      "evidence": "Supporting detail about why this transition is achievable"
    },
    {
      "insight": "Second inspiring statement about career development",
      "evidence": "Supporting detail about their strengths"
    }
  ]
}
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'transition_readiness',
      'viable_transitions',
      'ai_advantage',
      'market_insights',
      'action_plan',
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
    return 'ai_career_transitions.analysis_service';
  }

}
