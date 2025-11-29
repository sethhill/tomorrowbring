<?php

namespace Drupal\client_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\client_webform\WebformClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Controller for the member dashboard.
 */
class DashboardController extends ControllerBase {

  /**
   * The webform client manager.
   *
   * @var \Drupal\client_webform\WebformClientManager
   */
  protected $clientManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a DashboardController object.
   *
   * @param \Drupal\client_webform\WebformClientManager $client_manager
   *   The webform client manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(WebformClientManager $client_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->clientManager = $client_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('client_webform.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the page title for the dashboard.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function getTitle() {
    $current_user = $this->currentUser();

    // Load the user's member profile to get their name.
    $profiles = $this->entityTypeManager
      ->getStorage('profile')
      ->loadByProperties([
        'uid' => $current_user->id(),
        'type' => 'member',
      ]);

    if (empty($profiles)) {
      return $this->t('Welcome!');
    }

    $profile = reset($profiles);

    // Get the user's name from their profile field.
    $user_name = '';
    // @phpstan-ignore-next-line
    if ($profile->hasField('field_name') && !$profile->get('field_name')->isEmpty()) {
      // @phpstan-ignore-next-line
      $user_name = $profile->get('field_name')->value;
    }

    if (empty($user_name)) {
      return $this->t('Welcome!');
    }

    // Check if showing summary (all modules completed and reports viewed).
    $showing_summary = $this->isShowingSummary($current_user->id());

    if ($showing_summary) {
      return $this->t('Thank You, @name!', ['@name' => $user_name]);
    }

    return $this->t('Welcome, @name!', ['@name' => $user_name]);
  }

  /**
   * Check if the user should see the summary report.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if summary should be shown.
   */
  protected function isShowingSummary($uid) {
    // Get the user's client.
    $client = $this->clientManager->getCurrentUserClient();

    if (!$client) {
      return FALSE;
    }

    // Get enabled Module nodes for this client.
    $enabled_module_nids = $this->clientManager->getEnabledModules();

    if (empty($enabled_module_nids)) {
      return FALSE;
    }

    // Load Module nodes to calculate progress.
    $module_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($enabled_module_nids);

    // Calculate completion.
    $total = (int) count($module_nodes);
    $completed = 0;

    foreach ($module_nodes as $node) {
      // Get the webform associated with this module.
      $webform_id = NULL;
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_id = $node->get('field_form')->target_id;
      }

      // Check if user has completed this module.
      if ($webform_id) {
        $submission_id = $this->getModuleSubmission($webform_id, $uid);
        if ($submission_id) {
          $submission = $this->entityTypeManager->getStorage('webform_submission')->load($submission_id);
          if ($submission && $submission->get('completed')->value > 0) {
            $completed++;
          }
        }
      }
    }

    // Check if all modules are completed.
    $all_modules_completed = ($total > 0 && (int) $completed === (int) $total);

    if (!$all_modules_completed) {
      return FALSE;
    }

    // Check if all reports have been viewed.
    $report_statuses = $this->getReportStatuses($uid);
    return $this->allReportsViewed($report_statuses);
  }

  /**
   * Displays the member dashboard.
   *
   * @return array
   *   A render array.
   */
  public function view() {
    $current_user = $this->currentUser();

    // Get the user's client.
    $client = $this->clientManager->getCurrentUserClient();

    if (!$client) {
      return [
        '#markup' => $this->t('You have not been assigned to a client. Please contact an administrator.'),
      ];
    }

    // Get enabled Module nodes for this client.
    $enabled_module_nids = $this->clientManager->getEnabledModules();

    if (empty($enabled_module_nids)) {
      return [
        '#markup' => $this->t('No modules have been assigned to your client yet.'),
      ];
    }

    // Load Module nodes to calculate progress.
    $module_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($enabled_module_nids);

    // Calculate progress.
    $total = (int) count($module_nodes);
    $completed = 0;

    foreach ($module_nodes as $node) {
      // Get the webform associated with this module.
      $webform_id = NULL;
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_id = $node->get('field_form')->target_id;
      }

      // Check if user has completed this module.
      if ($webform_id) {
        $submission_id = $this->getModuleSubmission($webform_id, $current_user->id());
        if ($submission_id) {
          $submission = $this->entityTypeManager->getStorage('webform_submission')->load($submission_id);
          if ($submission && $submission->get('completed')->value > 0) {
            $completed++;
          }
        }
      }
    }

    $progress_percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

    // Check if all modules are completed
    $all_modules_completed = ($total > 0 && (int) $completed === (int) $total);

    // Get report statuses for all available report types
    $report_statuses = [];
    if ($all_modules_completed) {
      $report_statuses = $this->getReportStatuses($current_user->id());

      // Check if all reports have been viewed (ready status)
      // If so, show the summary report instead of dashboard
      if ($this->allReportsViewed($report_statuses)) {
        return $this->showSummaryReport($current_user->id());
      }
    }

    // Build the view with enabled module NIDs as arguments.
    $view = \Drupal\views\Views::getView('client_modules');
    $view->setDisplay('block_1');
    $view->setArguments([implode('+', $enabled_module_nids)]);
    $view_render = $view->render();

    return [
      '#theme' => 'client_dashboard',
      '#client_name' => $client->getTitle(),
      '#total_modules' => $total,
      '#completed_modules' => $completed,
      '#progress_percentage' => $progress_percentage,
      '#modules_view' => $view_render,
      '#all_modules_completed' => $all_modules_completed,
      '#report_statuses' => $report_statuses,
      '#attached' => [
        'library' => [
          'client_dashboard/dashboard',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list', 'ai_report_list'],
      ],
    ];
  }

  /**
   * Get the most recent submission for a module by the user.
   *
   * @param string $webform_id
   *   The webform ID.
   * @param int $uid
   *   The user ID.
   *
   * @return int|null
   *   The submission ID if exists (draft or completed), NULL otherwise.
   */
  protected function getModuleSubmission($webform_id, $uid) {
    // Get any submission (draft or completed) by this user for this webform.
    $submissions = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webform_id)
      ->condition('uid', $uid)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return !empty($submissions) ? reset($submissions) : NULL;
  }

  /**
   * Get report statuses for all available report types.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of report statuses keyed by report type.
   */
  protected function getReportStatuses($uid) {
    $statuses = [];

    // Define all available report types with their service IDs and metadata.
    // Note: The 'summary' report type is excluded from the list as it's
    // considered separate content and displayed independently after all
    // other reports are viewed.
    $report_types = [
      'industry_insights' => [
        'service_id' => 'ai_industry_insights.analysis_service',
        'title' => $this->t('Industry Insights'),
        'description' => $this->t('Insights to help you understand the current state of your industry and what the future holds'),
        'url' => '/analysis/industry-insights',
      ],
      'role_impact' => [
        'service_id' => 'ai_role_impact.analysis_service',
        'title' => $this->t('Evolution of Your Role'),
        'description' => $this->t('How AI will impact your role and what you should do about it'),
        'url' => '/analysis/role-impact',
      ],
      'skills' => [
        'service_id' => 'ai_skills_analyzer.analysis_service',
        'title' => $this->t('Improving Your Skills'),
        'description' => $this->t('Skills to help you stay ahead of the curve'),
        'url' => '/analysis/skills',
      ],
      'task_recommendations' => [
        'service_id' => 'ai_task_recommender.analysis_service',
        'title' => $this->t('Automating Tasks'),
        'description' => $this->t('Ideas to help you automate tasks and improve your efficiency'),
        'url' => '/analysis/task-recommendations',
      ],
      'career_transitions' => [
        'service_id' => 'ai_career_transitions.analysis_service',
        'title' => $this->t('Career Opportunities'),
        'description' => $this->t('Finding new career opportunities in your industry'),
        'url' => '/analysis/career-transitions',
      ],
      'learning_resources' => [
        'service_id' => 'ai_learning_resources.analysis_service',
        'title' => $this->t('Learning Resources'),
        'description' => $this->t('Learning resources to support your journey'),
        'url' => '/analysis/learning-resources',
      ],
      'breakthrough_strategies' => [
        'service_id' => 'ai_breakthrough_strategies.service',
        'title' => $this->t('Breakthrough Strategies'),
        'description' => $this->t('Based on your completed assessments, we have generated personalized strategies to help you overcome barriers and build confidence with AI adoption.'),
        'url' => '/analysis/breakthrough-strategies',
      ],
      'concerns_navigator' => [
        'service_id' => 'ai_concerns_navigator.service',
        'title' => $this->t('Concerns Navigator'),
        'description' => $this->t('Based on your completed assessments, we have generated a personalized guide to address your concerns about AI and provide balanced perspectives.'),
        'url' => '/analysis/concerns-navigator',
      ],
    ];

    foreach ($report_types as $type => $info) {
      if (!\Drupal::hasService($info['service_id'])) {
        continue;
      }

      $service = \Drupal::service($info['service_id']);

      // Check if the service has minimum data
      $has_minimum_data = $service->hasMinimumData($uid);

      // If no minimum data, skip this report type entirely
      if (!$has_minimum_data) {
        continue;
      }

      // Check for existing published report
      $existing_report = $service->getExistingReport($uid);

      // Check for pending report
      $pending_report = $service->getPendingReport($uid);

      if ($pending_report) {
        $statuses[$type] = [
          'status' => 'pending',
          'title' => $info['title'],
          'description' => $info['description'],
          'url' => $info['url'],
          'queued_at' => $pending_report->getGeneratedAt(),
          'viewed' => FALSE,
        ];
      }
      elseif ($existing_report) {
        // Load the actual entity to check if it's been viewed.
        $report_storage = $this->entityTypeManager->getStorage('ai_report');
        $entities = $report_storage->loadByProperties([
          'uid' => $uid,
          'type' => $type,
          'status' => 'published',
        ]);
        $report_entity = !empty($entities) ? reset($entities) : NULL;
        $viewed = $report_entity ? $report_entity->isViewed() : FALSE;

        $statuses[$type] = [
          'status' => 'ready',
          'title' => $info['title'],
          'description' => $info['description'],
          'url' => $info['url'],
          'generated_at' => $existing_report['generated_at'],
          'viewed' => $viewed,
        ];
      }
      else {
        $statuses[$type] = [
          'status' => 'not_generated',
          'title' => $info['title'],
          'description' => $info['description'],
          'url' => $info['url'],
          'viewed' => FALSE,
        ];
      }
    }

    return $statuses;
  }

  /**
   * Display the summary report on the dashboard.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Render array for the summary report.
   */
  protected function showSummaryReport($uid) {
    // Check if the summary service exists
    if (!\Drupal::hasService('ai_summary.service')) {
      // Fallback to regular dashboard if service doesn't exist
      return [
        '#markup' => $this->t('Summary report service is not available.'),
      ];
    }

    $summaryService = \Drupal::service('ai_summary.service');

    // Check for pending report first.
    $pending = $summaryService->getPendingReport($uid);
    if ($pending) {
      return [
        '#theme' => 'ai_summary_report',
        '#report' => [
          'status' => 'pending',
          'queued_at' => $pending->getGeneratedAt(),
        ],
        '#cache' => ['max-age' => 0],
        '#attached' => ['library' => ['ai_report_storage/report_polling']],
      ];
    }

    // Try to get existing report from cache/database (without generating).
    $report = $summaryService->getExistingReport($uid);

    // If no report exists, queue generation.
    if (!$report) {
      $queue_result = $summaryService->queueReportGeneration($uid);

      if (!$queue_result) {
        $this->messenger()->addError($this->t('Unable to generate your summary report. AI service may be temporarily unavailable.'));
        return [
          '#markup' => $this->t('Unable to load summary report.'),
        ];
      }

      return [
        '#theme' => 'ai_summary_report',
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
      return [
        '#markup' => $this->t('Unable to load summary report.'),
      ];
    }

    return [
      '#theme' => 'ai_summary_report',
      '#report' => $report,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list'],
      ],
    ];
  }

  /**
   * Check if all reports have been viewed (have 'ready' status).
   *
   * @param array $report_statuses
   *   Array of report statuses from getReportStatuses().
   *
   * @return bool
   *   TRUE if all reports are ready, FALSE otherwise.
   */
  protected function allReportsViewed(array $report_statuses) {
    // No reports means none have been viewed
    if (empty($report_statuses)) {
      return FALSE;
    }

    // Check if all reports (excluding summary) have been viewed
    foreach ($report_statuses as $type => $status) {
      // Skip the summary report itself in this check
      if ($type === 'summary') {
        continue;
      }

      // If any non-summary report is not ready AND viewed, return false
      if (!isset($status['status']) || $status['status'] !== 'ready') {
        return FALSE;
      }

      // Check if the report has been viewed
      if (!isset($status['viewed']) || !$status['viewed']) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
