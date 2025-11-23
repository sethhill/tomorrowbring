<?php

namespace Drupal\ai_industry_insights\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_industry_insights\AiIndustryInsightsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AiIndustryInsightsController extends ControllerBase {

  protected $analysisService;

  public function __construct(AiIndustryInsightsService $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('ai_industry_insights.analysis_service'));
  }

  public function viewReport() {
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the required modules before viewing this report.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Check for pending report first.
    $pending = $this->analysisService->getPendingReport();
    if ($pending) {
      return [
        '#theme' => 'ai_industry_insights_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $pending->getGeneratedAt(),
        ],
        '#cache' => ['max-age' => 0],
        '#attached' => ['library' => ['ai_report_storage/report_polling']],
      ];
    }

    // Try to get existing report from cache/database (without generating).
    $report = $this->analysisService->getExistingReport();

    // If no report exists, queue generation.
    if (!$report) {
      $queue_result = $this->analysisService->queueReportGeneration();

      if (!$queue_result) {
        $this->messenger()->addError($this->t('Unable to generate industry insights. AI service may be temporarily unavailable.'));
        return $this->redirect('client_dashboard.dashboard');
      }

      return [
        '#theme' => 'ai_industry_insights_report',
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
      '#theme' => 'ai_industry_insights_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
      '#attached' => [
        'library' => ['ai_industry_insights/speedometer'],
      ],
    ];
  }

  public function regenerateAnalysis() {
    $uid = $this->currentUser()->id();
    $this->analysisService->clearCache($uid);

    // Queue a new report generation.
    $queue_result = $this->analysisService->queueReportGeneration($uid);

    if ($queue_result) {
      $this->messenger()->addStatus($this->t('Your report is being regenerated. This may take a few minutes...'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to queue report generation. Please try again later.'));
    }

    return new RedirectResponse('/analysis/industry-insights');
  }

}
