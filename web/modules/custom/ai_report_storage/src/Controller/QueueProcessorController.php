<?php

namespace Drupal\ai_report_storage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for processing AI report queue items.
 */
class QueueProcessorController extends ControllerBase {

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
   * Constructs a QueueProcessorController.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   */
  public function __construct(QueueFactory $queue_factory, QueueWorkerManagerInterface $queue_manager) {
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker')
    );
  }

  /**
   * Process pending queue items.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with processing results.
   */
  public function process() {
    $queue = $this->queueFactory->get('generate_ai_report');
    $queue_worker = $this->queueManager->createInstance('generate_ai_report');

    $processed = 0;
    $failed = 0;

    // Process up to 5 items at a time
    while ($processed < 5 && ($item = $queue->claimItem())) {
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Exception $e) {
        $failed++;
        // Log the error but don't re-queue (let the item expire).
        \Drupal::logger('ai_report_storage')->error('Queue processing failed: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return new JsonResponse([
      'processed' => $processed,
      'failed' => $failed,
      'remaining' => $queue->numberOfItems(),
    ]);
  }

}
