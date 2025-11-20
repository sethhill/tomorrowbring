<?php

namespace Drupal\ai_skills_analyzer;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for AI-powered skills analysis.
 */
class AiSkillsAnalysisService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The webform client manager service.
   *
   * @var \Drupal\client_webform\WebformClientManager
   */
  protected $clientManager;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProvider;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs an AiSkillsAnalysisService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\client_webform\WebformClientManager $client_manager
   *   The webform client manager service.
   * @param \Drupal\ai\AiProviderPluginManager $ai_provider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
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
    $this->logger = $logger_factory->get('ai_skills_analyzer');
    $this->cache = $cache;
  }

  /**
   * Get submission data for a specific webform and user.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return array|null
   *   The submission data array or NULL if not found.
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
   * Check if user has minimum required data for analysis.
   *
   * @param int $uid
   *   The user ID (optional).
   *
   * @return bool
   *   TRUE if minimum data exists.
   */
  public function hasMinimumData($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Requires skills_gap and task_analysis webforms.
    $skills_data = $this->getSubmissionData('skills_gap', $uid);
    $task_data = $this->getSubmissionData('task_analysis', $uid);

    return !empty($skills_data) && !empty($task_data);
  }

  /**
   * Generate AI-powered skills analysis report.
   *
   * @param int $uid
   *   The user ID (optional).
   *
   * @return array|null
   *   Skills analysis report or NULL on failure.
   */
  public function generateReport($uid = NULL) {
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Check cache first.
    $cid = "ai_skills_analyzer:report:{$uid}";
    if ($cache = $this->cache->get($cid)) {
      $this->logger->info('Returning cached skills analysis for user @uid', ['@uid' => $uid]);
      return $cache->data;
    }

    // Get submission data.
    $skills_data = $this->getSubmissionData('skills_gap', $uid);
    $task_data = $this->getSubmissionData('task_analysis', $uid);

    // Generate AI analysis.
    $start_time = microtime(TRUE);

    try {
      $prompt = $this->buildPrompt($skills_data, $task_data);
      $response = $this->callAnthropicApi($prompt);

      if ($response === NULL) {
        return NULL;
      }

      $report = $this->parseResponse($response);

      if ($report === NULL) {
        return NULL;
      }

      // Log successful generation.
      $duration = round(microtime(TRUE) - $start_time, 2);
      $this->logger->info('Generated skills analysis for user @uid in @duration seconds', [
        '@uid' => $uid,
        '@duration' => $duration,
      ]);

      // Add metadata.
      $report['generated_at'] = time();
      $report['uid'] = $uid;

      // Cache indefinitely.
      $this->cache->set($cid, $report, CacheBackendInterface::CACHE_PERMANENT, [
        "user:{$uid}",
        'webform_submission_list',
        "ai_skills_analysis:{$uid}",
      ]);

      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate skills analysis for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Build the prompt for AI skills analysis.
   *
   * @param array $skills_data
   *   Skills gap submission data.
   * @param array $task_data
   *   Task analysis submission data.
   *
   * @return string
   *   The formatted prompt.
   */
  protected function buildPrompt(array $skills_data, array $task_data) {
    // Extract key data points for a focused prompt
    $role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_skills = $skills_data['m5_q2_current_skills'] ?? [];
    $desired_skills = $skills_data['m5_q3_desired_skills'] ?? [];

    return <<<PROMPT
Analyze this professional's skill development needs. Be concise.

Role: {$role}
Current Skills: {$current_skills}
Desired Skills: {$desired_skills}

Respond with JSON (keep it concise, limit arrays to 2-3 items):

{
  "skill_trajectory": {
    "current_strengths": [{"skill": "name", "why_valuable": "brief", "leverage_opportunities": "brief"}],
    "critical_gaps": [{"skill": "name", "why_critical": "brief", "impact_without": "brief", "acquisition_difficulty": "easy|moderate|challenging"}],
    "emerging_opportunities": [{"skill": "name", "why_emerging": "brief", "time_to_relevance": "brief"}]
  },
  "personalized_learning_path": {
    "learning_style_assessment": "1-2 sentences",
    "immediate_actions": [{"skill": "name", "action": "brief", "time_required": "brief", "expected_outcome": "brief"}],
    "thirty_day_sprint": {
      "focus_skill": "primary skill",
      "week_by_week": [{"week": 1, "activities": ["activity 1", "activity 2"], "milestone": "brief"}],
      "success_metrics": ["metric 1", "metric 2"]
    },
    "ninety_day_mastery": {
      "target_competencies": ["comp 1", "comp 2"],
      "project_based_learning": [{"project": "name", "skills_practiced": ["skill 1", "skill 2"], "portfolio_value": "brief"}]
    }
  },
  "learning_resources": {
    "recommended_platforms": [{"platform": "name", "why_recommended": "brief", "specific_courses": ["course 1", "course 2"]}],
    "practice_projects": [{"project": "idea", "skills_developed": ["skill 1"], "difficulty": "beginner|intermediate|advanced", "time_estimate": "brief"}],
    "community_resources": [{"resource": "name", "value": "brief", "how_to_engage": "brief"}]
  },
  "skill_synergies": {
    "powerful_combinations": [{"skills": ["A", "B"], "why_powerful": "brief", "application": "brief"}],
    "ai_augmentation_opportunities": [{"current_skill": "skill", "ai_tool": "tool", "multiplier_effect": "brief"}]
  },
  "barrier_strategies": {
    "identified_barriers": ["barrier 1"],
    "overcoming_strategies": [{"barrier": "specific", "strategies": ["strategy 1"], "mindset_shift": "brief"}]
  },
  "motivational_insights": {
    "unique_advantages": ["advantage 1"],
    "quick_wins": ["win 1"],
    "long_term_vision": "1-2 sentences"
  }
}
PROMPT;
  }

  /**
   * Call the Anthropic API.
   *
   * @param string $prompt
   *   The prompt to send.
   *
   * @return string|null
   *   The AI response text or NULL on failure.
   */
  protected function callAnthropicApi(string $prompt): ?string {
    try {
      $provider = $this->aiProvider->createInstance('anthropic');

      $system_prompt = 'You are an expert career development coach specializing in AI skills. Provide specific, actionable, and empowering guidance. Always respond with valid JSON.';

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);
      $messages->setSystemPrompt($system_prompt);

      $response = $provider->chat(
        $messages,
        'claude-sonnet-4-5-20250929',
        ['ai-skills-analyzer', 'skills-analysis']
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
   *
   * @param string $response
   *   The raw AI response.
   *
   * @return array|null
   *   Parsed report array or NULL if parsing fails.
   */
  protected function parseResponse(string $response): ?array {
    try {
      $json_string = $response;

      // Remove markdown code blocks if present.
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

      // Validate required fields.
      $required_fields = [
        'skill_trajectory',
        'personalized_learning_path',
        'learning_resources',
        'skill_synergies',
        'barrier_strategies',
        'motivational_insights',
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
   *
   * @param int $uid
   *   The user ID.
   */
  public function clearCache(int $uid): void {
    $cid = "ai_skills_analyzer:report:{$uid}";
    $this->cache->delete($cid);
    $this->logger->info('Cleared skills analysis cache for user @uid', ['@uid' => $uid]);
  }

}
