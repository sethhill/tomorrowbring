<?php

namespace Drupal\role_impact_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\role_impact_analysis\RoleImpactAnalysis;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
   * Constructs a RoleImpactReportController object.
   *
   * @param \Drupal\role_impact_analysis\RoleImpactAnalysis $analysis_service
   *   The role impact analysis service.
   */
  public function __construct(RoleImpactAnalysis $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('role_impact_analysis.analysis_service')
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
      return $this->redirect('webform_client_manager.dashboard');
    }

    // Generate the report
    $report = $this->analysisService->generateReport();

    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate your analysis report. Please ensure you have completed the required assessment modules.'));
      return $this->redirect('webform_client_manager.dashboard');
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

}