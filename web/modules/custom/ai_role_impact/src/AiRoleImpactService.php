<?php

namespace Drupal\ai_role_impact;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for AI-powered role impact analysis.
 */
class AiRoleImpactService {

  protected $entityTypeManager;
  protected $currentUser;
  protected $clientManager;
  protected $aiProvider;
  protected $logger;
  protected $cache;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    WebformClientManager $client_manager,
    AiProviderPluginManager $ai_provider,
    LoggerChannelFactoryInterface $logger_factory,
    CacheBackendInterface $cache
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->clientManager = $client_manager;
    $this->aiProvider = $ai_provider;
    $this->logger = $logger_factory->get('ai_role_impact');
    $this->cache = $cache;
  }

  public function getSubmissionData($webform_id, $uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $submission_storage = $this->entityTypeManager->getStorage('webform_submission');
    $query = $submission_storage->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->condition('completed', 0, '>')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $sids = $query->execute();

    if (empty($sids)) {
      return NULL;
    }

    $submission = $submission_storage->load(reset($sids));
    if ($submission) {
      return $submission->getData();
    }
    return NULL;
  }

  public function hasMinimumData($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $task_data = $this->getSubmissionData('task_analysis', $uid);
    $skills_data = $this->getSubmissionData('skills_gap', $uid);

    return !empty($task_data) && !empty($skills_data);
  }

  public function generateReport($uid = NULL, $retry = TRUE) {
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $cid = "ai_role_impact:report:{$uid}";
    if ($cache = $this->cache->get($cid)) {
      $this->logger->info('Returning cached role impact for user @uid', ['@uid' => $uid]);
      return $cache->data;
    }

    $task_data = $this->getSubmissionData('task_analysis', $uid);
    $skills_data = $this->getSubmissionData('skills_gap', $uid);
    $ai_usage_data = $this->getSubmissionData('current_ai_usage', $uid);

    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($task_data, $skills_data, $ai_usage_data);
      $response = $this->callAnthropicApi($prompt, $retry);

      if ($response === NULL) {
        return ['error' => 'API_TIMEOUT', 'message' => 'The AI service timed out. This analysis requires complex processing. Please try again in a moment.'];
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        return ['error' => 'PARSE_ERROR', 'message' => 'Unable to parse AI response. Please try regenerating the analysis.'];
      }

      $duration = round(microtime(TRUE) - $start_time, 2);
      $this->logger->info('Generated role impact for user @uid in @duration seconds', [
        '@uid' => $uid,
        '@duration' => $duration,
      ]);

      $report['generated_at'] = time();
      $report['uid'] = $uid;
      $report['generation_time'] = $duration;

      $this->cache->set($cid, $report, CacheBackendInterface::CACHE_PERMANENT, [
        "user:{$uid}",
        'webform_submission_list',
        "ai_role_impact:{$uid}",
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate role impact for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => 'EXCEPTION', 'message' => 'An unexpected error occurred. Please try again later.', 'details' => $e->getMessage()];
    }
  }

  protected function buildPrompt(array $task_data, array $skills_data, ?array $ai_usage_data) {
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

Generate JSON report with these sections (be concise):

1. current_state:
   - role_category, ai_frequency, ai_comfort
   - task_profile: {help_me_percentage, keep_it_percentage, unsure_percentage}

2. displacement_risk:
   - risk_score (0-100), risk_level (low|medium|high|critical), urgency
   - task_breakdown: {help: [], keep: [], unsure: []} - list actual tasks with automation_potential

3. skill_evolution:
   - increasing_value: [3-4 skills with {skill, reason, action}]
   - emerging_value: [3-4 skills with {skill, reason, action}]

4. evolution_path:
   - path_name, from_state, to_state, description (2 sentences)
   - positioning_points: [3-4 strings]
   - obstacles: [3-4 strings]
   - actions: [3-4 strings]

5. value_proposition:
   - summary (2 sentences)
   - irreplaceable_skills: [3-4 items with {skill, task}]
   - ai_multipliers: [3-4 items with {skill, why}]

6. action_plan:
   - immediate: {title, actions: [3-4 strings]}
   - thirty_day: {title, actions: [3-4 strings]}
   - ninety_day: {title, actions: [3-4 strings]}
   - six_month: {title, actions: [3-4 strings]}

7. learning_path:
   - recommended_approach (1 sentence)
   - timeline: {
       month_1: {focus, activities: [3-4 strings]},
       month_2_3: {focus, activities: [3-4 strings]},
       month_4_6: {focus, activities: [3-4 strings]}
     }
   - barrier_strategies: {
       time_management: [2-3 strings],
       staying_current: [2-3 strings],
       avoiding_overwhelm: [2-3 strings]
     }

8. ai_insights:
   - personalized_narrative (1 paragraph, 3 sentences max)
   - hidden_opportunities: [3-4 items with {opportunity, description, action}]
   - tool_recommendations: [3-4 items with {tool, why_for_you, use_cases: [2-3 strings]}]
   - skill_development_roadmap: {
       quarter_1: {primary_focus, skills: [3-4 strings], deliverables: [3-4 strings]},
       quarter_2: {primary_focus, skills: [3-4 strings], deliverables: [3-4 strings]}
     }
   - industry_context: {role_evolution, competitive_advantage, watch_out_for} (1 sentence each)
   - confidence_boosters: [3-4 items with {insight, evidence}]

RULES:
- Risk score: 0-30=low, 31-55=medium, 56-75=high, 76+=critical (based on help_me% and role)
- Be specific to THEIR data, avoid generic advice
- Keep responses concise - this is about actionable insights, not volume
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

  protected function callAnthropicApi(string $prompt, $retry = TRUE): ?string {
    $max_attempts = $retry ? 2 : 1;
    $attempt = 0;

    while ($attempt < $max_attempts) {
      $attempt++;

      try {
        $this->logger->info('Calling Anthropic API (attempt @attempt of @max) with 600s timeout', [
          '@attempt' => $attempt,
          '@max' => $max_attempts,
        ]);

        // Set execution timeout to allow for long API calls.
        set_time_limit(630);

        $provider = $this->aiProvider->createInstance('anthropic', ['timeout' => 600]);

        $system_prompt = 'You are an expert career analyst. Provide realistic, actionable career guidance based on AI displacement research. Always respond with valid JSON matching the exact structure requested. Be concise and specific - quality over quantity.';

        $messages = new ChatInput([
          new ChatMessage('user', $prompt),
        ]);
        $messages->setSystemPrompt($system_prompt);

        $response = $provider->chat(
          $messages,
          'claude-sonnet-4-5-20250929'
        );

        $this->logger->info('API call successful on attempt @attempt', ['@attempt' => $attempt]);
        return $response->getNormalized()->getText();
      }
      catch (\Exception $e) {
        $is_timeout = strpos($e->getMessage(), 'timeout') !== FALSE || strpos($e->getMessage(), 'timed out') !== FALSE;

        if ($is_timeout && $attempt < $max_attempts) {
          $this->logger->warning('API timeout on attempt @attempt, retrying...', ['@attempt' => $attempt]);
          sleep(2); // Wait 2 seconds before retry
          continue;
        }

        $this->logger->error('Anthropic API call failed after @attempt attempts: @error', [
          '@attempt' => $attempt,
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    return NULL;
  }

  protected function parseResponse(string $response): ?array {
    try {
      $json_string = $response;

      if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
        $json_string = $matches[1];
      }
      elseif (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
        $json_string = $matches[1];
      }

      $report = json_decode($json_string, TRUE);

      if ($report === NULL) {
        $this->logger->error('Failed to parse AI response as JSON');
        return NULL;
      }

      $required_fields = [
        'current_state',
        'displacement_risk',
        'skill_evolution',
        'evolution_path',
        'value_proposition',
        'action_plan',
        'learning_path',
        'ai_insights',
      ];

      foreach ($required_fields as $field) {
        if (!isset($report[$field])) {
          $this->logger->error('AI response missing required field: @field', ['@field' => $field]);
          return NULL;
        }
      }

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing AI response: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  public function clearCache(int $uid): void {
    $cid = "ai_role_impact:report:{$uid}";
    $this->cache->delete($cid);
    $this->logger->info('Cleared role impact cache for user @uid', ['@uid' => $uid]);
  }

}
