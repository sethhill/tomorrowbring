<?php

namespace Drupal\ai_report_storage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_report_storage\AiReportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for viewing report history and versions.
 */
class ReportHistoryController extends ControllerBase {

  protected $reportManager;

  /**
   * Constructs a ReportHistoryController object.
   */
  public function __construct(AiReportManager $report_manager) {
    $this->reportManager = $report_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_report_storage.manager')
    );
  }

  /**
   * View report history for a specific type.
   *
   * @param string $report_type
   *   The report type.
   *
   * @return array
   *   Render array.
   */
  public function viewHistory($report_type) {
    $uid = $this->currentUser()->id();
    $versions = $this->reportManager->getReportVersions($uid, $report_type);

    if (empty($versions)) {
      return [
        '#markup' => $this->t('No report history found for this type.'),
      ];
    }

    $rows = [];
    foreach ($versions as $entity) {
      $rows[] = [
        'version' => $entity->getVersion(),
        'status' => $entity->getStatus(),
        'generated' => \Drupal::service('date.formatter')->format($entity->getGeneratedAt(), 'medium'),
        'generation_time' => number_format($entity->getGenerationTime(), 2) . 's',
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'view' => [
                'title' => $this->t('View'),
                'url' => \Drupal\Core\Url::fromRoute('ai_report_storage.view_version', [
                  'report_type' => $report_type,
                  'version' => $entity->getVersion(),
                ]),
              ],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Version'),
        $this->t('Status'),
        $this->t('Generated'),
        $this->t('Time'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No versions found.'),
    ];
  }

  /**
   * View a specific report version.
   *
   * @param string $report_type
   *   The report type.
   * @param int $version
   *   The version number.
   *
   * @return array
   *   Render array.
   */
  public function viewVersion($report_type, $version) {
    $uid = $this->currentUser()->id();
    $entity = $this->reportManager->getReportVersion($uid, $report_type, $version);

    if (!$entity) {
      return [
        '#markup' => $this->t('Report version not found.'),
      ];
    }

    $data = $entity->getReportData();

    return [
      '#theme' => 'ai_report_version',
      '#report_type' => $report_type,
      '#version' => $version,
      '#generated_at' => $entity->getGeneratedAt(),
      '#generation_time' => $entity->getGenerationTime(),
      '#status' => $entity->getStatus(),
      '#data' => $data,
      '#attached' => [
        'library' => ['ai_report_storage/report_viewer'],
      ],
    ];
  }

  /**
   * Compare two report versions.
   *
   * @param string $report_type
   *   The report type.
   * @param int $version1
   *   The first version number.
   * @param int $version2
   *   The second version number.
   *
   * @return array
   *   Render array.
   */
  public function compareVersions($report_type, $version1, $version2) {
    $uid = $this->currentUser()->id();
    $comparison = $this->reportManager->compareReportVersions($uid, $report_type, $version1, $version2);

    if (empty($comparison)) {
      return [
        '#markup' => $this->t('Unable to compare these versions.'),
      ];
    }

    return [
      '#theme' => 'ai_report_comparison',
      '#report_type' => $report_type,
      '#version1' => $version1,
      '#version2' => $version2,
      '#report1' => $comparison['report1'],
      '#report2' => $comparison['report2'],
      '#differences' => $comparison['differences'],
    ];
  }

}
