<?php

namespace Drupal\ai_industry_insights;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered industry insights.
 */
class AiIndustryInsightsService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'industry_insights';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_industry_insights';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['task_analysis'];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $role = $task_data['m2_q1_role_category'] ?? 'unknown';

    return <<<PROMPT
Provide industry-specific AI impact insights. Be concise and current.

Role: {$role}

Respond with JSON:

{
  "industry_overview": {
    "current_state": "1-2 sentences on AI adoption in this industry",
    "transformation_pace": "slow|moderate|rapid",
    "key_drivers": ["driver 1", "driver 2"]
  },
  "role_evolution": {
    "how_changing": "1-2 sentences on how this role is evolving",
    "tasks_being_automated": ["task 1", "task 2"],
    "new_responsibilities": ["responsibility 1", "responsibility 2"],
    "timeline": "When major changes expected"
  },
  "competitive_positioning": {
    "differentiators": ["What sets professionals apart now"],
    "in_demand_skills": ["skill 1", "skill 2", "skill 3"],
    "red_flags": ["What to avoid/update"]
  },
  "emerging_trends": {
    "technologies": ["tech 1", "tech 2"],
    "methodologies": ["methodology 1", "methodology 2"],
    "impact_timeline": "When these become mainstream"
  },
  "strategic_recommendations": {
    "immediate_actions": ["action 1", "action 2"],
    "six_month_focus": "What to prioritize",
    "long_term_positioning": "How to stay relevant"
  }
}
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required = [
      'industry_overview',
      'role_evolution',
      'competitive_positioning',
      'emerging_trends',
      'strategic_recommendations',
    ];

    foreach ($required as $field) {
      if (!isset($response[$field])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_industry_insights.analysis_service';
  }

}
