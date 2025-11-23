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

    // Check for pending report first.
    $pending = $this->analysisService->getPendingReport();
    if ($pending) {
      return [
        '#theme' => 'ai_skills_analysis_report',
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
        $this->messenger()->addError($this->t('Unable to generate your skills analysis. AI service may be temporarily unavailable.'));
        return $this->redirect('client_dashboard.dashboard');
      }

      return [
        '#theme' => 'ai_skills_analysis_report',
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
    $this->analysisService->clearCache($uid);

    // Queue a new report generation.
    $queue_result = $this->analysisService->queueReportGeneration($uid);

    if ($queue_result) {
      $this->messenger()->addStatus($this->t('Your skills analysis is being regenerated. This may take a few minutes...'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to queue report generation. Please try again later.'));
    }

    return new RedirectResponse('/analysis/skills');
  }

}
