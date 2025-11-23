<?php

namespace Drupal\ai_report_storage\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes AI report generation tasks in the background.
 *
 * @QueueWorker(
 *   id = "generate_ai_report",
 *   title = @Translation("AI Report Generator"),
 *   cron = {"time" = 300}
 * )
 */
class GenerateAiReportWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new GenerateAiReportWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('ai_report_storage')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Validate data structure.
    if (!isset($data['service_id']) || !isset($data['uid']) || !isset($data['entity_id'])) {
      $this->logger->error('Invalid queue item data for AI report generation.');
      return;
    }

    $service_id = $data['service_id'];
    $uid = $data['uid'];
    $entity_id = $data['entity_id'];

    try {
      // Load the pending report entity.
      $entity = $this->entityTypeManager->getStorage('ai_report')->load($entity_id);
      if (!$entity) {
        $this->logger->error('AI report entity @id not found for processing.', ['@id' => $entity_id]);
        return;
      }

      // Verify it's still pending (could have been cancelled/regenerated).
      if ($entity->getStatus() !== 'pending') {
        $this->logger->info('AI report entity @id is no longer pending. Skipping.', ['@id' => $entity_id]);
        return;
      }

      // Get the report service.
      $service = \Drupal::service($service_id);
      if (!$service) {
        throw new \Exception("Report service $service_id not found.");
      }

      $this->logger->info('Starting background generation for @type report, user @uid', [
        '@type' => $entity->getType(),
        '@uid' => $uid,
      ]);

      // Generate the report synchronously within the queue worker.
      // We pass force_regenerate=TRUE to skip cache checks and generate fresh.
      $result = $service->generateReportInBackground($uid, $entity_id);

      if ($result === NULL || (is_array($result) && isset($result['error']))) {
        // Generation failed - update entity status to 'failed'.
        $entity->setStatus('failed');
        $entity->save();

        $error_msg = is_array($result) && isset($result['message']) ? $result['message'] : 'Unknown error';
        $this->logger->error('Failed to generate report for user @uid: @error', [
          '@uid' => $uid,
          '@error' => $error_msg,
        ]);
      }
      else {
        $this->logger->info('Successfully generated @type report for user @uid in background', [
          '@type' => $entity->getType(),
          '@uid' => $uid,
        ]);
      }
    }
    catch (\Exception $e) {
      // Mark entity as failed if we can.
      try {
        $entity = $this->entityTypeManager->getStorage('ai_report')->load($entity_id);
        if ($entity) {
          $entity->setStatus('failed');
          $entity->save();
        }
      }
      catch (\Exception $inner_e) {
        // Silently fail on this cleanup attempt.
      }

      $this->logger->error('Exception during AI report generation: @error', [
        '@error' => $e->getMessage(),
      ]);

      // Re-throw so queue system knows it failed.
      throw $e;
    }
  }

}
