<?php

namespace Drupal\ai_task_recommender;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for AI-powered task automation recommendations.
 */
class AiTaskRecommenderService {

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The current user.
   */
  protected $currentUser;

  /**
   * The webform client manager service.
   */
  protected $clientManager;

  /**
   * The AI provider plugin manager.
   */
  protected $aiProvider;

  /**
   * The logger service.
   */
  protected $logger;

  /**
   * The cache backend.
   */
  protected $cache;

  /**
   * Constructs an AiTaskRecommenderService object.
   */
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
    $this->logger = $logger_factory->get('ai_task_recommender');
    $this->cache = $cache;
  }

  /**
   * Get submission data for a specific webform and user.
   */
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

  /**
   * Check if user has minimum required data.
   */
  public function hasMinimumData($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $task_data = $this->getSubmissionData('task_analysis', $uid);
    $ai_usage_data = $this->getSubmissionData('current_ai_usage', $uid);

    return !empty($task_data) && !empty($ai_usage_data);
  }

  /**
   * Generate AI-powered task automation recommendations.
   */
  public function generateReport($uid = NULL) {
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Check cache first.
    $cid = "ai_task_recommender:report:{$uid}";
    if ($cache = $this->cache->get($cid)) {
      $this->logger->info('Returning cached task recommendations for user @uid', ['@uid' => $uid]);
      return $cache->data;
    }

    // Get submission data.
    $task_data = $this->getSubmissionData('task_analysis', $uid);
    $ai_usage_data = $this->getSubmissionData('current_ai_usage', $uid);

    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($task_data, $ai_usage_data);
      $response = $this->callAnthropicApi($prompt);

      if ($response === NULL) {
        return NULL;
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        return NULL;
      }

      $duration = round(microtime(TRUE) - $start_time, 2);
      $this->logger->info('Generated task recommendations for user @uid in @duration seconds', [
        '@uid' => $uid,
        '@duration' => $duration,
      ]);

      $report['generated_at'] = time();
      $report['uid'] = $uid;

      $this->cache->set($cid, $report, CacheBackendInterface::CACHE_PERMANENT, [
        "user:{$uid}",
        'webform_submission_list',
        "ai_task_recommendations:{$uid}",
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate task recommendations for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Build the prompt for AI task recommendations.
   */
  protected function buildPrompt(array $task_data, array $ai_usage_data) {
    $role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_ai_comfort = $ai_usage_data['m1_q3_comfort'] ?? '1';

    // Extract "help me" tasks
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
   * Call the Anthropic API.
   */
  protected function callAnthropicApi(string $prompt): ?string {
    try {
      $provider = $this->aiProvider->createInstance('anthropic');

      $system_prompt = 'You are an AI automation expert. Recommend specific, practical tools and workflows. Always respond with valid JSON.';

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);
      $messages->setSystemPrompt($system_prompt);

      $response = $provider->chat(
        $messages,
        'claude-sonnet-4-5-20250929',
        ['ai-task-recommender', 'task-automation']
      );

      return $response->getNormalized()->getText();
    }
    catch (\Exception $e) {
      $this->logger->error('Anthropic API call failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Parse AI response into structured data.
   */
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
        'overview',
        'tool_recommendations',
        'implementation_roadmap',
        'workflow_integration',
        'skill_development',
      ];

      foreach ($required_fields as $field) {
        if (!isset($report[$field])) {
          $this->logger->error('AI response missing required field: @field', [
            '@field' => $field,
          ]);
          return NULL;
        }
      }

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing AI response: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Clear cached analysis for a user.
   */
  public function clearCache(int $uid): void {
    $cid = "ai_task_recommender:report:{$uid}";
    $this->cache->delete($cid);
    $this->logger->info('Cleared task recommendations cache for user @uid', ['@uid' => $uid]);
  }

}
