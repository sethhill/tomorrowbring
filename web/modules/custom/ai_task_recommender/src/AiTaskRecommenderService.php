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
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract task_analysis data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $ai_usage_data = $submission_data['current_ai_usage']['data'] ?? [];

    $role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? '1';

    // Extract "help me" tasks.
    $help_tasks = [];
    foreach ($task_data as $key => $value) {
      if (strpos($key, 'm2_q2_') === 0 && $value === 'help') {
        $task_name = str_replace(['m2_q2_', '_'], ['', ' '], $key);
        $help_tasks[] = $task_name;
      }
    }
    $help_tasks_str = implode(', ', array_slice($help_tasks, 0, 8));

    return <<<PROMPT
Recommend specific AI tools for task automation. Be concise and practical.

Role: {$role}
AI Comfort Level: {$current_ai_comfort}/5
Tasks wanting automation help with: {$help_tasks_str}

Respond with JSON (limit to 3-5 tool recommendations):

{
  "overview": {
    "automation_potential": "1-2 sentences about their automation opportunity",
    "quick_wins": "1-2 sentences about easiest tasks to automate first"
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

}
