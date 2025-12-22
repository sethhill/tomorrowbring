<?php

namespace Drupal\ai_career_transitions;

use Drupal\ai_report_storage\AiReportServiceBase;

/**
 * Service for AI-powered career transition analysis.
 */
class AiCareerTransitionsService extends AiReportServiceBase {

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
    return 'career_transitions';
  }

  /**
   * {@inheritdoc}
   */
  protected function getModuleName(): string {
    return 'ai_career_transitions';
  }

  /**
   * {@inheritdoc}
   */
  protected function getRequiredWebforms(): array {
    return ['task_analysis', 'skills_gap'];
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
    // Extract task analysis data.
    $task_data = $submission_data['task_analysis']['data'] ?? [];
    $skills_data = $submission_data['skills_gap']['data'] ?? [];

    $current_role = $task_data['m2_q1_role_category'] ?? 'unknown';
    $current_skills = $skills_data['m5_q2_current_skills'] ?? 'not specified';
    $desired_skills = $skills_data['m5_q3_desired_skills'] ?? 'not specified';

    // Get the user's industry from their profile.
    $uid = $this->tempUid ?? $this->currentUser->id();
    $industry = $this->getUserIndustry($uid);

    // Build industry-specific instructions.
    if ($industry) {
      $industry_instruction = "Focus on career opportunities specifically within or related to the {$industry} industry. Consider roles that are in high demand in {$industry} and where AI skills provide competitive advantage.";
      $industry_context = "Industry: {$industry}";
    }
    else {
      $industry_instruction = "Consider diverse career opportunities across industries where the user's skills transfer well.";
      $industry_context = "Industry: Not specified";
    }

    // Optional: Extract confidence data for tone calibration.
    $confidence_data = $submission_data['confidence']['data'] ?? [];
    $emotional_context = $this->buildEmotionalContext($confidence_data);
    $emotional_section = $emotional_context['section'];
    $tone_rules = $emotional_context['tone_rules'];

    return <<<PROMPT
Analyze career transition opportunities in the AI era. Be concise.

Current Role: {$current_role}
{$industry_context}
Current Skills: {$current_skills}
Desired Skills: {$desired_skills}

{$industry_instruction}

{$emotional_section}

{$tone_rules}

CRITICAL: Return ONLY valid JSON. No markdown, no explanations. Start with { and end with }.

Respond with JSON (limit to 3-4 viable transitions):

{
  "transition_readiness": {
    "overall_score": "1-100",
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
      "skills_to_acquire": [{"skill": "skill name"}],
      "timeline": "Realistic timeline (e.g., '6-12 months')",
      "first_steps": ["step 1", "step 2", "step 3"]
    }
  ],
  "closing_message": {
    "title": "2-5 words, relevant to career transition",
    "message": "2-3 sentences connecting their transition readiness to opportunity. Be constructive, not motivational. Reference specific transitions or strengths from this report."
  }
}

ENHANCED RULES:
- If confidence < 3: recommend longer timelines with more incremental steps
- If anxiety > 3: include "low-risk exploration" options (side projects, informational interviews) in first_steps
- If support_needed includes "mentor": emphasize mentorship opportunities in why_viable for each role
- Adjust transition_readiness.summary tone based on emotional state

TONE RULES for closing_message:
- Be direct and practical
- Connect their transferable skills to viable opportunities
- Focus on market positioning, not self-belief
- Avoid "follow your dreams", "you're ready", "unlimited potential"
- Use language like "positions you for", "markets value", "transitions favor"

Return ONLY JSON.
PROMPT;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateResponse(array $response): bool {
    $required_fields = [
      'transition_readiness',
      'viable_transitions',
      'closing_message',
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
   * {@inheritdoc}
   */
  protected function getServiceId(): string {
    return 'ai_career_transitions.analysis_service';
  }

  /**
   * {@inheritdoc}
   *
   * Override to include user industry in the prompt context.
   */
  public function generateReport($uid = NULL, bool $force_regenerate = FALSE, bool $retry = TRUE) {
    // Check minimum data first.
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    // Store uid for use in buildPrompt.
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    // Create a temporary storage for uid to pass to buildPrompt.
    $this->tempUid = $uid;

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

    // Optional: confidence for tone calibration.
    $confidence_result = $this->getSubmissionData('confidence', $uid);
    if ($confidence_result) {
      $submission_data['confidence'] = $confidence_result;
      $submission_ids[] = $confidence_result['sid'];
    }

    // Check if we should regenerate based on source data hash.
    if (!$force_regenerate && $latest_entity = $this->loadLatestReport($uid)) {
      $current_hash = $this->getSourceDataHash($submission_data);
      if ($latest_entity->getSourceDataHash() === $current_hash) {
        // Source data hasn't changed, return existing report.
        $report_data = $latest_entity->getReportData();
        $report_data['generated_at'] = $latest_entity->getGeneratedAt();
        $report_data['uid'] = $uid;
        $report_data['generation_time'] = $latest_entity->getGenerationTime();
        $report_data['version'] = $latest_entity->getVersion();

        $this->setCachedReport($uid, $report_data);
        return $report_data;
      }
    }

    // Generate the report.
    return $this->generateReportFromData($uid, $submission_data, $submission_ids, $retry);
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
