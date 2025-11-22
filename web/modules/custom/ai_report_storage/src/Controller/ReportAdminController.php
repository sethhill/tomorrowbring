<?php

namespace Drupal\ai_report_storage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_report_storage\AiReportManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for admin oversight of AI reports.
 */
class ReportAdminController extends ControllerBase {

  protected $reportManager;
  protected $reportStorage;

  /**
   * Constructs a ReportAdminController object.
   */
  public function __construct(AiReportManager $report_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->reportManager = $report_manager;
    $this->reportStorage = $entity_type_manager->getStorage('ai_report');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_report_storage.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Admin overview of all AI reports.
   *
   * @return array
   *   Render array.
   */
  public function overview() {
    // Get statistics.
    $all_reports = $this->reportStorage->loadMultiple();

    $stats = [
      'total' => count($all_reports),
      'by_type' => [],
      'by_status' => [],
      'total_users' => 0,
    ];

    $user_ids = [];
    foreach ($all_reports as $entity) {
      $type = $entity->getType();
      $status = $entity->getStatus();

      if (!isset($stats['by_type'][$type])) {
        $stats['by_type'][$type] = 0;
      }
      $stats['by_type'][$type]++;

      if (!isset($stats['by_status'][$status])) {
        $stats['by_status'][$status] = 0;
      }
      $stats['by_status'][$status]++;

      $user_ids[$entity->getOwnerId()] = TRUE;
    }

    $stats['total_users'] = count($user_ids);

    // Build statistics display.
    $build = [];

    $build['stats'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Statistics'),
    ];

    $build['stats']['summary'] = [
      '#markup' => $this->t('<p><strong>Total Reports:</strong> @total<br><strong>Total Users:</strong> @users</p>', [
        '@total' => $stats['total'],
        '@users' => $stats['total_users'],
      ]),
    ];

    $build['stats']['by_type'] = [
      '#type' => 'details',
      '#title' => $this->t('Reports by Type'),
      '#open' => TRUE,
    ];

    $type_rows = [];
    foreach ($stats['by_type'] as $type => $count) {
      $type_rows[] = [$type, $count];
    }

    $build['stats']['by_type']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Type'), $this->t('Count')],
      '#rows' => $type_rows,
    ];

    $build['stats']['by_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Reports by Status'),
    ];

    $status_rows = [];
    foreach ($stats['by_status'] as $status => $count) {
      $status_rows[] = [$status, $count];
    }

    $build['stats']['by_status']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Status'), $this->t('Count')],
      '#rows' => $status_rows,
    ];

    // Use the entity list builder for the full list.
    $build['list'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('All Reports'),
    ];

    $list_builder = $this->entityTypeManager()->getListBuilder('ai_report');
    $build['list']['table'] = $list_builder->render();

    return $build;
  }

}
