<?php

namespace Drupal\role_impact_analysis\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Service for generating AI-powered analysis insights.
 */
class AiAnalysisService {

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
   * Constructs an AiAnalysisService object.
   *
   * @param \Drupal\ai\AiProviderPluginManager $ai_provider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(
    AiProviderPluginManager $ai_provider,
    LoggerChannelFactoryInterface $logger_factory,
    CacheBackendInterface $cache
  ) {
    $this->aiProvider = $ai_provider;
    $this->logger = $logger_factory->get('role_impact_analysis');
    $this->cache = $cache;
  }

  /**
   * Generate AI-powered insights from rule-based analysis and submission data.
   *
   * @param array $rule_based_data
   *   The rule-based analysis results containing risk scores, evolution paths, etc.
   * @param array $submissions
   *   Raw webform submission data.
   * @param int $uid
   *   The user ID for cache tagging.
   *
   * @return array|null
   *   Array of AI insights or NULL if generation fails.
   */
  public function generateAiInsights(array $rule_based_data, array $submissions, int $uid): ?array {
    // Check cache first.
    $cid = "role_impact_analysis:ai_insights:{$uid}";
    if ($cache = $this->cache->get($cid)) {
      $this->logger->info('Returning cached AI insights for user @uid', ['@uid' => $uid]);
      return $cache->data;
    }

    $start_time = microtime(TRUE);

    try {
      // Build the prompt with context.
      $prompt = $this->buildPrompt($rule_based_data, $submissions);

      // Call Anthropic API.
      $response = $this->callAnthropicApi($prompt);

      if ($response === NULL) {
        return NULL;
      }

      // Parse the AI response.
      $insights = $this->parseAiResponse($response);

      if ($insights === NULL) {
        return NULL;
      }

      // Log successful generation.
      $duration = round(microtime(TRUE) - $start_time, 2);
      $this->logger->info('Generated AI insights for user @uid in @duration seconds', [
        '@uid' => $uid,
        '@duration' => $duration,
      ]);

      // Cache indefinitely with appropriate tags.
      $this->cache->set($cid, $insights, CacheBackendInterface::CACHE_PERMANENT, [
        "user:{$uid}",
        'webform_submission_list',
        "ai_analysis:{$uid}",
      ]);

      return $insights;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate AI insights for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Build the prompt for AI analysis.
   *
   * @param array $rule_based_data
   *   Rule-based analysis results.
   * @param array $submissions
   *   Raw submission data.
   *
   * @return string
   *   The formatted prompt.
   */
  protected function buildPrompt(array $rule_based_data, array $submissions): string {
    // Extract key information from rule-based analysis.
    $context = [
      'risk_score' => $rule_based_data['displacement_risk']['risk_score'] ?? 0,
      'risk_level' => $rule_based_data['displacement_risk']['risk_level'] ?? 'unknown',
      'urgency_level' => $rule_based_data['displacement_risk']['urgency_level'] ?? 'unknown',
      'evolution_path' => $rule_based_data['evolution_path']['id'] ?? 'unknown',
      'role_category' => $rule_based_data['current_state']['role_category'] ?? 'unknown',
      'ai_usage_frequency' => $rule_based_data['current_state']['ai_usage_frequency'] ?? 'unknown',
      'current_comfort' => $rule_based_data['current_state']['current_comfort'] ?? 'unknown',
    ];

    // Extract task preferences.
    $task_breakdown = $rule_based_data['displacement_risk']['task_breakdown'] ?? [];
    $help_tasks = [];
    $keep_tasks = [];

    foreach ($task_breakdown as $task) {
      if ($task['preference'] === 'help') {
        $help_tasks[] = $task['task'];
      }
      elseif ($task['preference'] === 'keep') {
        $keep_tasks[] = $task['task'];
      }
    }

    // Limit help tasks to first 5 for prompt brevity
    $help_tasks_str = implode(', ', array_slice($help_tasks, 0, 5));
    $keep_tasks_str = implode(', ', array_slice($keep_tasks, 0, 3));

    return <<<PROMPT
Provide AI insights for this career profile. Be concise.

Role: {$context['role_category']}
Risk: {$context['risk_score']}/100 ({$context['risk_level']})
Evolution Path: {$context['evolution_path']}
Tasks wanting help with: {$help_tasks_str}
Tasks want to keep: {$keep_tasks_str}

Respond with JSON (keep brief, limit arrays to 3 items max):

{
  "personalized_narrative": "2-3 sentences summarizing their situation",
  "hidden_opportunities": ["insight 1", "insight 2", "insight 3"],
  "tool_recommendations": [
    {"task": "task name", "tool": "AI tool", "why": "brief", "getting_started": "brief"}
  ],
  "skill_development_roadmap": {
    "immediate_focus": "1 sentence",
    "thirty_day_goal": "1 sentence",
    "ninety_day_goal": "1 sentence",
    "learning_approach": "1 sentence"
  },
  "industry_context": {
    "role_evolution": "1-2 sentences",
    "competitive_advantage": "1-2 sentences",
    "watch_out_for": "1-2 sentences"
  },
  "confidence_boosters": ["booster 1", "booster 2", "booster 3"]
}
PROMPT;
  }

  /**
   * Call the Anthropic API via Drupal AI module.
   *
   * @param string $prompt
   *   The prompt to send.
   *
   * @return string|null
   *   The AI response text or NULL on failure.
   */
  protected function callAnthropicApi(string $prompt): ?string {
    try {
      // Get Anthropic provider.
      $provider = $this->aiProvider->createInstance('anthropic');

      // Build system prompt.
      $system_prompt = 'You are an expert career analyst specializing in AI\'s impact on professional roles. You provide empowering, actionable, and personalized insights based on data. You always respond with valid JSON.';

      // Create chat input.
      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);
      $messages->setSystemPrompt($system_prompt);

      // Call AI with claude-sonnet-4-5-20250929.
      $response = $provider->chat(
        $messages,
        'claude-sonnet-4-5-20250929',
        ['role-impact-analysis', 'ai-insights']
      );

      // Extract response text.
      $normalized = $response->getNormalized();
      return $normalized->getText();
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
   *   Parsed insights array or NULL if parsing fails.
   */
  protected function parseAiResponse(string $response): ?array {
    try {
      // Extract JSON from response (handle potential markdown code blocks).
      $json_string = $response;

      // Remove markdown code blocks if present.
      if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
        $json_string = $matches[1];
      }
      elseif (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
        $json_string = $matches[1];
      }

      // Decode JSON.
      $insights = json_decode($json_string, TRUE);

      if ($insights === NULL) {
        $this->logger->error('Failed to parse AI response as JSON. Response: @response', [
          '@response' => substr($response, 0, 500),
        ]);
        return NULL;
      }

      // Validate required fields.
      $required_fields = [
        'personalized_narrative',
        'hidden_opportunities',
        'tool_recommendations',
        'skill_development_roadmap',
        'industry_context',
        'confidence_boosters',
      ];

      foreach ($required_fields as $field) {
        if (!isset($insights[$field])) {
          $this->logger->error('AI response missing required field: @field', [
            '@field' => $field,
          ]);
          return NULL;
        }
      }

      return $insights;
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing AI response: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Clear cached AI insights for a user.
   *
   * @param int $uid
   *   The user ID.
   */
  public function clearCache(int $uid): void {
    $cid = "role_impact_analysis:ai_insights:{$uid}";
    $this->cache->delete($cid);
    $this->logger->info('Cleared AI insights cache for user @uid', ['@uid' => $uid]);
  }

}
