<?php

namespace Drupal\ai_learning_resources\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_learning_resources\AiLearningResourcesService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Learning Resources reports.
 */
class LearningResourcesReportController extends ControllerBase {

  /**
   * The AI learning resources service.
   *
   * @var \Drupal\ai_learning_resources\AiLearningResourcesService
   */
  protected $analysisService;

  /**
   * Constructs a LearningResourcesReportController object.
   *
   * @param \Drupal\ai_learning_resources\AiLearningResourcesService $analysis_service
   *   The AI learning resources service.
   */
  public function __construct(AiLearningResourcesService $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_learning_resources.analysis_service')
    );
  }

  /**
   * Display the learning resources report.
   *
   * @return array
   *   Render array or redirect.
   */
  public function viewReport() {
    // Check if user has completed minimum required modules.
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Skills Gap and Task Analysis modules before viewing your Learning Resources.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Check for pending report first.
    $pending = $this->analysisService->getPendingReport();
    if ($pending) {
      return [
        '#theme' => 'ai_learning_resources_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $pending->getGeneratedAt(),
        ],
        '#cache' => [
          'max-age' => 0,
        ],
        '#attached' => [
          'library' => ['ai_report_storage/report_polling'],
        ],
      ];
    }

    // Try to get existing report from cache/database (without generating).
    $report = $this->analysisService->getExistingReport();

    // If no report exists, queue generation.
    if (!$report) {
      $queue_result = $this->analysisService->queueReportGeneration();

      if (!$queue_result) {
        $this->messenger()->addError($this->t('Unable to generate your learning resources. AI service may be temporarily unavailable.'));
        return $this->redirect('client_dashboard.dashboard');
      }

      return [
        '#theme' => 'ai_learning_resources_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $queue_result['queued_at'],
        ],
        '#cache' => [
          'max-age' => 0,
        ],
        '#attached' => [
          'library' => ['ai_report_storage/report_polling'],
        ],
      ];
    }

    // Check if report is an error.
    if (is_array($report) && isset($report['error'])) {
      $this->messenger()->addError($report['message'] ?? $this->t('An error occurred generating your report.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'ai_learning_resources_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Regenerate learning resources for the current user.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the report page.
   */
  public function regenerateAnalysis() {
    $uid = $this->currentUser()->id();

    // Clear the cache for this user and archive any existing reports.
    $this->analysisService->clearCache($uid);

    // Queue a new report generation.
    $queue_result = $this->analysisService->queueReportGeneration($uid);

    if ($queue_result) {
      $this->messenger()->addStatus($this->t('Your learning resources are being regenerated. This may take a few minutes...'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to queue report generation. Please try again later.'));
    }

    return new RedirectResponse('/analysis/learning-resources');
  }

}
