<?php

namespace Drupal\ai_role_impact\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_role_impact\AiRoleImpactService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for AI Role Impact reports.
 */
class RoleImpactReportController extends ControllerBase {

  protected $analysisService;

  public function __construct(AiRoleImpactService $analysis_service) {
    $this->analysisService = $analysis_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_role_impact.analysis_service')
    );
  }

  public function viewReport() {
    if (!$this->analysisService->hasMinimumData()) {
      $this->messenger()->addWarning($this->t('You need to complete the Task Analysis and Skills Gap modules before viewing your Role Impact Analysis.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    $report = $this->analysisService->generateReport();

    if (!$report) {
      $this->messenger()->addError($this->t('Unable to generate role impact analysis. Please complete the required assessment modules.'));
      return $this->redirect('client_dashboard.dashboard');
    }

    // Check for error responses
    if (isset($report['error'])) {
      $error_messages = [
        'API_TIMEOUT' => $this->t('The AI analysis is taking longer than expected. This is a complex analysis that may require up to 3 minutes to complete. Please try again in a moment, or contact support if the issue persists.'),
        'PARSE_ERROR' => $this->t('We received a response from the AI service but had trouble processing it. Please try regenerating your analysis.'),
        'EXCEPTION' => $this->t('An unexpected error occurred while generating your analysis. Our team has been notified. Please try again later.'),
      ];

      $message = $error_messages[$report['error']] ?? $this->t('Unable to generate your analysis at this time. Please try again later.');
      $this->messenger()->addError($message);

      // Show a helpful message with retry option
      $this->messenger()->addStatus($this->t('You can try <a href="@url">regenerating your analysis</a> or return to your <a href="@dashboard">dashboard</a>.', [
        '@url' => '/analysis/role-impact/regenerate',
        '@dashboard' => '/dashboard',
      ]));

      return [
        '#markup' => '<div class="role-impact-error"><p>' . $message . '</p><p><a href="/analysis/role-impact/regenerate" class="button">Try Again</a> <a href="/dashboard" class="button">Back to Dashboard</a></p></div>',
      ];
    }

    return [
      '#theme' => 'ai_role_impact_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
      '#attached' => [
        'library' => ['ai_role_impact/report'],
      ],
    ];
  }

  public function regenerateAnalysis() {
    $uid = $this->currentUser()->id();
    $this->analysisService->clearCache($uid);
    $this->messenger()->addStatus($this->t('Generating your AI-powered role impact analysis. This comprehensive analysis may take up to 3 minutes to complete. Please wait...'));

    // Pre-generate the report to show real-time feedback
    $report = $this->analysisService->generateReport($uid, TRUE);

    if (isset($report['error'])) {
      $this->messenger()->addWarning($this->t('Analysis generation encountered an issue. Redirecting to the report page where you can try again.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Your analysis has been successfully generated!'));
    }

    return new RedirectResponse('/analysis/role-impact');
  }

}
