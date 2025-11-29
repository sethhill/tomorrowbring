<?php

namespace Drupal\ai_role_impact;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered role impact analysis.
 */
class AiRoleImpactService extends AiReportServiceBase {

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'role_impact';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_role_impact';
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
    $ai_usage_data = $submission_data['current_ai_usage']['data'] ?? [];
    // Extract role information
    $role_category = $task_data['m2_q1_role_category'] ?? 'unknown';
    $role_other = $task_data['m2_q1_other'] ?? '';

    // Extract AI usage data
    $ai_frequency = $ai_usage_data['m1_q2_frequency'] ?? 'never';
    $ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? '1';
    $ai_tools = $ai_usage_data['m1_q1_tools_used'] ?? [];
    if (is_array($ai_tools)) {
      $ai_tools = implode(', ', $ai_tools);
    }

    // Extract skills data
    $current_skills = $skills_data['m5_q2_current_skills'] ?? 'not specified';
    if (is_array($current_skills)) {
      $current_skills = implode(', ', $current_skills);
    }
    $desired_skills = $skills_data['m5_q3_want_to_develop'] ?? 'not specified';
    if (is_array($desired_skills)) {
      $desired_skills = implode(', ', $desired_skills);
    }
    $learning_barrier = $skills_data['m5_q4_barriers'] ?? 'not specified';
    if (is_array($learning_barrier)) {
      $learning_barrier = implode(', ', $learning_barrier);
    }
    $learning_style = $skills_data['m5_q5_learning_preference'] ?? 'not specified';
    if (is_array($learning_style)) {
      $learning_style = implode(', ', $learning_style);
    }

    // Extract task responses - categorize by user preference
    $tasks_help = [];
    $tasks_keep = [];
    $tasks_unsure = [];

    $task_labels = $this->getTaskLabels();

    foreach ($task_data as $key => $value) {
      if (strpos($key, 'm2_q2_') === 0 && $value && isset($task_labels[$key])) {
        $task_label = $task_labels[$key];
        if ($value === 'help') {
          $tasks_help[] = $task_label;
        }
        elseif ($value === 'keep') {
          $tasks_keep[] = $task_label;
        }
        elseif ($value === 'unsure') {
          $tasks_unsure[] = $task_label;
        }
      }
    }

    $tasks_help_str = !empty($tasks_help) ? implode(', ', $tasks_help) : 'none';
    $tasks_keep_str = !empty($tasks_keep) ? implode(', ', $tasks_keep) : 'none';
    $tasks_unsure_str = !empty($tasks_unsure) ? implode(', ', $tasks_unsure) : 'none';

    $total_tasks = count($tasks_help) + count($tasks_keep) + count($tasks_unsure);
    $help_percentage = $total_tasks > 0 ? round((count($tasks_help) / $total_tasks) * 100) : 0;
    $keep_percentage = $total_tasks > 0 ? round((count($tasks_keep) / $total_tasks) * 100) : 0;
    $unsure_percentage = $total_tasks > 0 ? round((count($tasks_unsure) / $total_tasks) * 100) : 0;

    return <<<PROMPT
Analyze AI displacement risk for this professional. Be concise but actionable.

PROFILE:
Role: {$role_category}{$role_other}
AI Usage: {$ai_frequency}, Comfort: {$ai_comfort}/5, Tools: {$ai_tools}
Current Skills: {$current_skills}
Want to Learn: {$desired_skills}
Learning Barrier: {$learning_barrier}, Style: {$learning_style}

TASKS ({$total_tasks} total):
- Want AI HELP ({$help_percentage}%): {$tasks_help_str}
- Want to KEEP ({$keep_percentage}%): {$tasks_keep_str}
- UNSURE ({$unsure_percentage}%): {$tasks_unsure_str}

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

Generate JSON report with these sections (be concise):

1. displacement_risk:
   - risk_score (0-100), risk_level (low|medium|high|critical), risk_summary (1-2 sentence assessment of their displacement risk)

2. evolution_path:
   - path_name, from_state, to_state, path_description (2 sentences)
   - positioning_points: [3-4 strings]
   - obstacles: [3-4 strings]

3. ai_insights:
   - personalized_narrative (1 paragraph, 3 sentences max)
   - confidence_boosters: [exactly 2 items with {insight, evidence}] - specific reasons THIS person should be optimistic
   - industry_context: {role_evolution, competitive_advantage, watch_out_for} (1 sentence each)

RULES:
- Risk score: 0-30=low, 31-55=medium, 56-75=high, 76+=critical (based on help_me% and role)
- Be specific to THEIR data, avoid generic advice
- Keep responses concise - this is about actionable insights, not volume
- ALWAYS include confidence_boosters - find genuine reasons for optimism in their profile
- Return ONLY JSON
PROMPT;
  }

  /**
   * Get task labels mapping.
   */
  protected function getTaskLabels() {
    return [
      'm2_q2_task1' => 'Research and information gathering',
      'm2_q2_task2' => 'Data analysis and interpretation',
      'm2_q2_task3' => 'Writing reports and documentation',
      'm2_q2_task4' => 'Creating presentations',
      'm2_q2_task5' => 'Email and communication management',
      'm2_q2_task6' => 'Scheduling and calendar management',
      'm2_q2_task7' => 'Project planning and management',
      'm2_q2_task8' => 'Budget and financial analysis',
      'm2_q2_task9' => 'Client relationship management',
      'm2_q2_task10' => 'Team collaboration and coordination',
      'm2_q2_task11' => 'Problem solving and troubleshooting',
      'm2_q2_task12' => 'Quality control and review',
      'm2_q2_task13' => 'Training and mentoring others',
      'm2_q2_task14' => 'Strategic planning and decision making',
      'm2_q2_task15' => 'Creative work and ideation',
      'm2_q2_task16' => 'Customer service and support',
      'm2_q2_task17' => 'Sales and business development',
      'm2_q2_task18' => 'Technical implementation',
      'm2_q2_task19' => 'Compliance and regulatory work',
      'm2_q2_task20' => 'Innovation and process improvement',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'displacement_risk',
      'evolution_path',
      'ai_insights',
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
   * Override to collect optional current_ai_usage data if available.
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

    // Optional: current_ai_usage.
    $ai_usage_result = $this->getSubmissionData('current_ai_usage', $uid);
    if ($ai_usage_result) {
      $submission_data['current_ai_usage'] = $ai_usage_result;
      $submission_ids[] = $ai_usage_result['sid'];
    }

    // Check if we should regenerate based on source data hash.
    if (!$force_regenerate && $latest_entity = $this->loadLatestReport($uid)) {
      $current_hash = $this->getSourceDataHash($submission_data);
      if ($latest_entity->getSourceDataHash() === $current_hash) {
        // Source data unchanged - return cached/entity version.
        $report_data = $latest_entity->getReportData();
        $report_data['generated_at'] = $latest_entity->getGeneratedAt();
        $report_data['uid'] = $uid;
        $report_data['generation_time'] = $latest_entity->getGenerationTime();
        $report_data['version'] = $latest_entity->getVersion();
        $this->setCachedReport($uid, $report_data);
        return $report_data;
      }
    }

    // Generate new report via AI.
    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($submission_data);
      $response = $this->callAnthropicApi($prompt, $retry);

      if ($response === NULL) {
        return [
          'error' => 'API_TIMEOUT',
          'message' => 'The AI service timed out. This analysis requires complex processing. Please try again in a moment.',
        ];
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        return [
          'error' => 'PARSE_ERROR',
          'message' => 'Unable to parse AI response. Please try regenerating the analysis.',
        ];
      }

      $duration = round(microtime(TRUE) - $start_time, 2);

      $report['generated_at'] = time();
      $report['uid'] = $uid;
      $report['generation_time'] = $duration;

      // Save as entity with versioning.
      $source_hash = $this->getSourceDataHash($submission_data);
      $entity = $this->saveReport($uid, $report, $duration, $submission_ids, $source_hash);
      $report['version'] = $entity->getVersion();

      // Archive old versions (keep last 5).
      $this->archiveOldReports($uid, 5);

      // Update cache.
      $this->setCachedReport($uid, $report);

      $this->logger->info('Generated report for user @uid in @duration seconds (version @version)', [
        '@uid' => $uid,
        '@duration' => $duration,
        '@version' => $entity->getVersion(),
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate report for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return [
        'error' => 'EXCEPTION',
        'message' => 'An unexpected error occurred. Please try again later.',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_role_impact.analysis_service';
  }

}
