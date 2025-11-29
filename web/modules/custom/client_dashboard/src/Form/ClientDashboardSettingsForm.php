<?php

namespace Drupal\client_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Client Dashboard settings.
 */
class ClientDashboardSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'client_dashboard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['client_dashboard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('client_dashboard.settings');

    $form['auto_processing'] = [
      '#type' => 'details',
      '#title' => $this->t('Automatic Report Processing'),
      '#open' => TRUE,
    ];

    $form['auto_processing']['auto_process_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic report processing'),
      '#description' => $this->t('When enabled, reports will be automatically generated when a user completes all required modules.'),
      '#default_value' => $config->get('auto_process_enabled') ?? FALSE,
    ];

    $form['auto_processing']['auto_process_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Processing delay (minutes)'),
      '#description' => $this->t('Number of minutes to wait before automatically processing reports. Set to 0 for immediate processing.'),
      '#default_value' => $config->get('auto_process_delay') ?? 0,
      '#min' => 0,
      '#max' => 1440,
      '#states' => [
        'visible' => [
          ':input[name="auto_process_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['auto_processing']['report_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Report Types to Auto-Generate'),
      '#description' => $this->t('Select which report types should be automatically generated.'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="auto_process_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $report_types = [
      'role_impact' => $this->t('Role Impact Analysis'),
      'career_transitions' => $this->t('Career Transitions'),
      'task_recommendations' => $this->t('Task Recommender'),
      'industry_insights' => $this->t('Industry Insights'),
      'skills' => $this->t('Skills Analysis'),
      'learning_resources' => $this->t('Learning Resources'),
      'breakthrough_strategies' => $this->t('Breakthrough Strategies'),
      'concerns_navigator' => $this->t('Concerns Navigator'),
    ];

    $default_reports = $config->get('auto_process_reports') ?? [];

    foreach ($report_types as $key => $label) {
      $form['auto_processing']['report_types']['auto_process_reports[' . $key . ']'] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $default_reports[$key] ?? TRUE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('client_dashboard.settings');

    $config->set('auto_process_enabled', $form_state->getValue('auto_process_enabled'));
    $config->set('auto_process_delay', $form_state->getValue('auto_process_delay'));

    // Extract report types from form values.
    $report_types = [];
    $all_values = $form_state->getValues();
    foreach ($all_values as $key => $value) {
      if (strpos($key, 'auto_process_reports[') === 0) {
        $report_key = str_replace(['auto_process_reports[', ']'], '', $key);
        $report_types[$report_key] = (bool) $value;
      }
    }
    $config->set('auto_process_reports', $report_types);

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
