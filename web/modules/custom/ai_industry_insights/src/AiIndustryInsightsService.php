<?php

namespace Drupal\ai_industry_insights;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for AI-powered industry insights.
 */
class AiIndustryInsightsService {

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
    $this->logger = $logger_factory->get('ai_industry_insights');
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
    return $submission ? $submission->getData() : NULL;
  }

  public function hasMinimumData($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }
    return !empty($this->getSubmissionData('task_analysis', $uid));
  }

  public function generateReport($uid = NULL) {
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $cid = "ai_industry_insights:report:{$uid}";
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $task_data = $this->getSubmissionData('task_analysis', $uid);
    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($task_data);
      $response = $this->callAnthropicApi($prompt);

      if (!$response) {
        return NULL;
      }

      $report = $this->parseResponse($response);
      if (!$report) {
        return NULL;
      }

      $duration = round(microtime(TRUE) - $start_time, 2);
      $this->logger->info('Generated industry insights for user @uid in @duration seconds', [
        '@uid' => $uid,
        '@duration' => $duration,
      ]);

      $report['generated_at'] = time();
      $report['uid'] = $uid;

      $this->cache->set($cid, $report, CacheBackendInterface::CACHE_PERMANENT, [
        "user:{$uid}",
        'webform_submission_list',
        "ai_industry_insights:{$uid}",
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate industry insights for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  protected function buildPrompt(array $task_data) {
    $role = $task_data['m2_q1_role_category'] ?? 'unknown';

    return <<<PROMPT
Provide industry-specific AI impact insights. Be concise and current.

Role: {$role}

Respond with JSON:

{
  "industry_overview": {
    "current_state": "1-2 sentences on AI adoption in this industry",
    "transformation_pace": "slow|moderate|rapid",
    "key_drivers": ["driver 1", "driver 2"]
  },
  "role_evolution": {
    "how_changing": "1-2 sentences on how this role is evolving",
    "tasks_being_automated": ["task 1", "task 2"],
    "new_responsibilities": ["responsibility 1", "responsibility 2"],
    "timeline": "When major changes expected"
  },
  "competitive_positioning": {
    "differentiators": ["What sets professionals apart now"],
    "in_demand_skills": ["skill 1", "skill 2", "skill 3"],
    "red_flags": ["What to avoid/update"]
  },
  "emerging_trends": {
    "technologies": ["tech 1", "tech 2"],
    "methodologies": ["methodology 1", "methodology 2"],
    "impact_timeline": "When these become mainstream"
  },
  "strategic_recommendations": {
    "immediate_actions": ["action 1", "action 2"],
    "six_month_focus": "What to prioritize",
    "long_term_positioning": "How to stay relevant"
  }
}
PROMPT;
  }

  protected function callAnthropicApi(string $prompt): ?string {
    try {
      $provider = $this->aiProvider->createInstance('anthropic');

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);
      $messages->setSystemPrompt('You are an industry analyst specializing in AI transformation. Provide current, actionable insights. Always respond with valid JSON.');

      $response = $provider->chat(
        $messages,
        'claude-sonnet-4-5-20250929',
        ['ai-industry-insights', 'industry-analysis']
      );

      return $response->getNormalized()->getText();
    }
    catch (\Exception $e) {
      $this->logger->error('Anthropic API call failed: @error', ['@error' => $e->getMessage()]);
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
        return NULL;
      }

      $required = ['industry_overview', 'role_evolution', 'competitive_positioning', 'emerging_trends', 'strategic_recommendations'];
      foreach ($required as $field) {
        if (!isset($report[$field])) {
          return NULL;
        }
      }

      return $report;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  public function clearCache(int $uid): void {
    $this->cache->delete("ai_industry_insights:report:{$uid}");
  }

}
