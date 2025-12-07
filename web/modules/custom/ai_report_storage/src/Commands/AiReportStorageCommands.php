<?php

namespace Drupal\ai_report_storage\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * Drush commands for AI Report Storage.
 */
class AiReportStorageCommands extends DrushCommands {

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs an AiReportStorageCommands object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_manager,
    LoggerInterface $logger
  ) {
    parent::__construct();
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
    $this->logger = $logger;
  }

  /**
   * Process the AI report generation queue.
   *
   * @param array $options
   *   An associative array of options whose values come from cli.
   *
   * @option limit
   *   Maximum number of items to process. Defaults to all items.
   *
   * @command ai-reports:process-queue
   * @aliases ai-queue,ars-queue
   * @usage ai-reports:process-queue
   *   Process all items in the AI report generation queue.
   * @usage ai-reports:process-queue --limit=5
   *   Process up to 5 items in the queue.
   */
  public function processQueue(array $options = ['limit' => NULL]) {
    $queue = $this->queueFactory->get('generate_ai_report');
    $queue_worker = $this->queueManager->createInstance('generate_ai_report');

    $limit = $options['limit'] ?? NULL;
    $initial_count = $queue->numberOfItems();

    if ($initial_count === 0) {
      $this->output()->writeln('Queue is empty. Nothing to process.');
      return;
    }

    $this->output()->writeln("Starting queue processing. Queue size: {$initial_count}");

    $processed = 0;
    $failed = 0;

    while (($limit === NULL || $processed < $limit) && ($item = $queue->claimItem())) {
      try {
        $report_type = $item->data['service_id'] ?? 'unknown';
        $uid = $item->data['uid'] ?? 'unknown';

        $this->output()->writeln("Processing report for user {$uid} (type: {$report_type})...");

        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;

        $this->output()->writeln("  ✓ Successfully processed");
      }
      catch (\Exception $e) {
        $failed++;
        // Release the item back to the queue on failure.
        $queue->releaseItem($item);

        $this->output()->writeln("  ✗ Failed: " . $e->getMessage());
        $this->logger->error('Queue processing failed: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }

    $remaining = $queue->numberOfItems();

    $this->output()->writeln('');
    $this->output()->writeln('Queue processing complete:');
    $this->output()->writeln("  Processed: {$processed}");
    $this->output()->writeln("  Failed: {$failed}");
    $this->output()->writeln("  Remaining: {$remaining}");
  }

  /**
   * Check the status of the AI report generation queue.
   *
   * @command ai-reports:queue-status
   * @aliases ai-queue-status,ars-status
   * @usage ai-reports:queue-status
   *   Display the current status of the AI report generation queue.
   */
  public function queueStatus() {
    $queue = $this->queueFactory->get('generate_ai_report');
    $count = $queue->numberOfItems();

    $this->output()->writeln('AI Report Generation Queue Status:');
    $this->output()->writeln("  Items in queue: {$count}");

    if ($count === 0) {
      $this->output()->writeln('  Status: Empty');
    }
    else {
      $this->output()->writeln('  Status: Items pending processing');
      $this->output()->writeln('');
      $this->output()->writeln('Run "drush ai-reports:process-queue" to process the queue.');
    }
  }

}



