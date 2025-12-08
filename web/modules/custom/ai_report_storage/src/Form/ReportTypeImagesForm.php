<?php

namespace Drupal\ai_report_storage\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Report Type Images.
 */
class ReportTypeImagesForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_report_storage.type_images'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_report_storage_type_images_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_report_storage.type_images');

    // Define all available report types.
    $report_types = [
      'industry_insights' => $this->t('Industry Insights'),
      'role_impact' => $this->t('Evolution of Your Role'),
      'skills' => $this->t('Improving Your Skills'),
      'task_recommendations' => $this->t('Automating Tasks'),
      'career_transitions' => $this->t('Career Opportunities'),
      'learning_resources' => $this->t('Learning Resources'),
      'breakthrough_strategies' => $this->t('Strategies for Change'),
      'concerns_navigator' => $this->t('Navigating Your Concerns'),
    ];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure default images for each report type. These images will be displayed on the dashboard.') . '</p>',
    ];

    foreach ($report_types as $type => $label) {
      $form['types'][$type] = [
        '#type' => 'fieldset',
        '#title' => $label,
      ];

      $form['types'][$type]['media_id'] = [
        '#type' => 'media_library',
        '#allowed_bundles' => ['image'],
        '#title' => $this->t('Image'),
        '#default_value' => $config->get("types.{$type}.media_id"),
        '#description' => $this->t('Select an image to display for this report type.'),
        '#cardinality' => 1,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ai_report_storage.type_images');

    // Get all report type values.
    $types = $form_state->getValue('types');

    foreach ($types as $type => $values) {
      // Extract media_id from the array (media_library returns an array).
      $media_id = is_array($values['media_id']) && !empty($values['media_id'])
        ? reset($values['media_id'])
        : NULL;

      $config->set("types.{$type}.media_id", $media_id);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
