<?php

namespace Drupal\client_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\client_webform\WebformClientManager;
use Drupal\ai_report_storage\AiReportManager;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;

/**
 * Service for automatically processing reports when modules are completed.
 */
class AutoReportProcessor {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The webform client manager.
   *
   * @var \Drupal\client_webform\WebformClientManager
   */
  protected $clientManager;

  /**
   * The AI report manager.
   *
   * @var \Drupal\ai_report_storage\AiReportManager
   */
  protected $reportManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The queue worker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * Report types available for auto-processing.
   *
   * @var array
   */
  protected $reportTypes = [
    'role_impact',
    'career_transitions',
    'task_recommendations',
    'industry_insights',
    'skills',
    'learning_resources',
    'breakthrough_strategies',
    'concerns_navigator',
  ];

  /**
   * Constructs an AutoReportProcessor object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\client_webform\WebformClientManager $client_manager
   *   The webform client manager.
   * @param \Drupal\ai_report_storage\AiReportManager $report_manager
   *   The AI report manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    WebformClientManager $client_manager,
    AiReportManager $report_manager,
    LoggerChannelFactoryInterface $logger_factory,
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_manager
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->clientManager = $client_manager;
    $this->reportManager = $report_manager;
    $this->logger = $logger_factory->get('client_dashboard');
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
  }

  /**
   * Check if automatic processing should occur for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if automatic processing should occur, FALSE otherwise.
   */
  public function shouldAutoProcess($uid) {
    $config = $this->configFactory->get('client_dashboard.settings');

    // Check if auto-processing is enabled.
    if (!$config->get('auto_process_enabled')) {
      return FALSE;
    }

    // Check if all modules are completed.
    return $this->isAllModulesCompleted($uid);
  }

  /**
   * Check if all required modules are completed for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if all modules are completed, FALSE otherwise.
   */
  public function isAllModulesCompleted($uid) {
    // Get enabled modules for this client.
    $module_nodes = $this->clientManager->getEnabledModules($uid);

    if (empty($module_nodes)) {
      return FALSE;
    }

    $total = count($module_nodes);
    $completed = 0;

    foreach ($module_nodes as $node) {
      $webform_id = $node->get('field_form')->target_id;
      if ($webform_id) {
        $submission_id = $this->getModuleSubmission($webform_id, $uid);
        if ($submission_id) {
          $submission = $this->entityTypeManager
            ->getStorage('webform_submission')
            ->load($submission_id);
          if ($submission && $submission->get('completed')->value > 0) {
            $completed++;
          }
        }
      }
    }

    return ($total > 0 && (int) $completed === (int) $total);
  }

  /**
   * Get a user's submission for a webform.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param int $uid
   *   The user ID.
   *
   * @return int|null
   *   The submission ID, or NULL if not found.
   */
  protected function getModuleSubmission($webform_id, $uid) {
    $submission_storage = $this->entityTypeManager->getStorage('webform_submission');

    $query = $submission_storage->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $result = $query->execute();

    return !empty($result) ? reset($result) : NULL;
  }

  /**
   * Queue all enabled reports for automatic processing.
   *
   * @param int $uid
   *   The user ID.
   */
  public function queueAllReports($uid) {
    $config = $this->configFactory->get('client_dashboard.settings');
    $enabled_reports = $config->get('auto_process_reports') ?? [];
    $delay = $config->get('auto_process_delay') ?? 0;

    $queued_count = 0;

    foreach ($this->reportTypes as $report_type) {
      // Skip if this report type is not enabled.
      if (empty($enabled_reports[$report_type])) {
        continue;
      }

      // Get the report service.
      $service = $this->reportManager->getService($report_type);
      if (!$service) {
        $this->logger->warning('Could not load service for report type: @type', [
          '@type' => $report_type,
        ]);
        continue;
      }

      // Check if report already exists or is pending.
      $existing = $service->getExistingReport($uid, FALSE);
      if ($existing) {
        $this->logger->info('Skipping @type for user @uid: report already exists', [
          '@type' => $report_type,
          '@uid' => $uid,
        ]);
        continue;
      }

      $pending = $service->getPendingReport($uid);
      if ($pending) {
        $this->logger->info('Skipping @type for user @uid: report already pending', [
          '@type' => $report_type,
          '@uid' => $uid,
        ]);
        continue;
      }

      // Check if user has minimum required data.
      if (!$service->hasMinimumData($uid)) {
        $this->logger->info('Skipping @type for user @uid: insufficient data', [
          '@type' => $report_type,
          '@uid' => $uid,
        ]);
        continue;
      }

      // Queue the report.
      try {
        if ($delay > 0) {
          // If there's a delay, we could implement a delayed queue item here.
          // For now, we'll just queue immediately and note this for future enhancement.
          $this->logger->info('Queuing @type for user @uid (delay setting: @delay minutes)', [
            '@type' => $report_type,
            '@uid' => $uid,
            '@delay' => $delay,
          ]);
        }

        $service->queueReportGeneration($uid);
        $queued_count++;

        $this->logger->info('Queued @type report for user @uid', [
          '@type' => $report_type,
          '@uid' => $uid,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to queue @type report for user @uid: @message', [
          '@type' => $report_type,
          '@uid' => $uid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($queued_count > 0) {
      $this->logger->info('Auto-queued @count reports for user @uid', [
        '@count' => $queued_count,
        '@uid' => $uid,
      ]);

      // Trigger immediate queue processing.
      $this->triggerQueueProcessing();
    }
  }

  /**
   * Trigger immediate queue processing.
   *
   * This processes one queue item immediately to start the process.
   * Subsequent items will be picked up either by continued processing
   * or by cron. This avoids the user having to wait for cron to run.
   */
  protected function triggerQueueProcessing() {
    try {
      $queue = $this->queueFactory->get('generate_ai_report');
      $queue_worker = $this->queueManager->createInstance('generate_ai_report');

      // Process just one item immediately to kick things off.
      // This gives the user immediate feedback that processing has started.
      if ($item = $queue->claimItem()) {
        try {
          $this->logger->info('Processing first queue item immediately for user @uid', [
            '@uid' => $item->data['uid'] ?? 'unknown',
          ]);

          $queue_worker->processItem($item->data);
          $queue->deleteItem($item);

          $this->logger->info('Successfully processed first queue item. Remaining: @remaining', [
            '@remaining' => $queue->numberOfItems(),
          ]);
        }
        catch (\Exception $e) {
          // Release the item back to the queue on failure.
          $queue->releaseItem($item);

          $this->logger->error('Failed to process first queue item: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    catch (\Exception $e) {
      // Log errors but don't fail the overall process.
      // Cron will pick up the queue items later.
      $this->logger->warning('Failed to trigger immediate queue processing: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
