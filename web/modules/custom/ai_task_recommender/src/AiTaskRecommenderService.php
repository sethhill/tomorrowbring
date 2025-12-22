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
   * Override to collect optional confidence data.
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

    // Optional: confidence for tone calibration.
    $confidence_result = $this->getSubmissionData('confidence', $uid);
    if ($confidence_result) {
      $submission_data['confidence'] = $confidence_result;
      $submission_ids[] = $confidence_result['sid'];
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

    // Optional: Extract confidence data for tone calibration.
    $confidence_data = $submission_data['confidence']['data'] ?? [];
    $emotional_context = $this->buildEmotionalContext($confidence_data);
    $emotional_section = $emotional_context['section'];
    $tone_rules = $emotional_context['tone_rules'];

    return <<<PROMPT
Recommend specific AI tools for task automation. Be concise and practical.

Role: {$role}
AI Comfort Level: {$current_ai_comfort}/5
Tasks wanting automation help with: {$help_tasks_str}
Tasks they want to keep doing themselves: {$keep_tasks_str}

{$emotional_section}

{$tone_rules}

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

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
    ],
  },
  "closing_message": {
    "title": "2-5 words, relevant to task automation",
    "message": "2-3 sentences connecting automation opportunities to capability gains. Be constructive, not motivational. Reference specific tasks or time savings from this report."
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
  "workflow_integration": {
    "automation_workflows": [
      {
        "workflow_name": "Name of workflow (e.g., 'Email Response Automation')",
        "tools_combined": ["Tool A", "Tool B"],
        "process_steps": ["Step 1", "Step 2", "Step 3"],
        "expected_impact": "Brief impact description"
      }
    ]
  }
}

ENHANCED PERSONALIZATION RULES:
- If anxiety > 3: start with low-risk automation suggestions, emphasize AI-assisted vs. full automation
- If confidence < 3: recommend easier-to-implement tools first (difficulty: easy), build confidence gradually
- If describes "energized" or excitement > 4: suggest aggressive automation strategy with advanced workflows
- Adjust automation_potential tone based on emotional state

TONE RULES for closing_message:
- Be direct and quantitative
- Connect automation to time reclaimed and capability expansion
- Focus on practical impact, not potential
- Avoid "work smarter", "unleash productivity", "transform your workflow"
- Use language like "reclaims hours", "compounds returns", "automation positions"

Return ONLY JSON.
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'overview',
      'closing_message',
      'tool_recommendations',
      'workflow_integration',
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
