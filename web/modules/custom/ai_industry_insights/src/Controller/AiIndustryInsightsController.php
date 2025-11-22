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
      $this->messenger()->addWarning($this->t('You need to complete the Task Analysis module before viewing industry insights.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    $report = $this->analysisService->generateReport();
    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate industry insights. AI service may be temporarily unavailable.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'ai_industry_insights_report',
      '#report' => $report,
      '#attached' => [
        'library' => [
          'ai_industry_insights/speedometer',
        ],
      ],
      '#cache' => ['contexts' => ['user'], 'tags' => ['webform_submission_list']],
    ];
  }

  public function regenerateAnalysis() {
    $this->analysisService->clearCache($this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Industry insights are being regenerated...'));
    return new RedirectResponse('/analysis/industry-insights');
  }

}
