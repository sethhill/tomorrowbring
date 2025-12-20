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

}









