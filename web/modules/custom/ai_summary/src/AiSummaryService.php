<?php

namespace Drupal\ai_summary;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI Summary - summarizes all reports with optimistic AI enablement message.
 */
class AiSummaryService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'summary';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_summary';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    // Require all major webforms to ensure comprehensive summary.
    return [
      'current_ai_usage',
      'task_analysis',
      'ethics',
      'future_vision',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    // Extract data from all modules for comprehensive summary.
    $ai_usage_data = $submission_data['current_ai_usage']['data'] ?? [];
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $ethics_data = $submission_data['ethics']['data'] ?? [];
    $future_data = $submission_data['future_vision']['data'] ?? [];

    // Current AI Usage.
    $ai_frequency = $ai_usage_data['m1_q2_frequency'] ?? 'not specified';
    $ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? '3';
    $ai_tools = $ai_usage_data['m1_q1_tools_used'] ?? [];
    if (is_array($ai_tools)) {
      $ai_tools = implode(', ', $ai_tools);
    }

    // Task Analysis.
    $role_category = $task_data['m2_q1_role_category'] ?? 'not specified';
    $tasks_described = $task_data['m2_q2_describe_tasks'] ?? '';

    // Ethics.
    $ethical_importance = $ethics_data['m11_q1_importance'] ?? '3';
    $ethics_concerns = $ethics_data['m11_q2_ethics_concerns'] ?? [];
    if (is_array($ethics_concerns)) {
      $ethics_concerns = implode(', ', $ethics_concerns);
    }

    // Future Vision.
    $career_5_years = $future_data['m14_q1_career_5_years'] ?? 'not_sure';
    $valuable_skills = $future_data['m14_q2_valuable_skills'] ?? [];
    if (is_array($valuable_skills)) {
      $valuable_skills = implode(', ', $valuable_skills);
    }
    $threat_opportunity_scale = $future_data['m14_q5_threat_opportunity'] ?? '5';

    return <<<PROMPT
Generate a comprehensive summary report that synthesizes the user's journey through all AI analysis reports.

PROFILE SUMMARY:
Role: {$role_category}
Tasks: {$tasks_described}
Current AI Usage: {$ai_frequency}, Tools: {$ai_tools}, Comfort: {$ai_comfort}/5
Ethics importance: {$ethical_importance}/5, Concerns: {$ethics_concerns}
Career outlook: {$career_5_years}, Skills valued: {$valuable_skills}
AI view (1=threat, 9=opportunity): {$threat_opportunity_scale}/9

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

This report should:
1. Summarize key insights from all previous reports (role impact, skills, tasks, career transitions, industry insights, learning, concerns, strategies)
2. End with an optimistic, empowering message about becoming more enabled with AI
3. Emphasize the journey from uncertainty to capability
4. Be concise but comprehensive

Structure (keep responses CONCISE - max 2-3 sentences per text field, 3-4 items per array):

1. journey_recap:
   - where_you_started (2 sentences about initial position with AI)
   - key_discoveries: [4 items - brief insights from different reports]
   - transformation_achieved (2 sentences about growth/change)

2. comprehensive_summary:
   - role_evolution_summary (2 sentences)
   - skills_development_summary (2 sentences)
   - automation_opportunities_summary (2 sentences)
   - career_possibilities_summary (2 sentences)

3. path_forward:
   - immediate_next_steps: [3 items - concrete actions to take now]
   - medium_term_goals: [3 items - goals for next 3-6 months]
   - long_term_vision (2 sentences about future potential)

4. ai_enablement_message:
   - empowerment_statement (3-4 sentences - optimistic, inspiring message about how AI will enhance their capabilities, not replace them)
   - unique_advantages: [3 items - their specific strengths with AI]
   - closing_thought (1-2 sentences - powerful, hopeful conclusion)

TONE: Optimistic, empowering, forward-looking. Emphasize AI as a tool for enhancement and growth.
RULES: Be specific and personal. Reference their actual data. Make them feel capable and excited. Return ONLY JSON.
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'journey_recap',
      'comprehensive_summary',
      'path_forward',
      'ai_enablement_message',
    ];

    $missing_fields = [];
    foreach ($required_fields as $field) {
      if (!isset($response[$field])) {
        $missing_fields[] = $field;
      }
    }

    if (!empty($missing_fields)) {
      $this->logger->error('AI response missing required fields: @fields', [
        '@fields' => implode(', ', $missing_fields),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_summary.service';
  }

}
