<?php

namespace Drupal\ai_report_storage\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\ai_report_storage\AiReportManager;
use Psr\Log\LoggerInterface;

/**
 * Service for regenerating AI reports for specific users.
 */
class UserReportRegenerator {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The AI report manager.
   *
   * @var \Drupal\ai_report_storage\AiReportManager
   */
  protected $reportManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a UserReportRegenerator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ai_report_storage\AiReportManager $report_manager
   *   The AI report manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AiReportManager $report_manager,
    MessengerInterface $messenger,
    LoggerInterface $logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->reportManager = $report_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * Regenerate reports for a specific user.
   *
   * @param int $uid
   *   The user ID.
   * @param array $report_types
   *   Array of report types to regenerate. Empty array means all types.
   * @param bool $queue
   *   Whether to queue reports for background processing (TRUE) or generate synchronously (FALSE).
   * @param bool $show_messages
   *   Whether to show user-facing messages.
   *
   * @return array
   *   Array with keys:
   *   - success: Array of successfully queued/regenerated report types.
   *   - failed: Array of failed report types with error messages.
   *   - skipped: Array of skipped report types (no data).
   */
  public function regenerateUserReports(int $uid, array $report_types = [], bool $queue = TRUE, bool $show_messages = TRUE): array {
    $results = [
      'success' => [],
      'failed' => [],
      'skipped' => [],
    ];

    // Load the user to validate.
    $user_storage = $this->entityTypeManager->getStorage('user');
    $user = $user_storage->load($uid);

    if (!$user) {
      $message = $this->t('User @uid not found.', ['@uid' => $uid]);
      $this->logger->error($message);
      if ($show_messages) {
        $this->messenger->addError($message);
      }
      return $results;
    }

    // Get all available report types if none specified.
    if (empty($report_types)) {
      $report_types = array_keys($this->reportManager->getAvailableReportTypes());
    }

    $this->logger->info('Starting report regeneration for user @uid. Report types: @types. Queue mode: @queue', [
      '@uid' => $uid,
      '@types' => implode(', ', $report_types),
      '@queue' => $queue ? 'yes' : 'no',
    ]);

    foreach ($report_types as $report_type) {
      try {
        // Get the report service for this type.
        $report_service = $this->reportManager->getReportService($report_type);

        if (!$report_service) {
          $results['failed'][$report_type] = $this->t('Report service not found');
          $this->logger->warning('Report service not found for type: @type', ['@type' => $report_type]);
          continue;
        }

        // Check if user has minimum required data.
        if (!$report_service->hasMinimumData($uid)) {
          $results['skipped'][$report_type] = $this->t('Insufficient data');
          $this->logger->info('Skipping @type for user @uid - insufficient data', [
            '@type' => $report_type,
            '@uid' => $uid,
          ]);
          continue;
        }

        if ($queue) {
          // Queue for background processing.
          $queued = $report_service->queueReportGeneration($uid);
          if ($queued) {
            $results['success'][] = $report_type;
            $this->logger->info('Queued @type report for user @uid', [
              '@type' => $report_type,
              '@uid' => $uid,
            ]);
          }
          else {
            $results['failed'][$report_type] = $this->t('Failed to queue');
            $this->logger->error('Failed to queue @type report for user @uid', [
              '@type' => $report_type,
              '@uid' => $uid,
            ]);
          }
        }
        else {
          // Generate synchronously with force flag.
          $report = $report_service->generateReport($uid, TRUE);
          if ($report) {
            $results['success'][] = $report_type;
            $this->logger->info('Generated @type report for user @uid', [
              '@type' => $report_type,
              '@uid' => $uid,
            ]);
          }
          else {
            $results['failed'][$report_type] = $this->t('Generation failed');
            $this->logger->error('Failed to generate @type report for user @uid', [
              '@type' => $report_type,
              '@uid' => $uid,
            ]);
          }
        }
      }
      catch (\Exception $e) {
        $results['failed'][$report_type] = $e->getMessage();
        $this->logger->error('Error regenerating @type report for user @uid: @error', [
          '@type' => $report_type,
          '@uid' => $uid,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Show summary messages if requested.
    if ($show_messages) {
      $this->showSummaryMessages($results, $queue);
    }

    return $results;
  }

  /**
   * Delete all reports for a specific user.
   *
   * @param int $uid
   *   The user ID.
   * @param array $report_types
   *   Array of report types to delete. Empty array means all types.
   * @param bool $show_messages
   *   Whether to show user-facing messages.
   *
   * @return int
   *   Number of reports deleted.
   */
  public function deleteUserReports(int $uid, array $report_types = [], bool $show_messages = TRUE): int {
    $count = 0;

    if (empty($report_types)) {
      $count = $this->reportManager->deleteUserReports($uid);
    }
    else {
      foreach ($report_types as $report_type) {
        $count += $this->reportManager->deleteUserReports($uid, $report_type);
      }
    }

    $this->logger->info('Deleted @count reports for user @uid', [
      '@count' => $count,
      '@uid' => $uid,
    ]);

    if ($show_messages && $count > 0) {
      $this->messenger->addStatus($this->t('Deleted @count report(s).', ['@count' => $count]));
    }

    return $count;
  }

  /**
   * Get report statistics for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Statistics array with counts by type and status.
   */
  public function getUserReportStatistics(int $uid): array {
    $stats = [
      'total' => 0,
      'by_type' => [],
      'by_status' => [],
      'has_reports' => FALSE,
    ];

    $reports = $this->reportManager->getUserReports($uid);

    if (empty($reports)) {
      return $stats;
    }

    $stats['has_reports'] = TRUE;
    $stats['total'] = count($reports);

    foreach ($reports as $report) {
      $type = $report->getType();
      $status = $report->getStatus();

      if (!isset($stats['by_type'][$type])) {
        $stats['by_type'][$type] = 0;
      }
      $stats['by_type'][$type]++;

      if (!isset($stats['by_status'][$status])) {
        $stats['by_status'][$status] = 0;
      }
      $stats['by_status'][$status]++;
    }

    return $stats;
  }

  /**
   * Show summary messages about regeneration results.
   *
   * @param array $results
   *   Results array from regenerateUserReports().
   * @param bool $queued
   *   Whether reports were queued or generated synchronously.
   */
  protected function showSummaryMessages(array $results, bool $queued): void {
    $success_count = count($results['success']);
    $failed_count = count($results['failed']);
    $skipped_count = count($results['skipped']);

    if ($success_count > 0) {
      if ($queued) {
        $this->messenger->addStatus($this->t('Successfully queued @count report(s) for regeneration: @types', [
          '@count' => $success_count,
          '@types' => implode(', ', $results['success']),
        ]));
      }
      else {
        $this->messenger->addStatus($this->t('Successfully regenerated @count report(s): @types', [
          '@count' => $success_count,
          '@types' => implode(', ', $results['success']),
        ]));
      }
    }

    if ($skipped_count > 0) {
      $this->messenger->addWarning($this->t('Skipped @count report(s) due to insufficient data: @types', [
        '@count' => $skipped_count,
        '@types' => implode(', ', array_keys($results['skipped'])),
      ]));
    }

    if ($failed_count > 0) {
      $failed_list = [];
      foreach ($results['failed'] as $type => $error) {
        $failed_list[] = $type . ' (' . $error . ')';
      }
      $this->messenger->addError($this->t('Failed to regenerate @count report(s): @types', [
        '@count' => $failed_count,
        '@types' => implode(', ', $failed_list),
      ]));
    }

    if ($success_count === 0 && $failed_count === 0 && $skipped_count === 0) {
      $this->messenger->addWarning($this->t('No reports to regenerate.'));
    }
  }

}
