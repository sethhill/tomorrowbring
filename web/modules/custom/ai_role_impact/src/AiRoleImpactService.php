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
Analyze this professional's AI displacement risk. Be specific and actionable.

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

Generate comprehensive JSON report with these sections:
- current_state: role_category, ai_frequency, ai_comfort, task_profile (percentages)
- displacement_risk: risk_score (number 0-100), risk_level (low|medium|high|critical), urgency, task_breakdown with all tasks listed
- skill_evolution: increasing_value and emerging_value skills with reasons and actions
- evolution_path: path name, from/to states, description, positioning points, obstacles, actions
- value_proposition: summary, irreplaceable_skills, ai_multipliers
- action_plan: immediate, thirty_day, ninety_day, six_month actions
- learning_path: recommended_approach, timeline (month_1, month_2_3, month_4_6 with focus and activities), learning_profile, barrier_strategies
- ai_insights: personalized_narrative (2-3 paragraphs), hidden_opportunities, tool_recommendations (3-5 real AI tools), skill_development_roadmap, industry_context, confidence_boosters

IMPORTANT:
- Include ALL their actual tasks in task_breakdown with automation_potential
- Risk score based on help_me% and role (0-30=low, 31-55=medium, 56-75=high, 76+=critical)
- Be specific to THEIR profile, not generic
- Suggest real, specific AI tools they can use today
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
        $this->logger->info('Calling Anthropic API (attempt @attempt of @max)', [
          '@attempt' => $attempt,
          '@max' => $max_attempts,
        ]);

        $provider = $this->aiProvider->createInstance('anthropic');

        $system_prompt = 'You are an expert career analyst. Provide realistic, actionable career guidance based on AI displacement research. Always respond with valid JSON matching the exact structure requested.';

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
