<?php

namespace Drupal\ai_task_recommender\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_task_recommender\AiTaskRecommenderService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Task Recommender reports.
 */
class AiTaskRecommenderController extends ControllerBase {

  /**
   * The AI task recommender service.
   */
  protected $analysisService;

  /**
   * Constructs an AiTaskRecommenderController object.
   */
  public function __construct(AiTaskRecommenderService $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_task_recommender.analysis_service')
    );
  }

  /**
   * Display the AI task recommendations report.
   */
  public function viewReport() {
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Task Analysis and Current AI Usage modules before viewing task recommendations.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    $report = $this->analysisService->generateReport();

    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate task recommendations. AI service may be temporarily unavailable.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'ai_task_recommender_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Regenerate AI analysis for the current user.
   */
  public function regenerateAnalysis() {
    $uid = $this->currentUser()->id();
    $this->analysisService->clearCache($uid);
    $this->messenger()->addStatus($this->t('Task recommendations are being regenerated...'));
    return new RedirectResponse('/analysis/task-recommendations');
  }

}
