<?php

namespace Drupal\role_impact_analysis\Service;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for generating AI-powered analysis insights.
 *
 * This is the AI portion of the hybrid role_impact_analysis module.
 * It extends the base class to provide AI insights that complement
 * the rule-based analysis in RoleImpactAnalysis.
 */
class AiAnalysisService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'hybrid_analysis';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'role_impact_analysis';
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
    // Extract data from submissions.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $skills_data = $submission_data['skills_gap']['data'] ?? [];

    // Build context from submissions.
    $role_category = $task_data['m2_q1_role_category'] ?? 'unknown';

    // Extract task preferences.
    $help_tasks = [];
    $keep_tasks = [];

    foreach ($task_data as $key => $value) {
      if (strpos($key, 'm2_q2_') === 0 && $value) {
        $task_label = $this->getTaskLabel($key);
        if ($value === 'help' && $task_label) {
          $help_tasks[] = $task_label;
        }
        elseif ($value === 'keep' && $task_label) {
          $keep_tasks[] = $task_label;
        }
      }
    }

    // Get skill level.
    $skill_level = $skills_data['m5_q1_skill_level'] ?? 'never_used';

    // Limit tasks for prompt brevity.
    $help_tasks_str = implode(', ', array_slice($help_tasks, 0, 5));
    $keep_tasks_str = implode(', ', array_slice($keep_tasks, 0, 3));

    return <<<PROMPT
Provide AI insights for this career profile. Be concise.

Role: {$role_category}
Skill Level: {$skill_level}
Tasks wanting help with: {$help_tasks_str}
Tasks want to keep: {$keep_tasks_str}

Respond with JSON (keep brief, limit arrays to 3 items max):

{
  "personalized_narrative": "2-3 sentences summarizing their situation",
  "hidden_opportunities": ["insight 1", "insight 2", "insight 3"],
  "tool_recommendations": [
    {"task": "task name", "tool": "AI tool", "why": "brief", "getting_started": "brief"}
  ],
  "skill_development_roadmap": {
    "immediate_focus": "1 sentence",
    "thirty_day_goal": "1 sentence",
    "ninety_day_goal": "1 sentence",
    "learning_approach": "1 sentence"
  },
  "industry_context": {
    "role_evolution": "1-2 sentences",
    "competitive_advantage": "1-2 sentences",
    "watch_out_for": "1-2 sentences"
  },
  "confidence_boosters": ["booster 1", "booster 2", "booster 3"]
}
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    // Validate required AI insights fields.
    $required_fields = [
      'personalized_narrative',
      'hidden_opportunities',
      'tool_recommendations',
      'skill_development_roadmap',
      'industry_context',
      'confidence_boosters',
    ];

    foreach ($required_fields as $field) {
      if (!isset($response[$field])) {
        $this->logger->error('AI response missing required field: @field', [
          '@field' => $field,
        ]);
        return FALSE;
      }
    }

    // Validate nested structures.
    if (!isset($response['skill_development_roadmap']['immediate_focus']) ||
        !isset($response['skill_development_roadmap']['thirty_day_goal']) ||
        !isset($response['skill_development_roadmap']['ninety_day_goal']) ||
        !isset($response['skill_development_roadmap']['learning_approach'])) {
      $this->logger->error('AI response missing skill_development_roadmap subfields');
      return FALSE;
    }

    if (!isset($response['industry_context']['role_evolution']) ||
        !isset($response['industry_context']['competitive_advantage']) ||
        !isset($response['industry_context']['watch_out_for'])) {
      $this->logger->error('AI response missing industry_context subfields');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Get human-readable task label from task key.
   *
   * @param string $task_key
   *   The task key (e.g., 'm2_q2_sales_followup').
   *
   * @return string|null
   *   The task label or NULL.
   */
  protected function getTaskLabel($task_key) {
    $task_labels = [
      // Sales tasks.
      'm2_q2_sales_followup' => 'Writing follow-up emails',
      'm2_q2_sales_research' => 'Researching prospects/accounts',
      'm2_q2_sales_scheduling' => 'Scheduling meetings',
      'm2_q2_sales_relationships' => 'Building relationships with clients',
      'm2_q2_sales_proposals' => 'Creating proposals/quotes',
      'm2_q2_sales_crm' => 'Entering data into CRM',
      'm2_q2_sales_negotiations' => 'Having complex negotiations',
      'm2_q2_sales_analysis' => 'Analyzing sales data',

      // Engineering tasks.
      'm2_q2_eng_boilerplate' => 'Writing boilerplate code',
      'm2_q2_eng_debugging' => 'Debugging errors',
      'm2_q2_eng_docs' => 'Writing documentation',
      'm2_q2_eng_reviews' => 'Code reviews',
      'm2_q2_eng_architecture' => 'System architecture decisions',
      'm2_q2_eng_learning' => 'Learning new frameworks',
      'm2_q2_eng_tests' => 'Writing tests',
      'm2_q2_eng_mentoring' => 'Mentoring junior developers',

      // Marketing tasks.
      'm2_q2_mkt_brainstorm' => 'Brainstorming campaign ideas',
      'm2_q2_mkt_social' => 'Writing social media posts',
      'm2_q2_mkt_design' => 'Designing visual assets',
      'm2_q2_mkt_analysis' => 'Analyzing campaign performance',
      'm2_q2_mkt_strategy' => 'Creating brand strategy',
      'm2_q2_mkt_ab' => 'A/B test analysis',
      'm2_q2_mkt_presentations' => 'Client presentations',
      'm2_q2_mkt_calendar' => 'Content calendar planning',

      // Operations tasks.
      'm2_q2_ops_data_entry' => 'Data entry',
      'm2_q2_ops_scheduling' => 'Scheduling / calendar management',
      'm2_q2_ops_email' => 'Email management',
      'm2_q2_ops_reports' => 'Report generation',
      'm2_q2_ops_docs' => 'Process documentation',
      'm2_q2_ops_vendors' => 'Vendor coordination',
      'm2_q2_ops_meetings' => 'Meeting coordination',
      'm2_q2_ops_edge_cases' => 'Problem-solving edge cases',

      // Management tasks.
      'm2_q2_mgmt_reviews' => 'Performance review writing',
      'm2_q2_mgmt_communication' => 'Team communication',
      'm2_q2_mgmt_planning' => 'Strategic planning',
      'm2_q2_mgmt_budget' => 'Budget analysis',
      'm2_q2_mgmt_coaching' => 'One-on-one coaching',
      'm2_q2_mgmt_hiring' => 'Hiring decisions',
      'm2_q2_mgmt_conflict' => 'Conflict resolution',
      'm2_q2_mgmt_vision' => 'Vision setting',

      // Finance tasks.
      'm2_q2_fin_cleaning' => 'Data cleaning / preparation',
      'm2_q2_fin_models' => 'Building financial models',
      'm2_q2_fin_viz' => 'Creating visualizations',
      'm2_q2_fin_trends' => 'Identifying trends/patterns',
      'm2_q2_fin_forecasting' => 'Forecasting',
      'm2_q2_fin_reconciliation' => 'Reconciliation tasks',
      'm2_q2_fin_recommendations' => 'Strategic recommendations',
      'm2_q2_fin_presentations' => 'Stakeholder presentations',

      // HR tasks.
      'm2_q2_hr_screening' => 'Resume screening',
      'm2_q2_hr_scheduling' => 'Interview scheduling',
      'm2_q2_hr_onboarding' => 'Onboarding documentation',
      'm2_q2_hr_benefits' => 'Benefits administration',
      'm2_q2_hr_relations' => 'Employee relations / sensitive issues',
      'm2_q2_hr_training' => 'Training program design',
      'm2_q2_hr_performance' => 'Performance management',
      'm2_q2_hr_culture' => 'Culture building',
    ];

    return $task_labels[$task_key] ?? NULL;
  }

  /**
   * Generate AI-powered insights from rule-based analysis and submission data.
   *
   * This is the main public method that the RoleImpactAnalysis service calls.
   * It wraps the generateReport() method from the base class.
   *
   * @param array $rule_based_data
   *   The rule-based analysis results containing risk scores, evolution paths, etc.
   * @param array $submissions
   *   Raw webform submission data.
   * @param int $uid
   *   The user ID for cache tagging.
   *
   * @return array|null
   *   Array of AI insights or NULL if generation fails.
   */
  public function generateAiInsights(array $rule_based_data, array $submissions, int $uid): ?array {
    // Use the base class generateReport() method which handles:
    // - Cache checking
    // - Entity storage
    // - Versioning
    // - AI API calls
    // - Error handling
    $report = $this->generateReport($uid);

    // If we get an error array, return NULL.
    if (is_array($report) && isset($report['error'])) {
      $this->logger->warning('AI insights generation returned error: @error', [
        '@error' => $report['error'],
      ]);
      return NULL;
    }

    // Return just the AI insights portion (without metadata).
    if (is_array($report)) {
      // Remove metadata fields that were added by base class.
      unset($report['generated_at']);
      unset($report['uid']);
      unset($report['generation_time']);
      unset($report['version']);
      return $report;
    }

    return NULL;
  }

}
