<?php

namespace Drupal\ai_summary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_summary\AiSummaryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Summary reports.
 */
class AiSummaryController extends ControllerBase {

  /**
   * The AI summary service.
   *
   * @var \Drupal\ai_summary\AiSummaryService
   */
  protected $summaryService;

  /**
   * Constructs an AiSummaryController object.
   *
   * @param \Drupal\ai_summary\AiSummaryService $summary_service
   *   The AI summary service.
   */
  public function __construct(AiSummaryService $summary_service) {
    $this->summaryService = $summary_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_summary.service')
    );
  }

  /**
   * Display the AI Summary report.
   *
   * @return array
   *   Render array or redirect.
   */
  public function viewReport() {
    // Check if user has completed minimum required modules.
    if (!$this->summaryService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete all the analysis modules before viewing your summary report.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Check for pending report first.
    $pending = $this->summaryService->getPendingReport();
    if ($pending) {
      return [
        '#theme' => 'ai_summary_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $pending->getGeneratedAt(),
        ],
        '#cache' => ['max-age' => 0],
        '#attached' => ['library' => ['ai_report_storage/report_polling']],
      ];
    }

    // Try to get existing report from cache/database (without generating).
    $report = $this->summaryService->getExistingReport();

    // If no report exists, queue generation.
    if (!$report) {
      $queue_result = $this->summaryService->queueReportGeneration();

      if (!$queue_result) {
        $this->messenger()->addError($this->t('Unable to generate your summary report. AI service may be temporarily unavailable.'));
        return $this->redirect('client_dashboard.dashboard');
      }

      return [
        '#theme' => 'ai_summary_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $queue_result['queued_at'],
        ],
        '#cache' => ['max-age' => 0],
        '#attached' => ['library' => ['ai_report_storage/report_polling']],
      ];
    }

    // Check if report is an error.
    if (is_array($report) && isset($report['error'])) {
      $this->messenger()->addError($report['message'] ?? $this->t('An error occurred generating your report.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'ai_summary_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Regenerate the summary report for the current user.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the report page.
   */
  public function regenerateReport() {
    $uid = $this->currentUser()->id();
    $this->summaryService->clearCache($uid);

    // Queue a new report generation.
    $queue_result = $this->summaryService->queueReportGeneration($uid);

    if ($queue_result) {
      $this->messenger()->addStatus($this->t('Your Summary report is being regenerated. This may take a few minutes...'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to queue report generation. Please try again later.'));
    }

    return new RedirectResponse('/analysis/summary');
  }

}
