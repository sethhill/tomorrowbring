<?php

namespace Drupal\ai_report_storage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Manager service for AI reports - provides unified API across all report types.
 */
class AiReportManager {

  protected $entityTypeManager;
  protected $currentUser;
  protected $logger;
  protected $moduleHandler;
  protected $reportStorage;

  /**
   * Map of report types to their service IDs.
   */
  protected $reportServices = [
    'role_impact' => 'ai_role_impact.analysis_service',
    'career_transitions' => 'ai_career_transitions.analysis_service',
    'industry_insights' => 'ai_industry_insights.analysis_service',
    'skills' => 'ai_skills_analyzer.analysis_service',
    'task_recommendations' => 'ai_task_recommender.analysis_service',
    'hybrid_analysis' => 'role_impact_analysis.analysis_service',
    'concerns_navigator' => 'ai_concerns_navigator.service',
  ];

  /**
   * Map of report types to user-friendly labels.
   */
  protected $reportLabels = [
    'role_impact' => 'Role Impact Analysis',
    'career_transitions' => 'Career Transitions',
    'industry_insights' => 'Industry Insights',
    'skills' => 'Skills Analysis',
    'task_recommendations' => 'Task Recommendations',
    'hybrid_analysis' => 'Hybrid Analysis',
    'concerns_navigator' => 'AI Concerns Navigator',
  ];

  /**
   * Constructs an AiReportManager object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    ModuleHandlerInterface $module_handler
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('ai_report_storage');
    $this->moduleHandler = $module_handler;
    $this->reportStorage = $entity_type_manager->getStorage('ai_report');
  }

  /**
   * Get all available report types.
   *
   * @return array
   *   Array of report type => label.
   */
  public function getAvailableReportTypes(): array {
    $available = [];
    foreach ($this->reportServices as $type => $service_id) {
      // Check if the module is enabled.
      if (\Drupal::hasService($service_id)) {
        $available[$type] = $this->reportLabels[$type];
      }
    }
    return $available;
  }

  /**
   * Get a specific report service.
   *
   * @param string $type
   *   The report type.
   *
   * @return object|null
   *   The service object, or NULL if not found.
   */
  public function getReportService(string $type) {
    if (!isset($this->reportServices[$type])) {
      return NULL;
    }

    $service_id = $this->reportServices[$type];
    if (!\Drupal::hasService($service_id)) {
      return NULL;
    }

    return \Drupal::service($service_id);
  }

  /**
   * Alias for getReportService() for backward compatibility.
   *
   * @param string $type
   *   The report type.
   *
   * @return object|null
   *   The service object, or NULL if not found.
   */
  public function getService(string $type) {
    return $this->getReportService($type);
  }

  /**
   * Get all reports for a user.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   * @param string|null $type
   *   Optional filter by report type.
   * @param string $status
   *   Filter by status (default: 'published').
   *
   * @return array
   *   Array of report entities.
   */
  public function getUserReports($uid = NULL, string $type = NULL, string $status = 'published'): array {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $properties = [
      'uid' => $uid,
      'status' => $status,
    ];

    if ($type !== NULL) {
      $properties['type'] = $type;
    }

    $entities = $this->reportStorage->loadByProperties($properties);

    // Sort by generated_at descending.
    usort($entities, function ($a, $b) {
      return $b->getGeneratedAt() - $a->getGeneratedAt();
    });

    return $entities;
  }

  /**
   * Get the latest report for each type for a user.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return array
   *   Array of report_type => entity.
   */
  public function getLatestReportsByType($uid = NULL): array {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $latest = [];
    foreach (array_keys($this->getAvailableReportTypes()) as $type) {
      $entities = $this->reportStorage->loadByProperties([
        'uid' => $uid,
        'type' => $type,
        'status' => 'published',
      ]);

      if (!empty($entities)) {
        usort($entities, function ($a, $b) {
          return $b->getVersion() - $a->getVersion();
        });
        $latest[$type] = reset($entities);
      }
    }

    return $latest;
  }

  /**
   * Check if a user has any reports.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   *
   * @return bool
   *   TRUE if user has at least one report, FALSE otherwise.
   */
  public function hasAnyReports($uid = NULL): bool {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $entities = $this->reportStorage->loadByProperties([
      'uid' => $uid,
      'status' => 'published',
    ]);

    return !empty($entities);
  }

  /**
   * Get the latest version number for a report type.
   *
   * @param int $uid
   *   The user ID.
   * @param string $type
   *   The report type.
   *
   * @return int|null
   *   The version number, or NULL if no reports exist.
   */
  public function getLatestReportVersion($uid, string $type): ?int {
    $entities = $this->reportStorage->loadByProperties([
      'uid' => $uid,
      'type' => $type,
      'status' => 'published',
    ]);

    if (empty($entities)) {
      return NULL;
    }

    $versions = array_map(function ($entity) {
      return $entity->getVersion();
    }, $entities);

    return max($versions);
  }

  /**
   * Get all versions of a report type for a user.
   *
   * @param int $uid
   *   The user ID.
   * @param string $type
   *   The report type.
   *
   * @return array
   *   Array of report entities sorted by version descending.
   */
  public function getReportVersions($uid, string $type): array {
    $entities = $this->reportStorage->loadByProperties([
      'uid' => $uid,
      'type' => $type,
    ]);

    usort($entities, function ($a, $b) {
      return $b->getVersion() - $a->getVersion();
    });

    return $entities;
  }

  /**
   * Get a specific version of a report.
   *
   * @param int $uid
   *   The user ID.
   * @param string $type
   *   The report type.
   * @param int $version
   *   The version number.
   *
   * @return \Drupal\ai_report_storage\Entity\AiReportInterface|null
   *   The report entity, or NULL if not found.
   */
  public function getReportVersion($uid, string $type, int $version) {
    $entities = $this->reportStorage->loadByProperties([
      'uid' => $uid,
      'type' => $type,
      'version' => $version,
    ]);

    return !empty($entities) ? reset($entities) : NULL;
  }

  /**
   * Compare two versions of a report.
   *
   * @param int $uid
   *   The user ID.
   * @param string $type
   *   The report type.
   * @param int $version1
   *   The first version number.
   * @param int $version2
   *   The second version number.
   *
   * @return array
   *   Array with 'report1', 'report2', and 'differences' keys.
   */
  public function compareReportVersions($uid, string $type, int $version1, int $version2): array {
    $report1 = $this->getReportVersion($uid, $type, $version1);
    $report2 = $this->getReportVersion($uid, $type, $version2);

    if (!$report1 || !$report2) {
      return [];
    }

    $data1 = $report1->getReportData();
    $data2 = $report2->getReportData();

    // Simple comparison - could be enhanced with more sophisticated diff logic.
    $differences = [
      'generation_time_diff' => $report2->getGeneratedAt() - $report1->getGeneratedAt(),
      'data_changed' => $data1 !== $data2,
    ];

    return [
      'report1' => $report1,
      'report2' => $report2,
      'differences' => $differences,
    ];
  }

  /**
   * Export all reports for a user.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   * @param string $format
   *   The export format (currently only 'json' supported).
   *
   * @return mixed
   *   The exported data.
   */
  public function exportUserReports($uid = NULL, string $format = 'json') {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $latest_reports = $this->getLatestReportsByType($uid);

    $export_data = [
      'uid' => $uid,
      'exported_at' => time(),
      'reports' => [],
    ];

    foreach ($latest_reports as $type => $entity) {
      $export_data['reports'][$type] = [
        'type' => $type,
        'version' => $entity->getVersion(),
        'generated_at' => $entity->getGeneratedAt(),
        'generation_time' => $entity->getGenerationTime(),
        'data' => $entity->getReportData(),
      ];
    }

    if ($format === 'json') {
      return json_encode($export_data, JSON_PRETTY_PRINT);
    }

    return $export_data;
  }

  /**
   * Generate all available reports for a user.
   *
   * @param int|null $uid
   *   The user ID, or NULL for current user.
   * @param bool $force
   *   Force regeneration even if reports exist.
   *
   * @return array
   *   Array of report_type => result.
   */
  public function generateAllReports($uid = NULL, bool $force = FALSE): array {
    if ($uid === NULL) {
      $uid = $this->currentUser->id();
    }

    $results = [];

    foreach ($this->getAvailableReportTypes() as $type => $label) {
      $service = $this->getReportService($type);
      if ($service && method_exists($service, 'generateReport')) {
        try {
          $result = $service->generateReport($uid, $force);
          $results[$type] = [
            'success' => !isset($result['error']),
            'data' => $result,
          ];
        }
        catch (\Exception $e) {
          $results[$type] = [
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
      }
    }

    return $results;
  }

  /**
   * Delete all reports for a user.
   *
   * @param int $uid
   *   The user ID.
   * @param string|null $type
   *   Optional: Only delete reports of this type.
   */
  public function deleteUserReports($uid, string $type = NULL): void {
    $properties = ['uid' => $uid];
    if ($type !== NULL) {
      $properties['type'] = $type;
    }

    $entities = $this->reportStorage->loadByProperties($properties);

    foreach ($entities as $entity) {
      $entity->delete();
    }

    $this->logger->info('Deleted @count reports for user @uid', [
      '@count' => count($entities),
      '@uid' => $uid,
    ]);
  }

}
