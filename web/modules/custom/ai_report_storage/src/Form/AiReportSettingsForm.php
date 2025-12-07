<?php

namespace Drupal\ai_report_storage\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for AI Report settings.
 */
class AiReportSettingsForm extends ConfigFormBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProvider;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static(
      $container->get('config.factory'),
      $container->get('config.typed')
    );
    $instance->aiProvider = $container->get('ai.provider');
    return $instance;
  }

  /**
   * Constructs an AiReportSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   */
  public function __construct($config_factory, $typed_config_manager) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_report_storage.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_report_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_report_storage.settings');

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure AI provider and model settings for report generation. Reports are categorized as either simple or complex based on their computational requirements.') . '</p>',
    ];

    // Simple Reports Section.
    $form['simple_reports'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Simple Reports Configuration'),
      '#description' => $this->t('Settings for straightforward reports (Role Impact, Industry Insights, Skills, Concerns Navigator).'),
    ];

    $form['simple_reports']['simple_operation_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Operation Type'),
      '#options' => $this->getOperationTypeOptions(),
      '#default_value' => $config->get('simple_reports.operation_type') ?? 'chat',
      '#description' => $this->t('The AI operation type to use for simple reports.'),
    ];

    $form['simple_reports']['simple_provider_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider and Model'),
      '#options' => $this->getProviderModelOptions($config->get('simple_reports.operation_type') ?? 'chat'),
      '#default_value' => $config->get('simple_reports.provider_model') ?? 'anthropic__claude-sonnet-4-5-20250929',
      '#description' => $this->t('The AI provider and model to use for simple reports.'),
    ];

    // Complex Reports Section.
    $form['complex_reports'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Complex Reports Configuration'),
      '#description' => $this->t('Settings for complex reports requiring advanced analysis (Career Transitions, Task Recommendations, Hybrid Analysis, Breakthrough Strategies).'),
    ];

    $form['complex_reports']['complex_operation_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Operation Type'),
      '#options' => $this->getOperationTypeOptions(),
      '#default_value' => $config->get('complex_reports.operation_type') ?? 'chat_with_complex_json',
      '#description' => $this->t('The AI operation type to use for complex reports.'),
    ];

    $form['complex_reports']['complex_provider_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider and Model'),
      '#options' => $this->getProviderModelOptions($config->get('complex_reports.operation_type') ?? 'chat_with_complex_json'),
      '#default_value' => $config->get('complex_reports.provider_model') ?? 'anthropic__claude-opus-4-5-20251101',
      '#description' => $this->t('The AI provider and model to use for complex reports.'),
    ];

    // Advanced Settings.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout'),
      '#default_value' => $config->get('timeout') ?? 600,
      '#min' => 60,
      '#max' => 1200,
      '#step' => 30,
      '#field_suffix' => $this->t('seconds'),
      '#description' => $this->t('Maximum time to wait for AI API responses. Increase for complex reports.'),
    ];

    $form['advanced']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Output Tokens'),
      '#default_value' => $config->get('max_tokens') ?? 8096,
      '#min' => 1000,
      '#max' => 16000,
      '#step' => 1000,
      '#description' => $this->t('Maximum number of tokens the AI can generate in response.'),
    ];

    $form['advanced']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Prompt Template'),
      '#default_value' => $config->get('system_prompt') ?? 'You are an expert career analyst. Provide realistic, actionable career guidance based on AI displacement research. Always respond with valid JSON matching the exact structure requested. Be concise and specific - quality over quantity.',
      '#rows' => 4,
      '#description' => $this->t('The system prompt sent to the AI before each request.'),
    ];

    // Report Complexity Mapping.
    $form['complexity_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Report Type Complexity Mapping'),
      '#description' => $this->t('Configure which reports are treated as simple vs. complex.'),
      '#open' => FALSE,
    ];

    $rules = $config->get('report_complexity_rules') ?? [];
    $form['complexity_mapping']['rules_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Report Type'),
        $this->t('Complexity'),
      ],
    ];

    foreach ($rules as $index => $rule) {
      $form['complexity_mapping']['rules_table'][$index]['report_type'] = [
        '#type' => 'markup',
        '#markup' => $this->formatReportTypeName($rule['report_type']),
      ];

      $form['complexity_mapping']['rules_table'][$index]['complexity'] = [
        '#type' => 'select',
        '#options' => [
          'simple' => $this->t('Simple'),
          'complex' => $this->t('Complex'),
        ],
        '#default_value' => $rule['complexity'],
      ];

      $form['complexity_mapping']['rules_table'][$index]['report_type_value'] = [
        '#type' => 'hidden',
        '#value' => $rule['report_type'],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ai_report_storage.settings');

    // Save simple reports config.
    $config->set('simple_reports.operation_type', $form_state->getValue('simple_operation_type'));
    $config->set('simple_reports.provider_model', $form_state->getValue('simple_provider_model'));

    // Save complex reports config.
    $config->set('complex_reports.operation_type', $form_state->getValue('complex_operation_type'));
    $config->set('complex_reports.provider_model', $form_state->getValue('complex_provider_model'));

    // Save advanced settings.
    $config->set('timeout', $form_state->getValue('timeout'));
    $config->set('max_tokens', $form_state->getValue('max_tokens'));
    $config->set('system_prompt', $form_state->getValue('system_prompt'));

    // Save complexity mapping.
    $rules_table = $form_state->getValue('rules_table');
    $updated_rules = [];
    if (!empty($rules_table)) {
      foreach ($rules_table as $row) {
        $updated_rules[] = [
          'report_type' => $row['report_type_value'],
          'complexity' => $row['complexity'],
        ];
      }
      $config->set('report_complexity_rules', $updated_rules);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get operation type options for dropdowns.
   *
   * @return array
   *   Array of operation type options.
   */
  private function getOperationTypeOptions() {
    try {
      $operation_types = $this->aiProvider->getOperationTypes();
      $options = [];
      foreach ($operation_types as $type) {
        // Only include chat-related operation types.
        if (strpos($type['id'], 'chat') === 0) {
          $options[$type['id']] = $type['label'] ?? $type['id'];
        }
      }
      return $options;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_report_storage')->error('Failed to load operation types: @error', ['@error' => $e->getMessage()]);
      return [
        'chat' => $this->t('Chat'),
        'chat_with_complex_json' => $this->t('Chat with Complex JSON'),
      ];
    }
  }

  /**
   * Get provider and model options for dropdowns.
   *
   * @param string $operation_type
   *   The operation type.
   *
   * @return array
   *   Array of provider/model options.
   */
  private function getProviderModelOptions($operation_type) {
    try {
      return $this->aiProvider->getSimpleProviderModelOptions($operation_type, TRUE, TRUE);
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_report_storage')->error('Failed to load provider models: @error', ['@error' => $e->getMessage()]);
      return [
        'anthropic__claude-sonnet-4-5-20250929' => $this->t('Anthropic - Claude Sonnet 4.5'),
        'anthropic__claude-opus-4-5-20251101' => $this->t('Anthropic - Claude Opus 4.5'),
      ];
    }
  }

  /**
   * Format report type machine name to human-readable name.
   *
   * @param string $report_type
   *   The machine name of the report type.
   *
   * @return string
   *   Human-readable name.
   */
  private function formatReportTypeName($report_type) {
    $names = [
      'role_impact' => $this->t('Role Impact Analysis'),
      'career_transitions' => $this->t('Career Transitions'),
      'industry_insights' => $this->t('Industry Insights'),
      'skills' => $this->t('Skills Analysis'),
      'task_recommendations' => $this->t('Task Recommendations'),
      'hybrid_analysis' => $this->t('Hybrid Analysis'),
      'concerns_navigator' => $this->t('Concerns Navigator'),
      'breakthrough_strategies' => $this->t('Breakthrough Strategies'),
    ];

    return $names[$report_type] ?? ucwords(str_replace('_', ' ', $report_type));
  }

}
