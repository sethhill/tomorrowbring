<?php

namespace Drupal\ai_report_storage\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai_report_storage\Service\UserReportRegenerator;
use Drupal\ai_report_storage\AiReportManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for regenerating AI reports for a specific user.
 */
class RegenerateUserReportsForm extends FormBase {

  /**
   * The user report regenerator service.
   *
   * @var \Drupal\ai_report_storage\Service\UserReportRegenerator
   */
  protected $userReportRegenerator;

  /**
   * The AI report manager.
   *
   * @var \Drupal\ai_report_storage\AiReportManager
   */
  protected $reportManager;

  /**
   * Constructs a RegenerateUserReportsForm object.
   *
   * @param \Drupal\ai_report_storage\Service\UserReportRegenerator $user_report_regenerator
   *   The user report regenerator service.
   * @param \Drupal\ai_report_storage\AiReportManager $report_manager
   *   The AI report manager.
   */
  public function __construct(
    UserReportRegenerator $user_report_regenerator,
    AiReportManager $report_manager
  ) {
    $this->userReportRegenerator = $user_report_regenerator;
    $this->reportManager = $report_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_report_storage.user_report_regenerator'),
      $container->get('ai_report_storage.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_report_storage_regenerate_user_reports_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL) {
    if (!$user) {
      $form['error'] = [
        '#markup' => $this->t('User not found.'),
      ];
      return $form;
    }

    // Store the user in form state.
    $form_state->set('user', $user);

    // Get report statistics.
    try {
      $stats = $this->userReportRegenerator->getUserReportStatistics($user->id());
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_report_storage')->error('Error getting report statistics for user @uid: @error', [
        '@uid' => $user->id(),
        '@error' => $e->getMessage(),
      ]);
      $stats = [
        'total' => 0,
        'by_type' => [],
        'by_status' => [],
        'has_reports' => FALSE,
      ];
    }

    // Display user information.
    $form['user_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User Information'),
    ];

    $form['user_info']['name'] = [
      '#markup' => '<p><strong>' . $this->t('Username:') . '</strong> ' . $user->getAccountName() . '</p>',
    ];

    $form['user_info']['email'] = [
      '#markup' => '<p><strong>' . $this->t('Email:') . '</strong> ' . $user->getEmail() . '</p>',
    ];

    $form['user_info']['uid'] = [
      '#markup' => '<p><strong>' . $this->t('User ID:') . '</strong> ' . $user->id() . '</p>',
    ];

    // Display current report statistics.
    if ($stats['has_reports']) {
      $form['current_reports'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Current Reports'),
      ];

      $form['current_reports']['total'] = [
        '#markup' => '<p><strong>' . $this->t('Total Reports:') . '</strong> ' . $stats['total'] . '</p>',
      ];

      if (!empty($stats['by_type'])) {
        $type_list = [];
        foreach ($stats['by_type'] as $type => $count) {
          $type_list[] = $type . ' (' . $count . ')';
        }
        $form['current_reports']['by_type'] = [
          '#markup' => '<p><strong>' . $this->t('By Type:') . '</strong> ' . implode(', ', $type_list) . '</p>',
        ];
      }

      if (!empty($stats['by_status'])) {
        $status_list = [];
        foreach ($stats['by_status'] as $status => $count) {
          $status_list[] = $status . ' (' . $count . ')';
        }
        $form['current_reports']['by_status'] = [
          '#markup' => '<p><strong>' . $this->t('By Status:') . '</strong> ' . implode(', ', $status_list) . '</p>',
        ];
      }
    }
    else {
      $form['no_reports'] = [
        '#markup' => '<p>' . $this->t('This user currently has no reports.') . '</p>',
      ];
    }

    // Report type selection.
    $available_types = $this->reportManager->getAvailableReportTypes();

    $form['report_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select Report Types to Regenerate'),
      '#options' => $available_types,
      '#default_value' => array_keys($available_types),
      '#description' => $this->t('Select which report types to regenerate. Only reports with sufficient data will be generated.'),
    ];

    $form['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Select/Deselect All'),
      '#default_value' => TRUE,
    ];

    // Options.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Options'),
    ];

    $form['options']['queue_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Generation Mode'),
      '#options' => [
        'queue' => $this->t('Queue for background processing (recommended)'),
        'sync' => $this->t('Generate immediately (may take several minutes)'),
      ],
      '#default_value' => 'queue',
      '#description' => $this->t('Queuing allows reports to be generated in the background without blocking this page.'),
    ];

    $form['options']['delete_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete existing reports before regenerating'),
      '#default_value' => FALSE,
      '#description' => $this->t('Warning: This will permanently delete all existing reports and their version history.'),
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate Reports'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.user.canonical', ['user' => $user->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    // Add JavaScript for select all functionality.
    $form['#attached']['library'][] = 'ai_report_storage/regenerate_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $selected_types = array_filter($form_state->getValue('report_types'));

    if (empty($selected_types)) {
      $form_state->setErrorByName('report_types', $this->t('Please select at least one report type to regenerate.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = $form_state->get('user');
    $selected_types = array_filter($form_state->getValue('report_types'));
    $queue_mode = $form_state->getValue('queue_mode') === 'queue';
    $delete_existing = $form_state->getValue('delete_existing');

    // Delete existing reports if requested.
    if ($delete_existing) {
      $this->userReportRegenerator->deleteUserReports($user->id(), $selected_types, TRUE);
    }

    // Regenerate reports.
    $this->userReportRegenerator->regenerateUserReports($user->id(), $selected_types, $queue_mode, TRUE);

    // Redirect back to user profile.
    $form_state->setRedirect('entity.user.canonical', ['user' => $user->id()]);
  }

}
