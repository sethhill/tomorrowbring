<?php

namespace Drupal\role_impact_analysis;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\role_impact_analysis\Service\AiAnalysisService;

/**
 * Role Impact Analysis Service.
 *
 * Analyzes user assessment responses to generate personalized
 * role impact reports based on the Role Impact Analysis Framework.
 */
class RoleImpactAnalysis {

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
   * The AI analysis service.
   *
   * @var \Drupal\role_impact_analysis\Service\AiAnalysisService
   */
  protected $aiService;

  /**
   * Constructs a RoleImpactAnalysis object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\client_webform\WebformClientManager $client_manager
   *   The webform client manager service.
   * @param \Drupal\role_impact_analysis\Service\AiAnalysisService $ai_service
   *   The AI analysis service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user, WebformClientManager $client_manager, AiAnalysisService $ai_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->clientManager = $client_manager;
    $this->aiService = $ai_service;
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
      /** @var \Drupal\webform\WebformSubmissionInterface $submission */
      return $submission->getData();
    }
    return NULL;
  }

  /**
   * Get all completed module submissions for a user.
   *
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return array
   *   Array of submission data keyed by webform ID.
   */
  public function getAllSubmissions($uid = NULL) {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $submissions = [];
    $enabled_webforms = $this->clientManager->getEnabledWebforms();

    foreach ($enabled_webforms as $webform_id) {
      $data = $this->getSubmissionData($webform_id, $uid);
      if ($data) {
        $submissions[$webform_id] = $data;
      }
    }

    return $submissions;
  }

  /**
   * Check if user has completed minimum required modules for analysis.
   *
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return bool
   *   TRUE if user has completed minimum modules, FALSE otherwise.
   */
  public function hasMinimumData($uid = NULL) {
    // Minimum required: Module 2 (Task Analysis) and Module 5 (Skills Gap)
    $required_modules = ['task_analysis', 'skills_gap'];

    foreach ($required_modules as $webform_id) {
      $data = $this->getSubmissionData($webform_id, $uid);
      if (!$data) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Calculate task displacement risk score.
   *
   * @param array $task_analysis_data
   *   Data from Module 2 (Task Analysis).
   *
   * @return array
   *   Array with risk_score, help_me_count, keep_it_count, unsure_count, and total_tasks.
   */
  public function calculateTaskDisplacementRisk(array $task_analysis_data) {
    $help_me = 0;
    $keep_it = 0;
    $unsure = 0;

    // Count task preferences
    foreach ($task_analysis_data as $key => $value) {
      // Look for task response fields (m2_q2_*)
      if (strpos($key, 'm2_q2_') === 0 && $value) {
        if ($value === 'help') {
          $help_me++;
        }
        elseif ($value === 'keep') {
          $keep_it++;
        }
        elseif ($value === 'unsure') {
          $unsure++;
        }
      }
    }

    $total_tasks = $help_me + $keep_it + $unsure;

    if ($total_tasks === 0) {
      return [
        'risk_score' => 0,
        'help_me_count' => 0,
        'keep_it_count' => 0,
        'unsure_count' => 0,
        'total_tasks' => 0,
        'help_me_percentage' => 0,
        'keep_it_percentage' => 0,
        'unsure_percentage' => 0,
      ];
    }

    return [
      'risk_score' => round(($help_me / $total_tasks) * 100),
      'help_me_count' => $help_me,
      'keep_it_count' => $keep_it,
      'unsure_count' => $unsure,
      'total_tasks' => $total_tasks,
      'help_me_percentage' => round(($help_me / $total_tasks) * 100),
      'keep_it_percentage' => round(($keep_it / $total_tasks) * 100),
      'unsure_percentage' => round(($unsure / $total_tasks) * 100),
    ];
  }

  /**
   * Get detailed task breakdown by category.
   *
   * @param array $task_analysis_data
   *   Data from Module 2 (Task Analysis).
   *
   * @return array
   *   Array of tasks organized by preference (help, keep, unsure).
   */
  public function getTaskBreakdown(array $task_analysis_data) {
    $tasks = [
      'help' => [],
      'keep' => [],
      'unsure' => [],
    ];

    // Task labels mapping
    $task_labels = $this->getTaskLabels();

    foreach ($task_analysis_data as $key => $value) {
      if (strpos($key, 'm2_q2_') === 0 && $value && isset($task_labels[$key])) {
        if (in_array($value, ['help', 'keep', 'unsure'])) {
          $tasks[$value][] = [
            'key' => $key,
            'label' => $task_labels[$key],
            'category' => $this->getTaskCategory($key),
            'automation_potential' => $this->getAutomationPotential($key, $value),
          ];
        }
      }
    }

    return $tasks;
  }

  /**
   * Get task labels mapping.
   *
   * @return array
   *   Array of task keys to human-readable labels.
   */
  protected function getTaskLabels() {
    return [
      // Sales tasks
      'm2_q2_sales_followup' => 'Writing follow-up emails',
      'm2_q2_sales_research' => 'Researching prospects/accounts',
      'm2_q2_sales_scheduling' => 'Scheduling meetings',
      'm2_q2_sales_relationships' => 'Building relationships with clients',
      'm2_q2_sales_proposals' => 'Creating proposals/quotes',
      'm2_q2_sales_crm' => 'Entering data into CRM',
      'm2_q2_sales_negotiations' => 'Having complex negotiations',
      'm2_q2_sales_analysis' => 'Analyzing sales data',

      // Engineering tasks
      'm2_q2_eng_boilerplate' => 'Writing boilerplate code',
      'm2_q2_eng_debugging' => 'Debugging errors',
      'm2_q2_eng_docs' => 'Writing documentation',
      'm2_q2_eng_reviews' => 'Code reviews',
      'm2_q2_eng_architecture' => 'System architecture decisions',
      'm2_q2_eng_learning' => 'Learning new frameworks',
      'm2_q2_eng_tests' => 'Writing tests',
      'm2_q2_eng_mentoring' => 'Mentoring junior developers',

      // Marketing tasks
      'm2_q2_mkt_brainstorm' => 'Brainstorming campaign ideas',
      'm2_q2_mkt_social' => 'Writing social media posts',
      'm2_q2_mkt_design' => 'Designing visual assets',
      'm2_q2_mkt_analysis' => 'Analyzing campaign performance',
      'm2_q2_mkt_strategy' => 'Creating brand strategy',
      'm2_q2_mkt_ab' => 'A/B test analysis',
      'm2_q2_mkt_presentations' => 'Client presentations',
      'm2_q2_mkt_calendar' => 'Content calendar planning',

      // Operations tasks
      'm2_q2_ops_data_entry' => 'Data entry',
      'm2_q2_ops_scheduling' => 'Scheduling / calendar management',
      'm2_q2_ops_email' => 'Email management',
      'm2_q2_ops_reports' => 'Report generation',
      'm2_q2_ops_docs' => 'Process documentation',
      'm2_q2_ops_vendors' => 'Vendor coordination',
      'm2_q2_ops_meetings' => 'Meeting coordination',
      'm2_q2_ops_edge_cases' => 'Problem-solving edge cases',

      // Management tasks
      'm2_q2_mgmt_reviews' => 'Performance review writing',
      'm2_q2_mgmt_communication' => 'Team communication',
      'm2_q2_mgmt_planning' => 'Strategic planning',
      'm2_q2_mgmt_budget' => 'Budget analysis',
      'm2_q2_mgmt_coaching' => 'One-on-one coaching',
      'm2_q2_mgmt_hiring' => 'Hiring decisions',
      'm2_q2_mgmt_conflict' => 'Conflict resolution',
      'm2_q2_mgmt_vision' => 'Vision setting',

      // Finance tasks
      'm2_q2_fin_cleaning' => 'Data cleaning / preparation',
      'm2_q2_fin_models' => 'Building financial models',
      'm2_q2_fin_viz' => 'Creating visualizations',
      'm2_q2_fin_trends' => 'Identifying trends/patterns',
      'm2_q2_fin_forecasting' => 'Forecasting',
      'm2_q2_fin_reconciliation' => 'Reconciliation tasks',
      'm2_q2_fin_recommendations' => 'Strategic recommendations',
      'm2_q2_fin_presentations' => 'Stakeholder presentations',

      // HR tasks
      'm2_q2_hr_screening' => 'Resume screening',
      'm2_q2_hr_scheduling' => 'Interview scheduling',
      'm2_q2_hr_onboarding' => 'Onboarding documentation',
      'm2_q2_hr_benefits' => 'Benefits administration',
      'm2_q2_hr_relations' => 'Employee relations / sensitive issues',
      'm2_q2_hr_training' => 'Training program design',
      'm2_q2_hr_performance' => 'Performance management',
      'm2_q2_hr_culture' => 'Culture building',
    ];
  }

  /**
   * Get task category from task key.
   *
   * @param string $task_key
   *   The task key (e.g., 'm2_q2_sales_followup').
   *
   * @return string
   *   The category (sales, engineering, marketing, etc.).
   */
  protected function getTaskCategory($task_key) {
    if (strpos($task_key, 'sales_') !== FALSE) {
      return 'sales';
    }
    elseif (strpos($task_key, 'eng_') !== FALSE) {
      return 'engineering';
    }
    elseif (strpos($task_key, 'mkt_') !== FALSE) {
      return 'marketing';
    }
    elseif (strpos($task_key, 'ops_') !== FALSE) {
      return 'operations';
    }
    elseif (strpos($task_key, 'mgmt_') !== FALSE) {
      return 'management';
    }
    elseif (strpos($task_key, 'fin_') !== FALSE) {
      return 'finance';
    }
    elseif (strpos($task_key, 'hr_') !== FALSE) {
      return 'hr';
    }
    return 'other';
  }

  /**
   * Determine automation potential for a task.
   *
   * @param string $task_key
   *   The task key.
   * @param string $user_preference
   *   User's preference (help, keep, unsure).
   *
   * @return string
   *   Automation potential level (immediate, high, medium, low).
   */
  protected function getAutomationPotential($task_key, $user_preference) {
    // Define high-automation tasks
    $immediate_automation = [
      'm2_q2_sales_crm',
      'm2_q2_sales_scheduling',
      'm2_q2_eng_boilerplate',
      'm2_q2_ops_data_entry',
      'm2_q2_ops_scheduling',
      'm2_q2_ops_email',
      'm2_q2_ops_reports',
      'm2_q2_fin_cleaning',
      'm2_q2_fin_reconciliation',
      'm2_q2_hr_screening',
      'm2_q2_hr_scheduling',
    ];

    $ai_assisted_tasks = [
      'm2_q2_sales_followup',
      'm2_q2_sales_research',
      'm2_q2_sales_proposals',
      'm2_q2_eng_debugging',
      'm2_q2_eng_docs',
      'm2_q2_eng_tests',
      'm2_q2_mkt_social',
      'm2_q2_mkt_analysis',
      'm2_q2_mkt_ab',
      'm2_q2_mkt_calendar',
      'm2_q2_ops_docs',
      'm2_q2_mgmt_reviews',
      'm2_q2_fin_models',
      'm2_q2_fin_viz',
      'm2_q2_fin_trends',
      'm2_q2_hr_onboarding',
    ];

    if ($user_preference === 'help') {
      if (in_array($task_key, $immediate_automation)) {
        return 'immediate';
      }
      elseif (in_array($task_key, $ai_assisted_tasks)) {
        return 'high';
      }
      else {
        return 'medium';
      }
    }
    elseif ($user_preference === 'keep') {
      return 'low';
    }
    else {
      // Unsure
      return 'medium';
    }
  }

  /**
   * Calculate comprehensive risk level.
   *
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return array
   *   Array with risk_level, risk_score, and contributing factors.
   */
  public function calculateComprehensiveRisk($uid = NULL) {
    $submissions = $this->getAllSubmissions($uid);

    // Task displacement (40% weight)
    $task_data = $submissions['task_analysis'] ?? [];
    $task_risk = $this->calculateTaskDisplacementRisk($task_data);
    $task_score = $task_risk['help_me_percentage'] * 0.4;

    // Current AI skill level (20% weight)
    $skills_data = $submissions['skills_gap'] ?? [];
    $skill_level = $skills_data['m5_q1_skill_level'] ?? 'never_used';
    $skill_scores = [
      'never_used' => 100,
      'beginner' => 75,
      'intermediate' => 40,
      'advanced' => 20,
      'expert' => 0,
    ];
    $skill_score = ($skill_scores[$skill_level] ?? 100) * 0.2;

    // Future skill confidence (20% weight) - based on skills gap
    $has_current_skills = !empty($skills_data['m5_q2_current_skills']) &&
                          !in_array('none', $skills_data['m5_q2_current_skills']);
    $confidence_score = ($has_current_skills ? 30 : 80) * 0.2;

    // Anxiety/threat perception (10% weight)
    // This would come from Module 7 if available
    $anxiety_score = 50 * 0.1; // Default middle value

    // Total risk score
    $total_risk = $task_score + $skill_score + $confidence_score + $anxiety_score;

    if ($total_risk > 70) {
      $risk_level = 'high';
      $urgency = 'immediate';
    }
    elseif ($total_risk >= 40) {
      $risk_level = 'medium';
      $urgency = 'proactive';
    }
    else {
      $risk_level = 'low';
      $urgency = 'strategic';
    }

    return [
      'risk_level' => $risk_level,
      'risk_score' => round($total_risk),
      'urgency' => $urgency,
      'task_component' => round($task_score / 0.4),
      'skill_component' => round($skill_score / 0.2),
      'confidence_component' => round($confidence_score / 0.2),
      'task_risk_details' => $task_risk,
    ];
  }

  /**
   * Analyze skill value trajectory.
   *
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return array
   *   Array with increasing, decreasing, and emerging skills.
   */
  public function analyzeSkillTrajectory($uid = NULL) {
    $submissions = $this->getAllSubmissions($uid);
    $task_data = $submissions['task_analysis'] ?? [];
    $skills_data = $submissions['skills_gap'] ?? [];

    $tasks = $this->getTaskBreakdown($task_data);
    $current_skills = $skills_data['m5_q2_current_skills'] ?? [];
    $desired_skills = $skills_data['m5_q3_want_to_develop'] ?? [];

    // Map skills to task categories
    $skill_mapping = [
      'prompts' => ['all'],
      'tool_selection' => ['all'],
      'evaluation' => ['management', 'finance', 'engineering'],
      'integration' => ['all'],
      'limitations' => ['all'],
      'strategic_thinking' => ['management'],
      'data_analysis' => ['finance', 'marketing'],
      'creative_applications' => ['marketing'],
      'technical_skills' => ['engineering'],
      'relationship_building' => ['sales', 'hr', 'management'],
    ];

    $increasing_value = [];
    $decreasing_value = [];
    $emerging_value = [];

    // Increasing value: Skills associated with "Keep it" tasks
    if (!empty($tasks['keep'])) {
      foreach ($tasks['keep'] as $task) {
        $category = $task['category'];
        $skill_label = $this->getSkillForTask($task['key']);
        if ($skill_label && !in_array($skill_label, $increasing_value)) {
          $increasing_value[] = [
            'skill' => $skill_label,
            'reason' => "Essential for {$task['label']}",
            'action' => 'Continue developing and documenting your expertise',
          ];
        }
      }
    }

    // Add relationship and judgment skills as increasing value
    if (in_array('evaluation', $current_skills)) {
      $increasing_value[] = [
        'skill' => 'Critical evaluation and judgment',
        'reason' => 'AI requires human oversight and decision-making',
        'action' => 'Position yourself as the quality control expert',
      ];
    }

    // Decreasing value: Skills only used for "Help me" tasks
    $help_me_categories = [];
    if (!empty($tasks['help'])) {
      foreach ($tasks['help'] as $task) {
        $help_me_categories[] = $task['category'];
      }
    }

    // Emerging value: Skills user wants to learn
    $skill_labels = [
      'prompt_engineering' => 'Prompt engineering',
      'accuracy_evaluation' => 'AI accuracy evaluation',
      'data_analysis' => 'Data analysis with AI',
      'automation' => 'Task automation',
      'creative_applications' => 'Creative AI applications',
      'how_it_works' => 'AI fundamentals',
      'ethical_use' => 'Ethical AI use',
      'training_others' => 'Training and leadership',
      'custom_solutions' => 'Custom AI solutions',
    ];

    foreach ($desired_skills as $skill_key) {
      if (isset($skill_labels[$skill_key])) {
        $emerging_value[] = [
          'skill' => $skill_labels[$skill_key],
          'reason' => $this->getSkillValueReason($skill_key),
          'action' => $this->getSkillDevelopmentAction($skill_key, $skills_data),
        ];
      }
    }

    return [
      'increasing_value' => $increasing_value,
      'decreasing_value' => $decreasing_value,
      'emerging_value' => $emerging_value,
    ];
  }

  /**
   * Get skill associated with a task.
   *
   * @param string $task_key
   *   The task key.
   *
   * @return string|null
   *   The skill label or NULL.
   */
  protected function getSkillForTask($task_key) {
    $mapping = [
      'm2_q2_sales_relationships' => 'Relationship building',
      'm2_q2_sales_negotiations' => 'Negotiation and persuasion',
      'm2_q2_eng_architecture' => 'System design and architecture',
      'm2_q2_eng_reviews' => 'Code quality assessment',
      'm2_q2_eng_mentoring' => 'Mentoring and knowledge transfer',
      'm2_q2_mkt_strategy' => 'Strategic brand thinking',
      'm2_q2_ops_edge_cases' => 'Problem-solving and critical thinking',
      'm2_q2_ops_vendors' => 'Vendor relationship management',
      'm2_q2_mgmt_coaching' => 'Coaching and people development',
      'm2_q2_mgmt_hiring' => 'Talent assessment',
      'm2_q2_mgmt_conflict' => 'Conflict resolution',
      'm2_q2_mgmt_vision' => 'Vision and strategy',
      'm2_q2_fin_recommendations' => 'Strategic financial analysis',
      'm2_q2_hr_relations' => 'Employee relations and empathy',
      'm2_q2_hr_culture' => 'Culture building',
    ];

    return $mapping[$task_key] ?? NULL;
  }

  /**
   * Get skill value reason.
   *
   * @param string $skill_key
   *   The skill key.
   *
   * @return string
   *   The reason this skill is valuable.
   */
  protected function getSkillValueReason($skill_key) {
    $reasons = [
      'prompt_engineering' => 'Essential for getting quality AI outputs',
      'accuracy_evaluation' => 'Critical for maintaining quality and trust',
      'data_analysis' => 'High-value skill for data-driven decision making',
      'automation' => 'Multiplies your productivity and frees time for strategic work',
      'creative_applications' => 'Differentiates you from competitors',
      'how_it_works' => 'Understanding fundamentals helps you use AI more effectively',
      'ethical_use' => 'Increasingly important as AI adoption grows',
      'training_others' => 'Leadership skill that positions you as an expert',
      'custom_solutions' => 'Enables you to solve unique problems in your domain',
    ];

    return $reasons[$skill_key] ?? 'Valuable for your role evolution';
  }

  /**
   * Get skill development action.
   *
   * @param string $skill_key
   *   The skill key.
   * @param array $skills_data
   *   The skills gap data.
   *
   * @return string
   *   Recommended action.
   */
  protected function getSkillDevelopmentAction($skill_key, array $skills_data) {
    $learning_style = $skills_data['m5_q4_learning_style'] ?? [];
    $time_available = $skills_data['m5_q5_time_available'] ?? 'less_1';

    // Customize based on learning style and time
    if (in_array('hands_on', $learning_style) || in_array($time_available, ['less_1', 'no_time'])) {
      return 'Start with 15-minute daily practice using AI tools in your actual work';
    }
    elseif (in_array('video', $learning_style)) {
      return 'Complete a short video course focused on this skill';
    }
    elseif (in_array('workshops', $learning_style)) {
      return 'Attend a live workshop or training session';
    }
    else {
      return 'Follow a self-paced learning plan aligned to your schedule';
    }
  }

  /**
   * Determine role evolution pathway.
   *
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return array
   *   Array with path name, description, and guidance.
   */
  public function determineEvolutionPath($uid = NULL) {
    $submissions = $this->getAllSubmissions($uid);
    $task_data = $submissions['task_analysis'] ?? [];
    $skills_data = $submissions['skills_gap'] ?? [];
    $current_ai_data = $submissions['current_ai_usage'] ?? [];

    $tasks = $this->getTaskBreakdown($task_data);
    $role_category = $task_data['m2_q1_role_category'] ?? 'other';
    $skill_level = $skills_data['m5_q1_skill_level'] ?? 'never_used';
    $desired_skills = $skills_data['m5_q3_want_to_develop'] ?? [];

    $help_count = count($tasks['help']);
    $keep_count = count($tasks['keep']);
    $total = $help_count + $keep_count + count($tasks['unsure']);

    $help_percentage = $total > 0 ? ($help_count / $total) * 100 : 0;
    $keep_percentage = $total > 0 ? ($keep_count / $total) * 100 : 0;

    // Decision tree for path assignment
    // PATH A: SPECIALIST → STRATEGIC SPECIALIST
    if ($keep_percentage > 50 && in_array($role_category, ['management', 'engineering', 'finance'])) {
      $keep_strategic_tasks = array_filter($tasks['keep'], function ($task) {
        return in_array($task['key'], [
          'm2_q2_mgmt_planning',
          'm2_q2_mgmt_vision',
          'm2_q2_eng_architecture',
          'm2_q2_eng_reviews',
          'm2_q2_fin_recommendations',
        ]);
      });

      if (!empty($keep_strategic_tasks)) {
        return [
          'path' => 'Specialist → Strategic Specialist',
          'from' => 'Executing specialized work',
          'to' => 'Providing strategic direction while AI handles execution',
          'description' => "Your role is evolving from executing specialized work to providing strategic direction while AI handles execution. Your deep expertise becomes MORE valuable, not less.",
          'positioning' => [
            'You understand trade-offs and long-term implications that AI cannot assess',
            'Your judgment is refined by years of experience',
            'You can evaluate AI outputs for quality and strategic fit',
          ],
          'obstacles' => [
            'Letting go of execution work you enjoy',
            'Proving strategic value to leadership',
          ],
          'actions' => [
            'Document your decision-making framework',
            'Volunteer for one strategic initiative this quarter',
            'Mentor others while using AI to handle routine analysis',
          ],
        ];
      }
    }

    // PATH B: GENERALIST → AI-AUGMENTED SPECIALIST
    if ($help_percentage > 40 && $keep_percentage > 20 && in_array($skill_level, ['beginner', 'intermediate'])) {
      if (in_array('automation', $desired_skills) || in_array('creative_applications', $desired_skills)) {
        return [
          'path' => 'Generalist → AI-Augmented Specialist',
          'from' => 'Handling variety of tasks manually',
          'to' => 'Orchestrating AI tools to multiply your impact',
          'description' => "Your breadth of experience positions you to become a specialist in AI-augmented workflows. You understand the full process and can orchestrate AI tools effectively.",
          'positioning' => [
            'You see connections across different work streams',
            'You can design workflows that combine AI and human judgment',
            'Your versatility helps you adapt quickly to new AI capabilities',
          ],
          'obstacles' => [
            'Learning curve for new AI tools',
            'Resistance from colleagues who prefer old methods',
          ],
          'actions' => [
            'Choose ONE workflow to fully automate with AI this month',
            'Document your AI-augmented process for others',
            'Become the go-to person for AI integration in your team',
          ],
        ];
      }
    }

    // PATH C: EXECUTOR → RELATIONSHIP BUILDER
    if ($help_percentage > 60) {
      $relationship_tasks = array_filter($tasks['keep'], function ($task) {
        return in_array($task['key'], [
          'm2_q2_sales_relationships',
          'm2_q2_sales_negotiations',
          'm2_q2_mgmt_coaching',
          'm2_q2_hr_relations',
          'm2_q2_ops_vendors',
        ]);
      });

      if (!empty($relationship_tasks)) {
        return [
          'path' => 'Executor → Relationship Builder',
          'from' => 'Executing administrative and operational tasks',
          'to' => 'Building relationships and navigating complexity',
          'description' => "As AI handles execution, your value shifts to the human elements: building trust, understanding needs, navigating complexity. These skills are irreplaceable.",
          'positioning' => [
            'You understand people and organizational dynamics',
            'You build trust and credibility through relationships',
            'You navigate ambiguity and edge cases AI cannot handle',
          ],
          'obstacles' => [
            'Fear that relationship skills aren\'t valued',
            'Need to make relationship impact visible',
          ],
          'actions' => [
            'Use AI for task execution, invest saved time in relationship building',
            'Document the business value of your key relationships',
            'Position yourself as the connector and problem solver',
          ],
        ];
      }
    }

    // PATH D: INDIVIDUAL CONTRIBUTOR → ORCHESTRATOR
    if (in_array($skill_level, ['intermediate', 'advanced', 'expert'])) {
      if (in_array('training_others', $desired_skills)) {
        return [
          'path' => 'Individual Contributor → Orchestrator',
          'from' => 'Completing tasks individually',
          'to' => 'Managing AI-human hybrid workflows and training others',
          'description' => "Your future is managing AI-human hybrid workflows. You become the conductor, orchestrating both AI capabilities and human expertise.",
          'positioning' => [
            'You can evaluate what AI does well vs. what needs human judgment',
            'You understand workflow design and optimization',
            'You can train others to work effectively with AI',
          ],
          'obstacles' => [
            'Shifting identity from doer to enabler',
            'Building teaching and leadership skills',
          ],
          'actions' => [
            'Lead one AI adoption project in your team',
            'Create templates and guides for AI-augmented work',
            'Schedule training sessions to share your AI expertise',
          ],
        ];
      }
    }

    // PATH E: SPECIALIST → HYBRID INNOVATOR
    if (in_array($skill_level, ['advanced', 'expert']) && $keep_percentage > 40) {
      if (in_array('custom_solutions', $desired_skills) || in_array('creative_applications', $desired_skills)) {
        return [
          'path' => 'Specialist → Hybrid Innovator',
          'from' => 'Deep domain expertise',
          'to' => 'Innovating at the intersection of domain and AI',
          'description' => "You're positioned to innovate at the intersection of your domain and AI. You can identify novel applications others miss.",
          'positioning' => [
            'You have both domain expertise and AI capabilities',
            'You see opportunities for AI that others overlook',
            'You can prototype solutions and prove value',
          ],
          'obstacles' => [
            'Balancing innovation time with day-to-day work',
            'Getting buy-in for experimental projects',
          ],
          'actions' => [
            'Identify one unique AI application in your domain',
            'Build a prototype or proof of concept',
            'Share your innovations internally and externally',
          ],
        ];
      }
    }

    // DEFAULT: ADAPTIVE PROFESSIONAL
    return [
      'path' => 'Adaptive Professional',
      'from' => 'Current role and responsibilities',
      'to' => 'Agile professional who adapts to AI-augmented work',
      'description' => "Your path is about building versatility and agility. As AI capabilities evolve, you'll adapt your skillset and find your unique value proposition.",
      'positioning' => [
        'You are willing to learn and experiment',
        'You stay current with AI developments in your field',
        'You are building a portfolio of AI-augmented skills',
      ],
      'obstacles' => [
        'Uncertainty about which direction to focus',
        'Keeping up with rapid AI changes',
      ],
      'actions' => [
        'Experiment with AI tools for 30 minutes daily',
        'Join communities focused on AI in your industry',
        'Reassess your path every 6 months as AI evolves',
      ],
    ];
  }

  /**
   * Generate comprehensive role impact analysis report.
   *
   * @param int $uid
   *   The user ID (optional, defaults to current user).
   *
   * @return array|null
   *   Complete analysis report data or NULL if insufficient data.
   */
  public function generateReport($uid = NULL) {
    if (!$this->hasMinimumData($uid)) {
      return NULL;
    }

    $submissions = $this->getAllSubmissions($uid);
    $task_data = $submissions['task_analysis'] ?? [];
    $skills_data = $submissions['skills_gap'] ?? [];
    $current_ai_data = $submissions['current_ai_usage'] ?? [];

    // Section 1: Current State
    $role_category = $task_data['m2_q1_role_category'] ?? 'other';
    $role_other = $task_data['m2_q1_other'] ?? '';
    $ai_frequency = $current_ai_data['m1_q2_frequency'] ?? 'never';
    $ai_comfort = $current_ai_data['m1_q3_comfort'] ?? '1';
    $ai_tools = $current_ai_data['m1_q1_tools_used'] ?? [];

    // Section 2: Risk Analysis
    $risk_analysis = $this->calculateComprehensiveRisk($uid);
    $task_breakdown = $this->getTaskBreakdown($task_data);

    // Section 3: Skill Analysis
    $skill_trajectory = $this->analyzeSkillTrajectory($uid);

    // Section 4: Evolution Path
    $evolution_path = $this->determineEvolutionPath($uid);

    // Section 5: Value Proposition
    $value_proposition = $this->generateValueProposition($task_breakdown, $skill_trajectory, $evolution_path);

    // Section 6: Action Plan
    $action_plan = $this->generateActionPlan($risk_analysis, $skills_data, $task_breakdown);

    // Section 7: Learning Path
    $learning_path = $this->generateLearningPath($skills_data, $skill_trajectory);

    // Build rule-based report data.
    $report = [
      'current_state' => [
        'role_category' => $role_category,
        'role_other' => $role_other,
        'ai_frequency' => $ai_frequency,
        'ai_comfort' => $ai_comfort,
        'ai_tools' => $ai_tools,
        'task_profile' => [
          'help_me_percentage' => $risk_analysis['task_risk_details']['help_me_percentage'],
          'keep_it_percentage' => $risk_analysis['task_risk_details']['keep_it_percentage'],
          'unsure_percentage' => $risk_analysis['task_risk_details']['unsure_percentage'],
        ],
      ],
      'displacement_risk' => [
        'risk_level' => $risk_analysis['risk_level'],
        'risk_score' => $risk_analysis['risk_score'],
        'urgency' => $risk_analysis['urgency'],
        'task_breakdown' => $task_breakdown,
      ],
      'skill_evolution' => $skill_trajectory,
      'evolution_path' => $evolution_path,
      'value_proposition' => $value_proposition,
      'action_plan' => $action_plan,
      'learning_path' => $learning_path,
      'generated_at' => time(),
    ];

    // Generate AI-powered insights.
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }
    $ai_insights = $this->aiService->generateAiInsights($report, $submissions, $uid);

    // Add AI insights to report if available.
    if ($ai_insights !== NULL) {
      $report['ai_insights'] = $ai_insights;
    }

    return $report;
  }

  /**
   * Generate value proposition statement.
   *
   * @param array $task_breakdown
   *   Task breakdown data.
   * @param array $skill_trajectory
   *   Skill trajectory data.
   * @param array $evolution_path
   *   Evolution path data.
   *
   * @return array
   *   Value proposition components.
   */
  protected function generateValueProposition(array $task_breakdown, array $skill_trajectory, array $evolution_path) {
    $irreplaceable_skills = [];
    $ai_multipliers = [];
    $unique_context = [];

    // Extract irreplaceable skills from "Keep it" tasks
    if (!empty($task_breakdown['keep'])) {
      foreach ($task_breakdown['keep'] as $task) {
        if ($task['automation_potential'] === 'low') {
          $irreplaceable_skills[] = [
            'task' => $task['label'],
            'skill' => $this->getSkillForTask($task['key']) ?? 'Professional judgment',
          ];
        }
      }
    }

    // AI multipliers from increasing value skills
    if (!empty($skill_trajectory['increasing_value'])) {
      foreach ($skill_trajectory['increasing_value'] as $skill) {
        $ai_multipliers[] = $skill['skill'];
      }
    }

    // Unique context from evolution path positioning
    $unique_context = $evolution_path['positioning'] ?? [];

    return [
      'irreplaceable_skills' => array_slice($irreplaceable_skills, 0, 3),
      'ai_multipliers' => array_slice($ai_multipliers, 0, 3),
      'unique_context' => $unique_context,
      'summary' => $this->formatValuePropositionSummary($irreplaceable_skills, $ai_multipliers, $unique_context),
    ];
  }

  /**
   * Format value proposition summary.
   *
   * @param array $irreplaceable
   *   Irreplaceable skills.
   * @param array $multipliers
   *   AI multipliers.
   * @param array $context
   *   Unique context.
   *
   * @return string
   *   Formatted summary.
   */
  protected function formatValuePropositionSummary(array $irreplaceable, array $multipliers, array $context) {
    $parts = [];

    if (!empty($irreplaceable)) {
      $first = reset($irreplaceable);
      $parts[] = "Your expertise in " . strtolower($first['skill']) . " provides irreplaceable value that AI cannot replicate";
    }

    if (!empty($multipliers)) {
      $items = array_map('strtolower', array_slice($multipliers, 0, 2));
      $last = array_pop($items);
      $list = empty($items) ? $last : implode(', ', $items) . ' and ' . $last;
      $parts[] = "You can leverage AI to amplify your capabilities in " . $list;
    }

    if (!empty($context)) {
      $parts[] = reset($context);
    }

    return implode('. ', $parts) . '.';
  }

  /**
   * Generate action plan based on risk level.
   *
   * @param array $risk_analysis
   *   Risk analysis data.
   * @param array $skills_data
   *   Skills gap data.
   * @param array $task_breakdown
   *   Task breakdown data.
   *
   * @return array
   *   Time-bound action plan.
   */
  protected function generateActionPlan(array $risk_analysis, array $skills_data, array $task_breakdown) {
    $plan = [
      'immediate' => [],
      'thirty_day' => [],
      'ninety_day' => [],
      'six_month' => [],
    ];

    $urgency = $risk_analysis['urgency'];

    // Immediate actions (This Week)
    if ($urgency === 'immediate') {
      if (!empty($task_breakdown['help']) && count($task_breakdown['help']) > 0) {
        $first_help_task = reset($task_breakdown['help']);
        $plan['immediate'][] = "Identify ONE AI tool to help with: {$first_help_task['label']}";
      }

      $skill_level = $skills_data['m5_q1_skill_level'] ?? 'never_used';
      if (in_array($skill_level, ['never_used', 'beginner'])) {
        $plan['immediate'][] = "Spend 15 minutes today trying ChatGPT or similar AI tool";
      }

      if (!empty($task_breakdown['keep'])) {
        $plan['immediate'][] = "Document your unique knowledge and expertise that isn't written down anywhere";
      }
    }
    else {
      $plan['immediate'][] = "Review this analysis and identify your top priority area";
      $plan['immediate'][] = "Schedule time to experiment with AI tools";
    }

    // 30-day actions
    if (!empty($task_breakdown['help']) && count($task_breakdown['help']) > 0) {
      $automatable_tasks = array_filter($task_breakdown['help'], function ($task) {
        return $task['automation_potential'] === 'immediate';
      });

      if (!empty($automatable_tasks)) {
        $task = reset($automatable_tasks);
        $plan['thirty_day'][] = "Automate or AI-assist: {$task['label']}";
      }
    }

    $desired_skills = $skills_data['m5_q3_want_to_develop'] ?? [];
    if (!empty($desired_skills)) {
      $first_skill = reset($desired_skills);
      $skill_labels = [
        'prompt_engineering' => 'prompt engineering',
        'accuracy_evaluation' => 'AI evaluation',
        'automation' => 'task automation',
      ];
      $skill_name = $skill_labels[$first_skill] ?? 'AI skills';
      $plan['thirty_day'][] = "Complete one training or practice session on {$skill_name}";
    }

    if (!empty($task_breakdown['keep'])) {
      $keep_task = reset($task_breakdown['keep']);
      $plan['thirty_day'][] = "Strengthen your expertise in: {$keep_task['label']}";
    }

    // 90-day goals
    $plan['ninety_day'][] = "Establish a consistent AI-augmented workflow for daily tasks";
    $plan['ninety_day'][] = "Measure time saved and productivity gains from AI tools";

    if ($urgency === 'immediate') {
      $plan['ninety_day'][] = "Have conversation with manager about role evolution and AI strategy";
    }
    else {
      $plan['ninety_day'][] = "Share your AI successes with team or colleagues";
    }

    // 6-month strategy
    $plan['six_month'][] = "Transition toward your identified evolution path";
    $plan['six_month'][] = "Build reputation as an AI-savvy professional in your domain";
    $plan['six_month'][] = "Reassess your skills and role positioning";

    return $plan;
  }

  /**
   * Generate personalized learning path.
   *
   * @param array $skills_data
   *   Skills gap data.
   * @param array $skill_trajectory
   *   Skill trajectory data.
   *
   * @return array
   *   Learning path recommendations.
   */
  protected function generateLearningPath(array $skills_data, array $skill_trajectory) {
    $learning_styles = $skills_data['m5_q4_learning_style'] ?? [];
    $time_available = $skills_data['m5_q5_time_available'] ?? 'less_1';
    $barrier = $skills_data['m5_q6_biggest_barrier'] ?? 'no_time';

    $path = [
      'learning_profile' => [
        'preferred_styles' => $learning_styles,
        'time_available' => $time_available,
        'biggest_barrier' => $barrier,
      ],
      'recommended_approach' => $this->getRecommendedLearningApproach($learning_styles, $time_available, $barrier),
      'timeline' => $this->generateLearningTimeline($time_available, $skill_trajectory),
      'barrier_strategies' => $this->getBarrierStrategies($barrier),
    ];

    return $path;
  }

  /**
   * Get recommended learning approach.
   *
   * @param array $styles
   *   Learning styles.
   * @param string $time
   *   Time available.
   * @param string $barrier
   *   Biggest barrier.
   *
   * @return string
   *   Recommended approach.
   */
  protected function getRecommendedLearningApproach(array $styles, $time, $barrier) {
    if ($barrier === 'no_time' || $time === 'less_1' || $time === 'no_time') {
      return "Focus on micro-learning: 15-minute daily practice sessions using AI in your actual work. This 'learning by doing' approach fits your schedule and provides immediate value.";
    }

    if (in_array('hands_on', $styles)) {
      return "Your hands-on learning style is perfect for AI. Start experimenting immediately with real work tasks. The best way to learn AI tools is by using them for actual work, not artificial exercises.";
    }

    if (in_array('video', $styles)) {
      return "Begin with short video tutorials (5-10 minutes) to understand basics, then immediately apply what you learned to your work. Combine watching with doing for maximum retention.";
    }

    if (in_array('workshops', $styles) || in_array('peers', $styles)) {
      return "Seek out live training sessions or peer learning opportunities. Learning AI with others provides accountability and diverse perspectives on effective use cases.";
    }

    return "Follow a self-paced learning approach that fits your schedule. Start with fundamentals, then progressively tackle more advanced applications in your specific domain.";
  }

  /**
   * Generate learning timeline.
   *
   * @param string $time_available
   *   Time available.
   * @param array $skill_trajectory
   *   Skill trajectory.
   *
   * @return array
   *   Timeline with milestones.
   */
  protected function generateLearningTimeline($time_available, array $skill_trajectory) {
    $timeline = [];

    // Week 1-2
    $timeline['week_1_2'] = [
      'focus' => 'Getting Started',
      'activities' => [
        'Set up account with one AI tool (ChatGPT or similar)',
        'Try 3–5 simple prompts related to your work',
        'Notice what works and what doesn’t',
      ],
    ];

    // Week 3-6
    if (!empty($skill_trajectory['emerging_value'])) {
      $first_skill = reset($skill_trajectory['emerging_value']);
      $timeline['week_3_6'] = [
        'focus' => $first_skill['skill'],
        'activities' => [
          'Practice using AI for one specific work task daily',
          'Learn effective prompting techniques',
          'Track time saved and quality of outputs',
        ],
      ];
    }

    // Month 2-3
    $timeline['month_2_3'] = [
      'focus' => 'Building Proficiency',
      'activities' => [
        'Expand AI use to 2–3 different task types',
        'Develop your personal prompt library',
        'Share learnings with a colleague',
      ],
    ];

    return $timeline;
  }

  /**
   * Get strategies for overcoming barriers.
   *
   * @param string $barrier
   *   The biggest barrier.
   *
   * @return array
   *   Strategies to overcome the barrier.
   */
  protected function getBarrierStrategies($barrier) {
    $strategies = [
      'no_time' => [
        'Replace, don’t add: Use AI for tasks you’re already doing, rather than treating learning as extra work',
        'Start with your most time-consuming task and use AI to speed it up',
        'Just 15 minutes a day will build competency faster than you expect',
      ],
      'dont_know_start' => [
        'Start here: Open ChatGPT and type “Help me write a professional email to…” for your next email',
        'You don’t need to understand how AI works to use it effectively',
        'This report provides your specific starting point',
      ],
      'no_access' => [
        'Free tools available: ChatGPT, Claude, and others have free tiers',
        'Present a business case to your manager showing potential time savings',
        'Start with free tools to demonstrate value, then request paid access',
      ],
      'overwhelming' => [
        'Focus on ONE tool and ONE use case to start',
        'You don’t need to learn everything - just what helps YOUR work',
        'Every expert started exactly where you are now',
      ],
      'not_priority' => [
        'Build the business case: Track time savings and productivity gains',
        'Show, don’t ask: Demonstrate results from free tools first',
        'Frame it as professional development that benefits the team',
      ],
      'need_support' => [
        'Find a learning buddy - colleague interested in AI',
        'Join online communities focused on AI in your industry',
        'This analysis provides structured guidance to reduce uncertainty',
      ],
    ];

    return $strategies[$barrier] ?? [
      'Start small with one simple use case',
      'Build confidence through practice',
      'Seek support from peers or mentors',
    ];
  }

}
