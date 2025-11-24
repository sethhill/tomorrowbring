<?php

namespace Drupal\ai_concerns_navigator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_concerns_navigator\AiConcernsNavigatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Concerns Navigator reports.
 */
class AiConcernsNavigatorController extends ControllerBase {

  /**
   * The AI concerns navigator service.
   *
   * @var \Drupal\ai_concerns_navigator\AiConcernsNavigatorService
   */
  protected $navigatorService;

  /**
   * Constructs an AiConcernsNavigatorController object.
   *
   * @param \Drupal\ai_concerns_navigator\AiConcernsNavigatorService $navigator_service
   *   The AI concerns navigator service.
   */
  public function __construct(AiConcernsNavigatorService $navigator_service) {
    $this->navigatorService = $navigator_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_concerns_navigator.service')
    );
  }

  /**
   * Display the AI Concerns Navigator report.
   *
   * @return array
   *   Render array or redirect.
   */
  public function viewReport() {
    // Check if user has completed minimum required modules.
    if (!$this->navigatorService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Ethics and Values, and Future Vision modules before viewing your AI Concerns Navigator report.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Check for pending report first.
    $pending = $this->navigatorService->getPendingReport();
    if ($pending) {
      return [
        '#theme' => 'ai_concerns_navigator_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $pending->getGeneratedAt(),
        ],
        '#cache' => ['max-age' => 0],
        '#attached' => ['library' => ['ai_report_storage/report_polling']],
      ];
    }

    // Try to get existing report from cache/database (without generating).
    $report = $this->navigatorService->getExistingReport();

    // If no report exists, queue generation.
    if (!$report) {
      $queue_result = $this->navigatorService->queueReportGeneration();

      if (!$queue_result) {
        $this->messenger()->addError($this->t('Unable to generate your concerns navigator report. AI service may be temporarily unavailable.'));
        return $this->redirect('client_dashboard.dashboard');
      }

      return [
        '#theme' => 'ai_concerns_navigator_report',
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
      '#theme' => 'ai_concerns_navigator_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Regenerate the concerns navigator report for the current user.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the report page.
   */
  public function regenerateReport() {
    $uid = $this->currentUser()->id();
    $this->navigatorService->clearCache($uid);

    // Queue a new report generation.
    $queue_result = $this->navigatorService->queueReportGeneration($uid);

    if ($queue_result) {
      $this->messenger()->addStatus($this->t('Your AI Concerns Navigator report is being regenerated. This may take a few minutes...'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to queue report generation. Please try again later.'));
    }

    return new RedirectResponse('/analysis/concerns-navigator');
  }

}
