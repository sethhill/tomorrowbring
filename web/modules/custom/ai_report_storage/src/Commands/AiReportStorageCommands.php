<?php

namespace Drupal\ai_report_storage\Commands;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\ai_report_storage\Service\UserReportRegenerator;
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
   * The user report regenerator service.
   *
   * @var \Drupal\ai_report_storage\Service\UserReportRegenerator
   */
  protected $userReportRegenerator;

  /**
   * Constructs an AiReportStorageCommands object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_manager
   *   The queue worker manager.
   * @param \Drupal\ai_report_storage\Service\UserReportRegenerator $user_report_regenerator
   *   The user report regenerator service.
   */
  public function __construct(
    QueueFactory $queue_factory,
    QueueWorkerManagerInterface $queue_manager,
    UserReportRegenerator $user_report_regenerator
  ) {
    parent::__construct();
    $this->queueFactory = $queue_factory;
    $this->queueManager = $queue_manager;
    $this->userReportRegenerator = $user_report_regenerator;
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
        $this->logger()->error('Queue processing failed: @error', [
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

  /**
   * Regenerate AI reports for a specific user.
   *
   * @param int $uid
   *   The user ID to regenerate reports for.
   * @param array $options
   *   An associative array of options whose values come from cli.
   *
   * @option types
   *   Comma-separated list of report types to regenerate. If not specified, all types will be regenerated.
   * @option sync
   *   Generate reports synchronously instead of queuing them for background processing.
   * @option delete
   *   Delete existing reports before regenerating.
   *
   * @command ai-reports:regenerate-user
   * @aliases ai-regen,ars-regen
   * @usage ai-reports:regenerate-user 123
   *   Queue all reports for user 123 for regeneration.
   * @usage ai-reports:regenerate-user 123 --types=role_impact,skills
   *   Queue only role_impact and skills reports for user 123.
   * @usage ai-reports:regenerate-user 123 --sync
   *   Regenerate all reports for user 123 synchronously (blocks until complete).
   * @usage ai-reports:regenerate-user 123 --delete
   *   Delete existing reports for user 123 before queuing new ones.
   */
  public function regenerateUser(int $uid, array $options = ['types' => NULL, 'sync' => FALSE, 'delete' => FALSE]) {
    $this->output()->writeln("Regenerating reports for user {$uid}...");
    $this->output()->writeln('');

    // Parse report types if specified.
    $report_types = [];
    if (!empty($options['types'])) {
      $report_types = array_map('trim', explode(',', $options['types']));
    }

    // Delete existing reports if requested.
    if ($options['delete']) {
      $this->output()->writeln('Deleting existing reports...');
      $deleted = $this->userReportRegenerator->deleteUserReports($uid, $report_types, FALSE);
      $this->output()->writeln("  Deleted {$deleted} report(s)");
      $this->output()->writeln('');
    }

    // Regenerate reports.
    $queue_mode = !$options['sync'];
    $results = $this->userReportRegenerator->regenerateUserReports($uid, $report_types, $queue_mode, FALSE);

    // Display results.
    $success_count = count($results['success']);
    $failed_count = count($results['failed']);
    $skipped_count = count($results['skipped']);

    if ($success_count > 0) {
      $this->output()->writeln('✓ Successfully ' . ($queue_mode ? 'queued' : 'regenerated') . ' reports:');
      foreach ($results['success'] as $type) {
        $this->output()->writeln("  - {$type}");
      }
      $this->output()->writeln('');
    }

    if ($skipped_count > 0) {
      $this->output()->writeln('⚠ Skipped reports (insufficient data):');
      foreach ($results['skipped'] as $type => $reason) {
        $this->output()->writeln("  - {$type}: {$reason}");
      }
      $this->output()->writeln('');
    }

    if ($failed_count > 0) {
      $this->output()->writeln('✗ Failed reports:');
      foreach ($results['failed'] as $type => $error) {
        $this->output()->writeln("  - {$type}: {$error}");
      }
      $this->output()->writeln('');
    }

    // Summary.
    $this->output()->writeln('Summary:');
    $this->output()->writeln("  Success: {$success_count}");
    $this->output()->writeln("  Skipped: {$skipped_count}");
    $this->output()->writeln("  Failed: {$failed_count}");

    if ($queue_mode && $success_count > 0) {
      $this->output()->writeln('');
      $this->output()->writeln('Reports have been queued for background processing.');
      $this->output()->writeln('Run "drush ai-reports:process-queue" to process them immediately,');
      $this->output()->writeln('or wait for cron to process them automatically.');
    }
  }

  /**
   * Display report statistics for a specific user.
   *
   * @param int $uid
   *   The user ID to check.
   *
   * @command ai-reports:user-stats
   * @aliases ai-stats,ars-stats
   * @usage ai-reports:user-stats 123
   *   Display report statistics for user 123.
   */
  public function userStats(int $uid) {
    $stats = $this->userReportRegenerator->getUserReportStatistics($uid);

    $this->output()->writeln("Report Statistics for User {$uid}:");
    $this->output()->writeln('');

    if (!$stats['has_reports']) {
      $this->output()->writeln('  No reports found for this user.');
      return;
    }

    $this->output()->writeln("  Total Reports: {$stats['total']}");
    $this->output()->writeln('');

    if (!empty($stats['by_type'])) {
      $this->output()->writeln('  By Type:');
      foreach ($stats['by_type'] as $type => $count) {
        $this->output()->writeln("    {$type}: {$count}");
      }
      $this->output()->writeln('');
    }

    if (!empty($stats['by_status'])) {
      $this->output()->writeln('  By Status:');
      foreach ($stats['by_status'] as $status => $count) {
        $this->output()->writeln("    {$status}: {$count}");
      }
    }
  }

  /**
   * Check and fix the ai_report table schema.
   *
   * @command ai-reports:fix-schema
   * @aliases ai-fix-schema
   * @usage ai-reports:fix-schema
   *   Check and repair the ai_report table schema.
   */
  public function fixSchema() {
    $schema = \Drupal\Core\Database\Database::getConnection()->schema();
    $this->output()->writeln('Checking ai_report table schema...');
    $this->output()->writeln('');

    $messages = [];
    $fixed = FALSE;

    // Check for uid field.
    if (!$schema->fieldExists('ai_report', 'uid')) {
      $this->output()->writeln('  ✗ uid field is MISSING');
      try {
        $schema->addField('ai_report', 'uid', [
          'description' => 'The user ID of the report owner.',
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ]);
        $messages[] = 'Added uid field';
        $this->output()->writeln('    ✓ Added uid field');
        $fixed = TRUE;
      }
      catch (\Exception $e) {
        $this->output()->writeln('    ✗ Failed to add uid field: ' . $e->getMessage());
      }
    }
    else {
      $this->output()->writeln('  ✓ uid field exists');
    }

    // Check for status field.
    if (!$schema->fieldExists('ai_report', 'status')) {
      $this->output()->writeln('  ✗ status field is MISSING');
      try {
        $schema->addField('ai_report', 'status', [
          'description' => 'The status of the report.',
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => 'published',
        ]);
        $messages[] = 'Added status field';
        $this->output()->writeln('    ✓ Added status field');
        $fixed = TRUE;
      }
      catch (\Exception $e) {
        $this->output()->writeln('    ✗ Failed to add status field: ' . $e->getMessage());
      }
    }
    else {
      $this->output()->writeln('  ✓ status field exists');
    }

    // Check for indexes.
    if (!$schema->indexExists('ai_report', 'uid_type')) {
      $this->output()->writeln('  ✗ uid_type index is MISSING');
      try {
        $schema->addIndex('ai_report', 'uid_type', ['uid', 'type'], [
          'fields' => [
            'uid' => [],
            'type' => [],
          ],
        ]);
        $messages[] = 'Added uid_type index';
        $this->output()->writeln('    ✓ Added uid_type index');
        $fixed = TRUE;
      }
      catch (\Exception $e) {
        $this->output()->writeln('    ✗ Failed to add uid_type index: ' . $e->getMessage());
      }
    }
    else {
      $this->output()->writeln('  ✓ uid_type index exists');
    }

    if (!$schema->indexExists('ai_report', 'uid_type_status')) {
      $this->output()->writeln('  ✗ uid_type_status index is MISSING');
      try {
        $schema->addIndex('ai_report', 'uid_type_status', ['uid', 'type', 'status'], [
          'fields' => [
            'uid' => [],
            'type' => [],
            'status' => [],
          ],
        ]);
        $messages[] = 'Added uid_type_status index';
        $this->output()->writeln('    ✓ Added uid_type_status index');
        $fixed = TRUE;
      }
      catch (\Exception $e) {
        $this->output()->writeln('    ✗ Failed to add uid_type_status index: ' . $e->getMessage());
      }
    }
    else {
      $this->output()->writeln('  ✓ uid_type_status index exists');
    }

    $this->output()->writeln('');
    if ($fixed) {
      $this->output()->writeln('✓ Schema has been repaired: ' . implode(', ', $messages));
      $this->output()->writeln('');
      $this->output()->writeln('Run "drush cr" to clear caches.');
    }
    else {
      $this->output()->writeln('✓ All schema checks passed. No repairs needed.');
    }
  }

  /**
   * Rebuild the ai_report entity storage schema definitions.
   *
   * @command ai-reports:rebuild-entity-schema
   * @aliases ai-rebuild-schema
   * @usage ai-reports:rebuild-entity-schema
   *   Rebuild the stored entity schema definitions for ai_report.
   */
  public function rebuildEntitySchema() {
    $this->output()->writeln('Rebuilding ai_report entity storage schema...');
    $this->output()->writeln('');

    try {
      $database = \Drupal::database();
      $entity_type_manager = \Drupal::entityTypeManager();

      // Step 1: Delete corrupted schema data from key_value table.
      $deleted = $database->delete('key_value')
        ->condition('collection', 'entity.storage_schema.sql')
        ->condition('name', 'ai_report%', 'LIKE')
        ->execute();

      $this->output()->writeln("  ✓ Deleted {$deleted} corrupted schema definition(s)");

      // Step 2: Clear entity-related caches.
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
      \Drupal::service('entity_type.manager')->clearCachedDefinitions();
      \Drupal::service('entity.memory_cache')->deleteAll();

      $this->output()->writeln('  ✓ Cleared entity caches');

      // Step 3: Get entity type and storage.
      $entity_type = $entity_type_manager->getDefinition('ai_report');
      $storage = $entity_type_manager->getStorage('ai_report');

      // Step 4: Get the SQL storage schema handler.
      // Since entity uses SqlContentEntityStorage, we instantiate the schema handler manually.
      $storage_schema = new \Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema(
        $entity_type_manager,
        $entity_type,
        $storage,
        $database,
        \Drupal::service('entity_field.manager')
      );

      $this->output()->writeln('  ✓ Created storage schema handler');

      // Step 5: Get field definitions to verify they're all known.
      $field_definitions = \Drupal::service('entity_field.manager')
        ->getFieldStorageDefinitions('ai_report');

      $this->output()->writeln('  ✓ Found ' . count($field_definitions) . ' field definitions');

      // Step 6: Force schema recreation.
      // First check current state.
      $table_mapping = $storage->getTableMapping();
      $field_names = $table_mapping->getFieldNames('ai_report');

      $this->output()->writeln('  Current table mapping knows about ' . count($field_names) . ' fields');

      if (count($field_names) < 10) {
        $this->output()->writeln('  Schema incomplete, forcing recreation...');

        // Manually build the complete schema definition based on the .install file.
        // This matches exactly what hook_schema() defines.
        $complete_schema = [
          'ai_report' => [
            'fields' => [
              'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
              'uuid' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
              'type' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
              'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
              'report_data' => ['type' => 'text', 'size' => 'big', 'not null' => TRUE],
              'version' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 1],
              'status' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => 'published'],
              'generated_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
              'generation_time' => ['type' => 'numeric', 'precision' => 10, 'scale' => 3],
              'model_used' => ['type' => 'varchar', 'length' => 128],
              'source_data_hash' => ['type' => 'varchar', 'length' => 32],
              'source_submissions' => ['type' => 'text', 'size' => 'normal'],
              'viewed_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
              'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
            ],
            'primary key' => ['id'],
            'unique keys' => ['uuid' => ['uuid']],
            'indexes' => [
              'uid_type' => ['uid', 'type'],
              'uid_type_version' => ['uid', 'type', 'version'],
              'uid_type_status' => ['uid', 'type', 'status'],
              'status' => ['status'],
              'generated_at' => ['generated_at'],
              'type' => ['type'],
            ],
          ],
        ];

        // Store this schema in the key_value table.
        \Drupal::keyValue('entity.storage_schema.sql')->set(
          'ai_report.entity_schema_data',
          $complete_schema
        );

        $this->output()->writeln('  ✓ Manually recreated complete schema in key_value');

        // Also need to store field schema definitions for each field.
        foreach ($field_definitions as $field_name => $definition) {
          // Store each field's schema.
          \Drupal::keyValue('entity.storage_schema.sql')->set(
            "ai_report.field_schema_data.{$field_name}",
            ['ai_report' => ['fields' => [$field_name => $complete_schema['ai_report']['fields'][$field_name] ?? []]]]
          );
        }

        $this->output()->writeln('  ✓ Stored field schema definitions for ' . count($field_definitions) . ' fields');
      }
      else {
        $this->output()->writeln('  ✓ Schema appears complete');
      }

      // Step 7: Clear all caches one final time.
      drupal_flush_all_caches();
      $this->output()->writeln('  ✓ Flushed all caches');

      // Step 8: Test entity query.
      $this->output()->writeln('');
      $this->output()->writeln('Testing entity query...');

      // Get fresh storage after cache clear.
      $storage = $entity_type_manager->getStorage('ai_report');
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', 1)
        ->range(0, 1);

      try {
        $ids = $query->execute();
        $this->output()->writeln('  ✓ Entity query with uid field works!');
        $this->output()->writeln('');
        $this->output()->writeln('✓ Entity schema has been successfully rebuilt!');
      }
      catch (\Exception $e) {
        $this->output()->writeln('  ✗ Entity query still failing: ' . $e->getMessage());
        $this->output()->writeln('');
        $this->output()->writeln('⚠ Schema rebuild attempted but queries still fail.');
        $this->output()->writeln('  Checking table mapping after rebuild:');

        // Debug: show what fields the table mapping knows about.
        $storage = $entity_type_manager->getStorage('ai_report');
        $table_mapping = $storage->getTableMapping();
        $known_fields = $table_mapping->getFieldNames('ai_report');
        $this->output()->writeln('  Table mapping knows about: ' . implode(', ', $known_fields));
      }
    }
    catch (\Exception $e) {
      $this->output()->writeln('');
      $this->output()->writeln('✗ Error: ' . $e->getMessage());
      $this->output()->writeln('  Trace: ' . $e->getTraceAsString());
    }
  }

  /**
   * Nuclear option: completely reinstall ai_report entity from scratch.
   *
   * WARNING: This command forcefully reinstalls all field definitions.
   * Only use this if ai-rebuild-schema fails.
   *
   * @command ai-reports:nuclear-rebuild
   * @aliases ai-nuclear
   * @usage ai-reports:nuclear-rebuild
   *   Forcefully reinstall all ai_report field storage definitions.
   */
  public function nuclearRebuild() {
    $this->output()->writeln('⚠ WARNING: Nuclear rebuild of ai_report entity storage');
    $this->output()->writeln('');

    try {
      $entity_type_manager = \Drupal::entityTypeManager();
      $entity_definition_update_manager = \Drupal::entityDefinitionUpdateManager();
      $database = \Drupal::database();
      $field_manager = \Drupal::service('entity_field.manager');

      // Step 1: Delete ALL schema data from key_value.
      $deleted = $database->delete('key_value')
        ->condition('collection', 'entity.storage_schema.sql')
        ->condition('name', 'ai_report%', 'LIKE')
        ->execute();

      $this->output()->writeln("  ✓ Deleted {$deleted} schema entries from key_value");

      // Step 2: Delete entity last installed definitions.
      $deleted = $database->delete('key_value')
        ->condition('collection', 'entity.definitions.installed')
        ->condition('name', 'ai_report%', 'LIKE')
        ->execute();

      $this->output()->writeln("  ✓ Deleted {$deleted} installed definition entries");

      // Step 3: Clear all caches.
      drupal_flush_all_caches();
      $this->output()->writeln('  ✓ Flushed all caches');

      // Step 4: Get fresh field definitions.
      $entity_type = $entity_type_manager->getDefinition('ai_report');
      $field_storage_definitions = $field_manager->getFieldStorageDefinitions('ai_report');

      $this->output()->writeln('  ✓ Found ' . count($field_storage_definitions) . ' field definitions');
      $this->output()->writeln('');
      $this->output()->writeln('  Registering field storage definitions...');

      // Step 5: Manually register each field as "installed" by calling
      // the protected method through reflection.
      $last_installed_key = \Drupal::keyValue('entity.definitions.installed');

      // Store the entity type definition.
      $last_installed_key->set('ai_report.entity_type', $entity_type);
      $this->output()->writeln('    ✓ Registered entity type definition');

      // Store field storage definitions.
      $last_installed_key->set('ai_report.field_storage_definitions', $field_storage_definitions);
      $this->output()->writeln('    ✓ Registered ' . count($field_storage_definitions) . ' field storage definitions');

      // Step 6: Final cache clear - this will cause Drupal to regenerate schemas.
      drupal_flush_all_caches();
      $this->output()->writeln('');
      $this->output()->writeln('  ✓ Final cache clear');

      // Step 7: Test entity query.
      $this->output()->writeln('');
      $this->output()->writeln('Testing entity query...');

      $storage = $entity_type_manager->getStorage('ai_report');
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', 1)
        ->range(0, 1);

      try {
        $ids = $query->execute();
        $this->output()->writeln('  ✓ Entity query with uid field WORKS!');
        $this->output()->writeln('');
        $this->output()->writeln('✓ Nuclear rebuild SUCCESSFUL!');
      }
      catch (\Exception $e) {
        $this->output()->writeln('  ✗ Entity query still failing: ' . $e->getMessage());
        $this->output()->writeln('');
        $this->output()->writeln('⚠ Nuclear rebuild completed but queries still fail.');

        // Final diagnostic.
        $table_mapping = $storage->getTableMapping();
        $known_fields = $table_mapping->getFieldNames('ai_report');
        $this->output()->writeln('  Table mapping knows about: ' . implode(', ', $known_fields));
      }
    }
    catch (\Exception $e) {
      $this->output()->writeln('');
      $this->output()->writeln('✗ Error: ' . $e->getMessage());
      $this->output()->writeln('');
      $this->output()->writeln('Stack trace:');
      $this->output()->writeln($e->getTraceAsString());
    }
  }

}









