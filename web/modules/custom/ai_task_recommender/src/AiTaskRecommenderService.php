<?php

namespace Drupal\ai_task_recommender;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered task automation recommendations.
 */
class AiTaskRecommenderService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'task_recommendations';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_task_recommender';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['task_analysis', 'current_ai_usage'];
  }

  /**
   * Get human-readable task labels.
   *
   * @return array
   *   Map of field names to readable labels.
   */
  protected function getTaskLabels(): array {
    return [
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
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract task_analysis data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $ai_usage_data = $submission_data['current_ai_usage']['data'] ?? [];

    $role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? '1';

    // Get task labels.
    $task_labels = $this->getTaskLabels();

    // Extract tasks by category.
    $help_tasks = [];
    $keep_tasks = [];
    foreach ($task_data as $key => $value) {
      if (strpos($key, 'm2_q2_') === 0 && isset($task_labels[$key])) {
        $task_name = $task_labels[$key];
        if ($value === 'help') {
          $help_tasks[] = $task_name;
        }
        elseif ($value === 'keep') {
          $keep_tasks[] = $task_name;
        }
      }
    }
    $help_tasks_str = implode(', ', $help_tasks);
    $keep_tasks_str = implode(', ', $keep_tasks);

    return <<<PROMPT
Recommend specific AI tools for task automation. Be concise and practical.

Role: {$role}
AI Comfort Level: {$current_ai_comfort}/5
Tasks wanting automation help with: {$help_tasks_str}
Tasks they want to keep doing themselves: {$keep_tasks_str}

IMPORTANT: For "automatable_tasks", generate 10 specific, concrete example tasks this person could automate based on their role and the tasks they mentioned. These should be realistic, actionable tasks (e.g., "Drafting weekly status update emails to stakeholders" not just "Email management"). Mix tasks from both categories (help and keep).

Respond with JSON (limit to 3-5 tool recommendations):

{
  "overview": {
    "automation_potential": "1-2 sentences about their automation opportunity",
    "automatable_tasks": [
      "Specific example task 1 based on their role and responses",
      "Specific example task 2",
      "Specific example task 3",
      "Specific example task 4",
      "Specific example task 5",
      "Specific example task 6",
      "Specific example task 7",
      "Specific example task 8",
      "Specific example task 9",
      "Specific example task 10"
    ],
    "quick_wins": [
      {
        "insight": "Inspiring statement about a quick automation win",
        "evidence": "Supporting detail on why this is achievable"
      },
      {
        "insight": "Second inspiring quick win statement",
        "evidence": "Supporting detail on this opportunity"
      }
    ]
  },
  "tool_recommendations": [
    {
      "task": "specific task name",
      "tool_name": "Specific AI tool/service name",
      "tool_category": "category (e.g., 'Writing Assistant', 'Code Generator', 'Data Analysis')",
      "why_recommended": "1 sentence why this tool fits",
      "getting_started": "Concrete first step to try it",
      "time_savings": "Estimated time saved (e.g., '2-3 hours/week')",
      "difficulty": "easy|moderate|advanced",
      "cost": "free|freemium|paid"
    }
  ],
  "implementation_roadmap": {
    "week_1": {
      "focus": "Which tool/task to start with",
      "action": "Specific action to take"
    },
    "week_2_4": {
      "focus": "Next tool/task",
      "action": "Specific action"
    },
    "ongoing": {
      "habits": ["Habit 1 to build", "Habit 2 to build"]
    }
  },
  "workflow_integration": {
    "automation_workflows": [
      {
        "workflow_name": "Name of workflow (e.g., 'Email Response Automation')",
        "tools_combined": ["Tool A", "Tool B"],
        "process_steps": ["Step 1", "Step 2", "Step 3"],
        "expected_impact": "Brief impact description"
      }
    ]
  },
  "skill_development": {
    "prompt_engineering": "1-2 sentences on learning to write effective AI prompts",
    "tool_mastery": "1-2 sentences on deepening tool proficiency",
    "staying_current": "1-2 sentences on keeping up with new tools"
  }
}
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'overview',
      'tool_recommendations',
      'implementation_roadmap',
      'workflow_integration',
      'skill_development',
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
    return 'ai_task_recommender.analysis_service';
  }

}
