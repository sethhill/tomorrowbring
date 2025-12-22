<?php

namespace Drupal\ai_industry_insights;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered industry insights.
 */
class AiIndustryInsightsService extends AiReportServiceBase {

  /**
   * Temporary storage for user ID to pass to buildPrompt.
   *
   * @var int|null
   */
  protected $tempUid;

  /**
   * {@inheritdoc}
   */
  protected function getReportType(): string {
    return 'industry_insights';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_industry_insights';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['task_analysis'];
  }

  /**
   * Get the user's industry from their profile.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return string|null
   *   The industry name, or NULL if not set.
   */
  protected function getUserIndustry($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Load the user's member profile.
    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $uid,
      'type' => 'member',
      'status' => TRUE,
    ]);

    if (empty($profiles)) {
      return NULL;
    }

    $profile = reset($profiles);

    // Get the industry field value.
    if ($profile->hasField('field_industry') && !$profile->get('field_industry')->isEmpty()) {
      $industry_term = $profile->get('field_industry')->entity;
      if ($industry_term) {
        return $industry_term->getName();
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPrompt(array $submission_data): string {
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $role = $task_data['m2_q1_role_category'] ?? 'unknown';

    // Get the user's industry from their profile.
    $uid = $this->tempUid ?? $this->currentUser->id();
    $industry = $this->getUserIndustry($uid);

    // Build industry-specific prompt.
    if ($industry) {
      $industry_instruction = "Tailor ALL insights specifically to the {$industry} industry.";
      $overview_instruction = "1-2 sentences on AI adoption in the {$industry} industry";
    }
    else {
      $industry_instruction = "Provide general industry insights based on the role.";
      $overview_instruction = "1-2 sentences on AI adoption for this role";
    }

    return <<<PROMPT
Provide industry-specific AI impact insights. Be concise and current.

Role: {$role}
Industry: {$industry}

{$industry_instruction}

Respond with JSON:

{
  "industry_overview": {
    "current_state": "{$overview_instruction}",
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
  },
  "closing_message": {
    "title": "Concise, relevant title (2-5 words)",
    "message": "2-3 sentences that connect this specific industry context to actionable positioning. Be constructive and grounded, not cheesy or overly optimistic. Reference specific insights from this report."
  }
}

TONE RULES for closing_message:
- Be direct and professional
- Connect to specific industry/role insights from this report
- Focus on positioning and action, not motivation
- Avoid phrases like "exciting journey", "bright future", "endless possibilities"
- Use language like "positions you", "markets reward", "signals to act on"
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required = [
      'industry_overview',
      'role_evolution',
      'competitive_positioning',
      'emerging_trends',
      'strategic_recommendations',
      'closing_message',
    ];

    foreach ($required as $field) {
      if (!isset($response[$field])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_industry_insights.analysis_service';
  }

  /**
   * {@inheritdoc}
   *
   * Override to include user industry in the prompt context.
   */
  public function generateReport($uid = NULL, bool $force_regenerate = FALSE, bool $retry = TRUE) {
    // Store uid for use in buildPrompt.
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Create a temporary storage for uid to pass to buildPrompt.
    $this->tempUid = $uid;

    return parent::generateReport($uid, $force_regenerate, $retry);
  }

  /**
   * {@inheritdoc}
   *
   * Override to include user industry in the prompt context.
   */
  public function generateReportInBackground($uid, $entity_id) {
    // Store uid for use in buildPrompt.
    $this->tempUid = $uid;

    return parent::generateReportInBackground($uid, $entity_id);
  }

}
