<?php

namespace Drupal\ai_career_transitions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_career_transitions\AiCareerTransitionsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Career Transitions reports.
 */
class AiCareerTransitionsController extends ControllerBase {

  protected $analysisService;

  public function __construct(AiCareerTransitionsService $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_career_transitions.analysis_service')
    );
  }

  public function viewReport() {
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Task Analysis and Skills Gap modules before viewing career transition opportunities.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    $report = $this->analysisService->generateReport();

    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate career transition analysis. AI service may be temporarily unavailable.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'ai_career_transitions_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  public function regenerateAnalysis() {
    $uid = $this->currentUser()->id();
    $this->analysisService->clearCache($uid);
    $this->messenger()->addStatus($this->t('Career transition analysis is being regenerated...'));
    return new RedirectResponse('/analysis/career-transitions');
  }

}
