<?php

namespace Drupal\role_impact_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\role_impact_analysis\RoleImpactAnalysis;
use Drupal\role_impact_analysis\Service\AiAnalysisService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for Role Impact Analysis reports.
 */
class RoleImpactReportController extends ControllerBase {

  /**
   * The role impact analysis service.
   *
   * @var \Drupal\role_impact_analysis\RoleImpactAnalysis
   */
  protected $analysisService;

  /**
   * The AI analysis service.
   *
   * @var \Drupal\role_impact_analysis\Service\AiAnalysisService
   */
  protected $aiService;

  /**
   * Constructs a RoleImpactReportController object.
   *
   * @param \Drupal\role_impact_analysis\RoleImpactAnalysis $analysis_service
   *   The role impact analysis service.
   * @param \Drupal\role_impact_analysis\Service\AiAnalysisService $ai_service
   *   The AI analysis service.
   */
  public function __construct(RoleImpactAnalysis $analysis_service, AiAnalysisService $ai_service) {
    $this->analysisService = $analysis_service;
    $this->aiService = $ai_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('role_impact_analysis.analysis_service'),
      $container->get('role_impact_analysis.ai_service')
    );
  }

  /**
   * Display the role impact analysis report.
   *
   * @return array|Response
   *   Render array or response.
   */
  public function viewReport() {
    // Check if user has completed minimum required modules
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete at least the Task Analysis and Skills Gap modules before viewing your Role Impact Analysis.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Generate the report
    $report = $this->analysisService->generateReport();

    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate your analysis report. Please ensure you have completed the required assessment modules.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    return [
      '#theme' => 'role_impact_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Get the title for the report page.
   *
   * @return string
   *   The page title.
   */
  public function getTitle() {
    return $this->t('Your Role Impact Analysis');
  }

  /**
   * Regenerate AI analysis for the current user.
   *
   * Clears the cached AI insights and redirects back to the report,
   * which will trigger fresh AI analysis.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the report page.
   */
  public function regenerateAiAnalysis() {
    $uid = $this->currentUser()->id();

    // Clear the AI insights cache for this user.
    $this->aiService->clearCache($uid);

    // Add a message to inform the user.
    $this->messenger()->addStatus($this->t('AI analysis is being regenerated. This may take a moment...'));

    // Redirect back to the report page.
    return new RedirectResponse('/analysis/role-impact');
  }

}