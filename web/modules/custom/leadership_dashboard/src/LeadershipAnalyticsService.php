<?php

namespace Drupal\leadership_dashboard;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\client_webform\WebformClientManager;
use Drupal\role_impact_analysis\RoleImpactAnalysis;

/**
 * Leadership Analytics Service.
 *
 * Provides aggregated analytics across multiple users for leadership dashboards.
 */
class LeadershipAnalyticsService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The webform client manager service.
   *
   * @var \Drupal\client_webform\WebformClientManager
   */
  protected $clientManager;

  /**
   * The role impact analysis service.
   *
   * @var \Drupal\role_impact_analysis\RoleImpactAnalysis
   */
  protected $roleImpactAnalyzer;

  /**
   * Minimum users required to show department data (privacy threshold).
   */
  const MIN_USERS_FOR_DEPARTMENT = 3;

  /**
   * Constructs a LeadershipAnalyticsService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    WebformClientManager $client_manager,
    RoleImpactAnalysis $role_impact_analyzer
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->clientManager = $client_manager;
    $this->roleImpactAnalyzer = $role_impact_analyzer;
  }

  /**
   * Get all users for a client, optionally filtered by department.
   *
   * @param int $client_nid
   *   The client node ID.
   * @param string|null $department
   *   Optional department filter.
   *
   * @return array
   *   Array of user IDs.
   */
  public function getClientUsers($client_nid, $department = NULL) {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $query = $user_storage->getQuery()
      ->condition('status', 1)
      ->condition('field_client', $client_nid)
      ->accessCheck(FALSE);

    if ($department !== NULL) {
      $query->condition('field_department', $department);
    }

    return $query->execute();
  }

  /**
   * Get list of departments within a client.
   *
   * @param int $client_nid
   *   The client node ID.
   *
   * @return array
   *   Array of department names with user counts.
   */
  public function getDepartments($client_nid) {
    $query = $this->database->select('user__field_department', 'dept');
    $query->join('user__field_client', 'client', 'dept.entity_id = client.entity_id');
    $query->join('users_field_data', 'u', 'dept.entity_id = u.uid');
    $query->fields('dept', ['field_department_value'])
      ->condition('client.field_client_target_id', $client_nid)
      ->condition('u.status', 1)
      ->condition('dept.field_department_value', '', '!=')
      ->isNotNull('dept.field_department_value');
    $query->addExpression('COUNT(dept.entity_id)', 'user_count');
    $query->groupBy('dept.field_department_value');

    $results = $query->execute()->fetchAll();

    $departments = [];
    foreach ($results as $row) {
      $departments[$row->field_department_value] = [
        'name' => $row->field_department_value,
        'user_count' => (int) $row->user_count,
        'show_data' => (int) $row->user_count >= self::MIN_USERS_FOR_DEPARTMENT,
      ];
    }

    return $departments;
  }

  /**
   * Aggregate risk scores across users.
   *
   * @param array $user_ids
   *   Array of user IDs.
   *
   * @return array
   *   Aggregated risk data with distribution.
   */
  public function aggregateRiskScores(array $user_ids) {
    if (empty($user_ids)) {
      return $this->getEmptyRiskData();
    }

    $risk_levels = ['high' => 0, 'medium' => 0, 'low' => 0];
    $urgency_levels = ['immediate' => 0, 'proactive' => 0, 'strategic' => 0];
    $risk_scores = [];
    $total_users = 0;

    foreach ($user_ids as $uid) {
      $risk_analysis = $this->roleImpactAnalyzer->calculateComprehensiveRisk($uid);

      if (!empty($risk_analysis)) {
        $total_users++;
        $risk_levels[$risk_analysis['risk_level']]++;
        $urgency_levels[$risk_analysis['urgency']]++;
        $risk_scores[] = $risk_analysis['risk_score'];
      }
    }

    return [
      'total_users' => $total_users,
      'avg_risk_score' => !empty($risk_scores) ? round(array_sum($risk_scores) / count($risk_scores), 1) : 0,
      'risk_distribution' => [
        'high' => $risk_levels['high'],
        'medium' => $risk_levels['medium'],
        'low' => $risk_levels['low'],
      ],
      'urgency_distribution' => [
        'immediate' => $urgency_levels['immediate'],
        'proactive' => $urgency_levels['proactive'],
        'strategic' => $urgency_levels['strategic'],
      ],
      'high_risk_percentage' => $total_users > 0 ? round(($risk_levels['high'] / $total_users) * 100, 1) : 0,
    ];
  }

  /**
   * Aggregate readiness metrics across users.
   *
   * @param array $user_ids
   *   Array of user IDs.
   *
   * @return array
   *   Aggregated readiness data.
   */
  public function aggregateReadinessMetrics(array $user_ids) {
    if (empty($user_ids)) {
      return $this->getEmptyReadinessData();
    }

    $skills_data = [];
    $confidence_data = [];
    $tool_access = ['yes' => 0, 'limited' => 0, 'no' => 0];
    $total_users = 0;

    foreach ($user_ids as $uid) {
      // Skills gap data (Module 5)
      $skills = $this->roleImpactAnalyzer->getSubmissionData('skills_gap', $uid);
      if ($skills) {
        $total_users++;
        $skill_level = $skills['m5_q1_skill_level'] ?? '';
        $skills_data[] = $this->normalizeSkillLevel($skill_level);
      }

      // Confidence data (Module 7)
      $confidence = $this->roleImpactAnalyzer->getSubmissionData('confidence', $uid);
      if ($confidence) {
        $confidence_data[] = [
          'confidence' => (int) ($confidence['m7_q1_confidence'] ?? 0),
          'anxiety' => (int) ($confidence['m7_q1_anxiety'] ?? 0),
          'excitement' => (int) ($confidence['m7_q1_excitement'] ?? 0),
        ];
      }

      // Tool access (Module 8)
      $org_readiness = $this->roleImpactAnalyzer->getSubmissionData('organizational_readiness', $uid);
      if ($org_readiness) {
        $access = $org_readiness['m8_q3_tool_access'] ?? '';
        if (isset($tool_access[$access])) {
          $tool_access[$access]++;
        }
      }
    }

    return [
      'total_users' => $total_users,
      'avg_skill_level' => !empty($skills_data) ? round(array_sum($skills_data) / count($skills_data), 1) : 0,
      'avg_confidence' => !empty($confidence_data) ? round(array_sum(array_column($confidence_data, 'confidence')) / count($confidence_data), 1) : 0,
      'avg_anxiety' => !empty($confidence_data) ? round(array_sum(array_column($confidence_data, 'anxiety')) / count($confidence_data), 1) : 0,
      'avg_excitement' => !empty($confidence_data) ? round(array_sum(array_column($confidence_data, 'excitement')) / count($confidence_data), 1) : 0,
      'tool_access_distribution' => $tool_access,
      'tool_access_percentage' => $total_users > 0 ? round(($tool_access['yes'] / $total_users) * 100, 1) : 0,
    ];
  }

  /**
   * Aggregate culture indicators across users.
   *
   * @param array $user_ids
   *   Array of user IDs.
   *
   * @return array
   *   Culture indicator data.
   */
  public function aggregateCultureIndicators(array $user_ids) {
    if (empty($user_ids)) {
      return $this->getEmptyCultureData();
    }

    $anxiety_scores = [];
    $confidence_scores = [];
    $excitement_scores = [];
    $sentiments = [];
    $trust_levels = [];
    $overwhelmed_count = 0;
    $worried_count = 0;
    $total_users = 0;

    foreach ($user_ids as $uid) {
      // Confidence/psychological data (Module 7)
      $confidence_data = $this->roleImpactAnalyzer->getSubmissionData('confidence', $uid);
      if ($confidence_data) {
        $total_users++;
        $anxiety_scores[] = (int) ($confidence_data['m7_q1_anxiety'] ?? 0);
        $confidence_scores[] = (int) ($confidence_data['m7_q1_confidence'] ?? 0);
        $excitement_scores[] = (int) ($confidence_data['m7_q1_excitement'] ?? 0);

        $sentiment = $confidence_data['m7_q2_describes_you'] ?? '';
        if ($sentiment) {
          $sentiments[] = $sentiment;
          if ($sentiment === 'overwhelmed') {
            $overwhelmed_count++;
          }
        }
      }

      // Threat perception (Module 3)
      $threat_data = $this->roleImpactAnalyzer->getSubmissionData('threat_perception', $uid);
      if ($threat_data) {
        $feelings = $threat_data['m3_q3_feelings_about_ai'] ?? '';
        if ($feelings === 'worried') {
          $worried_count++;
        }
      }

      // Leadership trust (Module 8)
      $org_data = $this->roleImpactAnalyzer->getSubmissionData('organizational_readiness', $uid);
      if ($org_data) {
        $communication = $org_data['m8_q5_communication'] ?? '';
        $trust_levels[] = $this->normalizeCommunicationToTrust($communication);
      }
    }

    // Calculate change fatigue (derived from anxiety + overwhelm)
    $avg_anxiety = !empty($anxiety_scores) ? array_sum($anxiety_scores) / count($anxiety_scores) : 0;
    $overwhelm_rate = $total_users > 0 ? $overwhelmed_count / $total_users : 0;
    $change_fatigue_score = ($avg_anxiety / 5 * 0.6) + ($overwhelm_rate * 0.4);

    return [
      'total_users' => $total_users,
      'avg_anxiety' => round($avg_anxiety, 1),
      'avg_confidence' => !empty($confidence_scores) ? round(array_sum($confidence_scores) / count($confidence_scores), 1) : 0,
      'avg_excitement' => !empty($excitement_scores) ? round(array_sum($excitement_scores) / count($excitement_scores), 1) : 0,
      'avg_trust_in_leadership' => !empty($trust_levels) ? round(array_sum($trust_levels) / count($trust_levels), 1) : 0,
      'change_fatigue_score' => round($change_fatigue_score * 100, 1),
      'overwhelmed_percentage' => $total_users > 0 ? round(($overwhelmed_count / $total_users) * 100, 1) : 0,
      'worried_percentage' => $total_users > 0 ? round(($worried_count / $total_users) * 100, 1) : 0,
      'sentiment_distribution' => array_count_values($sentiments),
    ];
  }

  /**
   * Identify quick wins: high potential + high readiness users.
   *
   * @param array $user_ids
   *   Array of user IDs.
   *
   * @return array
   *   Quick wins data.
   */
  public function identifyQuickWins(array $user_ids) {
    if (empty($user_ids)) {
      return ['count' => 0, 'percentage' => 0, 'criteria' => []];
    }

    $quick_wins = 0;
    $criteria_breakdown = [];

    foreach ($user_ids as $uid) {
      $is_quick_win = true;
      $user_criteria = [];

      // High AI usage (Module 1)
      $ai_usage = $this->roleImpactAnalyzer->getSubmissionData('current_ai_usage', $uid);
      $high_usage = isset($ai_usage['m1_q2_frequency']) &&
                    in_array($ai_usage['m1_q2_frequency'], ['daily', 'weekly']);
      $user_criteria['high_usage'] = $high_usage;
      if (!$high_usage) $is_quick_win = false;

      // Low anxiety (Module 7)
      $confidence_data = $this->roleImpactAnalyzer->getSubmissionData('confidence', $uid);
      $low_anxiety = isset($confidence_data['m7_q1_anxiety']) &&
                     (int) $confidence_data['m7_q1_anxiety'] <= 2;
      $user_criteria['low_anxiety'] = $low_anxiety;
      if (!$low_anxiety) $is_quick_win = false;

      // High confidence (Module 7)
      $high_confidence = isset($confidence_data['m7_q1_confidence']) &&
                        (int) $confidence_data['m7_q1_confidence'] >= 4;
      $user_criteria['high_confidence'] = $high_confidence;
      if (!$high_confidence) $is_quick_win = false;

      // Tool access (Module 8)
      $org_data = $this->roleImpactAnalyzer->getSubmissionData('organizational_readiness', $uid);
      $has_tools = isset($org_data['m8_q3_tool_access']) &&
                   $org_data['m8_q3_tool_access'] === 'yes';
      $user_criteria['has_tools'] = $has_tools;
      if (!$has_tools) $is_quick_win = false;

      if ($is_quick_win) {
        $quick_wins++;
      }

      $criteria_breakdown[] = $user_criteria;
    }

    return [
      'count' => $quick_wins,
      'percentage' => count($user_ids) > 0 ? round(($quick_wins / count($user_ids)) * 100, 1) : 0,
      'criteria' => [
        'high_usage' => array_sum(array_column($criteria_breakdown, 'high_usage')),
        'low_anxiety' => array_sum(array_column($criteria_breakdown, 'low_anxiety')),
        'high_confidence' => array_sum(array_column($criteria_breakdown, 'high_confidence')),
        'has_tools' => array_sum(array_column($criteria_breakdown, 'has_tools')),
      ],
    ];
  }

  /**
   * Identify risk areas: high displacement + low readiness.
   *
   * @param array $user_ids
   *   Array of user IDs.
   *
   * @return array
   *   Risk area data.
   */
  public function identifyRiskAreas(array $user_ids) {
    if (empty($user_ids)) {
      return ['count' => 0, 'percentage' => 0, 'avg_displacement' => 0];
    }

    $risk_areas = 0;
    $displacement_scores = [];

    foreach ($user_ids as $uid) {
      $risk = $this->roleImpactAnalyzer->calculateComprehensiveRisk($uid);

      if (!empty($risk) && $risk['risk_level'] === 'high') {
        // Get task displacement data
        $task_data = $this->roleImpactAnalyzer->getSubmissionData('task_analysis', $uid);

        if ($task_data) {
          $displacement_risk = $this->roleImpactAnalyzer->calculateTaskDisplacementRisk($task_data);

          if ($displacement_risk['help_me_percentage'] >= 40) {
            $risk_areas++;
            $displacement_scores[] = $displacement_risk['help_me_percentage'];
          }
        }
      }
    }

    return [
      'count' => $risk_areas,
      'percentage' => count($user_ids) > 0 ? round(($risk_areas / count($user_ids)) * 100, 1) : 0,
      'avg_displacement' => !empty($displacement_scores) ? round(array_sum($displacement_scores) / count($displacement_scores), 1) : 0,
    ];
  }

  /**
   * Calculate ROI projections based on adoption scenarios.
   *
   * @param array $user_ids
   *   Array of user IDs.
   * @param float $adoption_rate
   *   Adoption rate percentage (0-100).
   *
   * @return array
   *   ROI projection data.
   */
  public function calculateROIProjections(array $user_ids, $adoption_rate = 50) {
    if (empty($user_ids)) {
      return $this->getEmptyROIData();
    }

    // Benchmark time savings per task category (hours per week)
    $benchmarks = $this->getROIBenchmarks();

    $total_time_savings = 0;
    $productivity_gain = 0;
    $users_analyzed = 0;

    foreach ($user_ids as $uid) {
      $task_data = $this->roleImpactAnalyzer->getSubmissionData('task_analysis', $uid);

      if (!$task_data) {
        continue;
      }

      $users_analyzed++;
      $role = $task_data['m2_q1_role_category'] ?? 'operations';

      // Calculate potential time savings based on "help me" tasks
      $help_tasks = 0;
      foreach ($task_data as $key => $value) {
        if (strpos($key, 'm2_q2_') === 0 && $value === 'help') {
          $help_tasks++;
        }
      }

      // Apply benchmark for role category
      $role_benchmark = $benchmarks[$role] ?? $benchmarks['operations'];
      $user_time_savings = ($help_tasks / 50) * $role_benchmark; // 50 total tasks
      $total_time_savings += $user_time_savings;
    }

    // Apply adoption rate
    $adjusted_savings = $total_time_savings * ($adoption_rate / 100);

    // Calculate productivity gain (assuming $75/hour average)
    $hourly_rate = 75;
    $weeks_per_year = 48; // Account for vacation/holidays
    $annual_value = $adjusted_savings * $weeks_per_year * $hourly_rate;

    return [
      'total_users' => count($user_ids),
      'users_analyzed' => $users_analyzed,
      'adoption_rate' => $adoption_rate,
      'weekly_hours_saved' => round($adjusted_savings, 1),
      'annual_hours_saved' => round($adjusted_savings * $weeks_per_year, 0),
      'annual_value' => round($annual_value, 0),
      'per_user_weekly_savings' => $users_analyzed > 0 ? round($adjusted_savings / $users_analyzed, 1) : 0,
      'roi_percentage' => 0, // To be calculated based on implementation cost
    ];
  }

  /**
   * Get heat map data for departments × metrics.
   *
   * @param int $client_nid
   *   The client node ID.
   *
   * @return array
   *   Heat map data structure.
   */
  public function getHeatMapData($client_nid) {
    $departments = $this->getDepartments($client_nid);
    $heat_map = [];

    foreach ($departments as $dept_name => $dept_info) {
      if (!$dept_info['show_data']) {
        continue; // Skip departments with < 3 users
      }

      $user_ids = $this->getClientUsers($client_nid, $dept_name);
      $risk_data = $this->aggregateRiskScores($user_ids);
      $readiness_data = $this->aggregateReadinessMetrics($user_ids);

      $heat_map[$dept_name] = [
        'department' => $dept_name,
        'user_count' => $dept_info['user_count'],
        'risk_score' => $risk_data['avg_risk_score'],
        'risk_level' => $this->calculateRiskLevel($risk_data['avg_risk_score']),
        'skill_level' => $readiness_data['avg_skill_level'],
        'confidence' => $readiness_data['avg_confidence'],
        'anxiety' => $readiness_data['avg_anxiety'],
      ];
    }

    return $heat_map;
  }

  /**
   * Helper: Normalize skill level to numeric score.
   */
  private function normalizeSkillLevel($skill_level) {
    $levels = [
      'never_used' => 1,
      'beginner' => 2,
      'intermediate' => 3,
      'advanced' => 4,
      'expert' => 5,
    ];
    return $levels[$skill_level] ?? 1;
  }

  /**
   * Helper: Normalize communication quality to trust score.
   */
  private function normalizeCommunicationToTrust($communication) {
    $trust_map = [
      'excellent' => 5,
      'good' => 4,
      'okay' => 3,
      'poor' => 2,
      'very_poor' => 1,
    ];
    return $trust_map[$communication] ?? 3;
  }

  /**
   * Helper: Calculate risk level from score.
   */
  private function calculateRiskLevel($score) {
    if ($score >= 60) return 'high';
    if ($score >= 30) return 'medium';
    return 'low';
  }

  /**
   * Helper: Get ROI benchmarks.
   */
  private function getROIBenchmarks() {
    return [
      'sales' => 12,
      'marketing' => 10,
      'engineering' => 15,
      'operations' => 8,
      'management' => 14,
      'finance' => 11,
      'hr' => 9,
    ];
  }

  /**
   * Helper: Empty risk data.
   */
  private function getEmptyRiskData() {
    return [
      'total_users' => 0,
      'avg_risk_score' => 0,
      'risk_distribution' => ['high' => 0, 'medium' => 0, 'low' => 0],
      'urgency_distribution' => ['immediate' => 0, 'proactive' => 0, 'strategic' => 0],
      'high_risk_percentage' => 0,
    ];
  }

  /**
   * Helper: Empty readiness data.
   */
  private function getEmptyReadinessData() {
    return [
      'total_users' => 0,
      'avg_skill_level' => 0,
      'avg_confidence' => 0,
      'avg_anxiety' => 0,
      'avg_excitement' => 0,
      'tool_access_distribution' => ['yes' => 0, 'limited' => 0, 'no' => 0],
      'tool_access_percentage' => 0,
    ];
  }

  /**
   * Helper: Empty culture data.
   */
  private function getEmptyCultureData() {
    return [
      'total_users' => 0,
      'avg_anxiety' => 0,
      'avg_confidence' => 0,
      'avg_excitement' => 0,
      'avg_trust_in_leadership' => 0,
      'change_fatigue_score' => 0,
      'overwhelmed_percentage' => 0,
      'worried_percentage' => 0,
      'sentiment_distribution' => [],
    ];
  }

  /**
   * Helper: Empty ROI data.
   */
  private function getEmptyROIData() {
    return [
      'total_users' => 0,
      'users_analyzed' => 0,
      'adoption_rate' => 0,
      'weekly_hours_saved' => 0,
      'annual_hours_saved' => 0,
      'annual_value' => 0,
      'per_user_weekly_savings' => 0,
      'roi_percentage' => 0,
    ];
  }

}
