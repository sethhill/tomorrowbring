<?php

namespace Drupal\ai_career_transitions;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for AI-powered career transition analysis.
 */
class AiCareerTransitionsService {

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
    $this->logger = $logger_factory->get('ai_career_transitions');
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

  public function generateReport($uid = NULL) {
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $cid = "ai_career_transitions:report:{$uid}";
    if ($cache = $this->cache->get($cid)) {
      $this->logger->info('Returning cached career transitions for user @uid', ['@uid' => $uid]);
      return $cache->data;
    }

    $task_data = $this->getSubmissionData('task_analysis', $uid);
    $skills_data = $this->getSubmissionData('skills_gap', $uid);

    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($task_data, $skills_data);
      $response = $this->callAnthropicApi($prompt);

      if ($response === NULL) {
        return NULL;
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        return NULL;
      }

      $duration = round(microtime(TRUE) - $start_time, 2);
      $this->logger->info('Generated career transitions for user @uid in @duration seconds', [
        '@uid' => $uid,
        '@duration' => $duration,
      ]);

      $report['generated_at'] = time();
      $report['uid'] = $uid;

      $this->cache->set($cid, $report, CacheBackendInterface::CACHE_PERMANENT, [
        "user:{$uid}",
        'webform_submission_list',
        "ai_career_transitions:{$uid}",
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate career transitions for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  protected function buildPrompt(array $task_data, array $skills_data) {
    $current_role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_skills = $skills_data['m5_q2_current_skills'] ?? 'not specified';
    $desired_skills = $skills_data['m5_q3_desired_skills'] ?? 'not specified';

    return <<<PROMPT
Analyze career transition opportunities in the AI era. Be concise.

Current Role: {$current_role}
Current Skills: {$current_skills}
Desired Skills: {$desired_skills}

Respond with JSON (limit to 3-4 viable transitions):

{
  "transition_readiness": {
    "overall_score": "1-10",
    "summary": "1-2 sentences about their readiness to pivot",
    "key_strengths": ["strength 1", "strength 2"],
    "key_gaps": ["gap 1", "gap 2"]
  },
  "viable_transitions": [
    {
      "target_role": "Specific role title",
      "fit_score": "1-10",
      "why_viable": "1-2 sentences why this is a good fit",
      "transferable_skills": ["skill 1", "skill 2", "skill 3"],
      "skills_to_acquire": [
        {
          "skill": "skill name",
          "priority": "critical|important|helpful",
          "time_to_learn": "estimate (e.g., '2-3 months')"
        }
      ],
      "timeline": "Realistic timeline (e.g., '6-12 months')",
      "first_steps": ["step 1", "step 2", "step 3"]
    }
  ],
  "ai_advantage": {
    "how_ai_helps": "1-2 sentences on how AI makes this transition easier now",
    "ai_skills_needed": ["AI skill 1", "AI skill 2"]
  },
  "market_insights": {
    "demand_trend": "growing|stable|declining",
    "why": "1 sentence explanation",
    "salary_outlook": "Brief outlook"
  },
  "action_plan": {
    "immediate": "What to do this week",
    "month_1_3": "Focus for months 1-3",
    "month_4_6": "Focus for months 4-6",
    "beyond": "Long-term focus"
  }
}
PROMPT;
  }

  protected function callAnthropicApi(string $prompt): ?string {
    try {
      $provider = $this->aiProvider->createInstance('anthropic');

      $system_prompt = 'You are a career transition expert. Provide realistic, actionable career guidance. Always respond with valid JSON.';

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);
      $messages->setSystemPrompt($system_prompt);

      $response = $provider->chat(
        $messages,
        'claude-sonnet-4-5-20250929',
        ['ai-career-transitions', 'career-analysis']
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
        'transition_readiness',
        'viable_transitions',
        'ai_advantage',
        'market_insights',
        'action_plan',
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
    $cid = "ai_career_transitions:report:{$uid}";
    $this->cache->delete($cid);
    $this->logger->info('Cleared career transitions cache for user @uid', ['@uid' => $uid]);
  }

}
