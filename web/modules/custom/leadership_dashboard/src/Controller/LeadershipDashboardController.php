<?php

namespace Drupal\leadership_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\leadership_dashboard\LeadershipAnalyticsService;
use Drupal\client_webform\WebformClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Leadership Dashboard Controller.
 */
class LeadershipDashboardController extends ControllerBase {

  /**
   * The leadership analytics service.
   *
   * @var \Drupal\leadership_dashboard\LeadershipAnalyticsService
   */
  protected $analyticsService;

  /**
   * The webform client manager service.
   *
   * @var \Drupal\client_webform\WebformClientManager
   */
  protected $clientManager;

  /**
   * Constructs a LeadershipDashboardController object.
   */
  public function __construct(
    LeadershipAnalyticsService $analytics_service,
    WebformClientManager $client_manager
  ) {
    $this->analyticsService = $analytics_service;
    $this->clientManager = $client_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('leadership_dashboard.analytics'),
      $container->get('client_webform.manager')
    );
  }

  /**
   * Displays the leadership dashboard.
   */
  public function dashboard(Request $request) {
    // Get current user's client
    $client = $this->clientManager->getCurrentUserClient();

    if (!$client) {
      $this->messenger()->addError($this->t('You must be assigned to a client to view the leadership dashboard.'));
      return ['#markup' => ''];
    }

    $client_nid = $client->id();
    $client_name = $client->getTitle();

    // Get department filter from query parameter
    $department_filter = $request->query->get('department');

    // Get user IDs based on filter
    if ($department_filter && $department_filter !== 'all') {
      $user_ids = $this->analyticsService->getClientUsers($client_nid, $department_filter);
      $context_label = $department_filter;
    }
    else {
      $user_ids = $this->analyticsService->getClientUsers($client_nid);
      $context_label = 'All Departments';
    }

    // Get all departments for filter dropdown
    $departments = $this->analyticsService->getDepartments($client_nid);

    // Aggregate all analytics data
    $risk_data = $this->analyticsService->aggregateRiskScores($user_ids);
    $readiness_data = $this->analyticsService->aggregateReadinessMetrics($user_ids);
    $culture_data = $this->analyticsService->aggregateCultureIndicators($user_ids);
    $quick_wins = $this->analyticsService->identifyQuickWins($user_ids);
    $risk_areas = $this->analyticsService->identifyRiskAreas($user_ids);
    $heat_map_data = $this->analyticsService->getHeatMapData($client_nid);

    // ROI projections for multiple scenarios
    $roi_scenarios = [
      25 => $this->analyticsService->calculateROIProjections($user_ids, 25),
      50 => $this->analyticsService->calculateROIProjections($user_ids, 50),
      75 => $this->analyticsService->calculateROIProjections($user_ids, 75),
      100 => $this->analyticsService->calculateROIProjections($user_ids, 100),
    ];

    // Pass data to JavaScript for Chart.js
    $chart_data = [
      'riskDistribution' => $risk_data['risk_distribution'],
      'urgencyDistribution' => $risk_data['urgency_distribution'],
      'toolAccessDistribution' => $readiness_data['tool_access_distribution'],
      'sentimentDistribution' => $culture_data['sentiment_distribution'],
      'heatMapData' => array_values($heat_map_data),
      'roiScenarios' => $roi_scenarios,
    ];

    $build = [
      '#theme' => 'leadership_dashboard',
      '#client_name' => $client_name,
      '#context_label' => $context_label,
      '#departments' => $departments,
      '#current_department' => $department_filter ?? 'all',
      '#total_users' => count($user_ids),
      '#risk_data' => $risk_data,
      '#readiness_data' => $readiness_data,
      '#culture_data' => $culture_data,
      '#quick_wins' => $quick_wins,
      '#risk_areas' => $risk_areas,
      '#heat_map_data' => $heat_map_data,
      '#roi_scenarios' => $roi_scenarios,
      '#attached' => [
        'library' => ['leadership_dashboard/leadership_dashboard'],
        'drupalSettings' => [
          'leadershipDashboard' => $chart_data,
        ],
      ],
    ];

    return $build;
  }

}
