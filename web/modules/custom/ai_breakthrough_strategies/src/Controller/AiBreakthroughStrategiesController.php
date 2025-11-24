<?php

namespace Drupal\ai_breakthrough_strategies\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_breakthrough_strategies\AiBreakthroughStrategiesService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Breakthrough Strategies reports.
 */
class AiBreakthroughStrategiesController extends ControllerBase {

  /**
   * The AI breakthrough strategies service.
   *
   * @var \Drupal\ai_breakthrough_strategies\AiBreakthroughStrategiesService
   */
  protected $strategiesService;

  /**
   * Constructs an AiBreakthroughStrategiesController object.
   *
   * @param \Drupal\ai_breakthrough_strategies\AiBreakthroughStrategiesService $strategies_service
   *   The AI breakthrough strategies service.
   */
  public function __construct(AiBreakthroughStrategiesService $strategies_service) {
    $this->strategiesService = $strategies_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_breakthrough_strategies.service')
    );
  }

  /**
   * Display the AI Breakthrough Strategies report.
   *
   * @return array
   *   Render array or redirect.
   */
  public function viewReport() {
    // Check if user has completed minimum required modules.
    if (!$this->strategiesService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Ethics and Values, and Future Vision modules before viewing your Breakthrough Strategies report.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Check for pending report first.
    $pending = $this->strategiesService->getPendingReport();
    if ($pending) {
      return [
        '#theme' => 'ai_breakthrough_strategies_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $pending->getGeneratedAt(),
        ],
        '#cache' => ['max-age' => 0],
        '#attached' => ['library' => ['ai_report_storage/report_polling']],
      ];
    }

    // Try to get existing report from cache/database (without generating).
    $report = $this->strategiesService->getExistingReport();

    // If no report exists, queue generation.
    if (!$report) {
      $queue_result = $this->strategiesService->queueReportGeneration();

      if (!$queue_result) {
        $this->messenger()->addError($this->t('Unable to generate your breakthrough strategies report. AI service may be temporarily unavailable.'));
        return $this->redirect('client_dashboard.dashboard');
      }

      return [
        '#theme' => 'ai_breakthrough_strategies_report',
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
      '#theme' => 'ai_breakthrough_strategies_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Regenerate the breakthrough strategies report for the current user.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the report page.
   */
  public function regenerateReport() {
    $uid = $this->currentUser()->id();
    $this->strategiesService->clearCache($uid);

    // Queue a new report generation.
    $queue_result = $this->strategiesService->queueReportGeneration($uid);

    if ($queue_result) {
      $this->messenger()->addStatus($this->t('Your Breakthrough Strategies report is being regenerated. This may take a few minutes...'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to queue report generation. Please try again later.'));
    }

    return new RedirectResponse('/analysis/breakthrough-strategies');
  }

}
