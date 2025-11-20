<?php

namespace Drupal\ai_skills_analyzer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_skills_analyzer\AiSkillsAnalysisService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Skills Analysis reports.
 */
class AiSkillsReportController extends ControllerBase {

  /**
   * The AI skills analysis service.
   *
   * @var \Drupal\ai_skills_analyzer\AiSkillsAnalysisService
   */
  protected $analysisService;

  /**
   * Constructs an AiSkillsReportController object.
   *
   * @param \Drupal\ai_skills_analyzer\AiSkillsAnalysisService $analysis_service
   *   The AI skills analysis service.
   */
  public function __construct(AiSkillsAnalysisService $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_skills_analyzer.analysis_service')
    );
  }

  /**
   * Display the AI skills analysis report.
   *
   * @return array
   *   Render array or redirect.
   */
  public function viewReport() {
    // Check if user has completed minimum required modules.
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Skills Gap and Task Analysis modules before viewing your AI Skills Analysis.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Generate the report.
    $report = $this->analysisService->generateReport();

    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate your skills analysis. AI service may be temporarily unavailable.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'ai_skills_analysis_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Regenerate AI analysis for the current user.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the report page.
   */
  public function regenerateAnalysis() {
    $uid = $this->currentUser()->id();

    // Clear the cache for this user.
    $this->analysisService->clearCache($uid);

    $this->messenger()->addStatus($this->t('Skills analysis is being regenerated...'));

    return new RedirectResponse('/analysis/skills');
  }

}
