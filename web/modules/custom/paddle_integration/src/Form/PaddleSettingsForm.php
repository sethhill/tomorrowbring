<?php

namespace Drupal\paddle_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Paddle Integration settings.
 */
class PaddleSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['paddle_integration.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'paddle_integration_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('paddle_integration.settings');

    $form['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Environment'),
      '#options' => [
        'sandbox' => $this->t('Sandbox (Testing)'),
        'production' => $this->t('Production (Live)'),
      ],
      '#default_value' => $config->get('environment') ?: 'sandbox',
      '#required' => TRUE,
    ];

    $form['sandbox'] = [
      '#type' => 'details',
      '#title' => $this->t('Sandbox Settings'),
      '#open' => TRUE,
    ];

    $form['sandbox']['api_key_sandbox'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox API Key'),
      '#default_value' => $config->get('api_key_sandbox'),
      '#description' => $this->t('Enter your Paddle sandbox API key. Can be overridden via environment variable PADDLE_API_KEY_SANDBOX.'),
    ];

    $form['sandbox']['webhook_secret_sandbox'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox Webhook Secret'),
      '#default_value' => $config->get('webhook_secret_sandbox'),
      '#description' => $this->t('Enter your Paddle sandbox webhook secret. Can be overridden via environment variable PADDLE_WEBHOOK_SECRET_SANDBOX.'),
    ];

    $form['sandbox']['client_token_sandbox'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox Client-Side Token'),
      '#default_value' => $config->get('client_token_sandbox'),
      '#description' => $this->t('Enter your Paddle sandbox client-side token (for Paddle.js).'),
    ];

    $form['sandbox']['product_id_sandbox'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox Product ID'),
      '#default_value' => $config->get('product_id_sandbox'),
      '#description' => $this->t('Enter your Paddle sandbox product ID.'),
    ];

    $form['production'] = [
      '#type' => 'details',
      '#title' => $this->t('Production Settings'),
      '#open' => FALSE,
    ];

    $form['production']['api_key_production'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Production API Key'),
      '#default_value' => $config->get('api_key_production'),
      '#description' => $this->t('Enter your Paddle production API key. Can be overridden via environment variable PADDLE_API_KEY_PRODUCTION.'),
    ];

    $form['production']['webhook_secret_production'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Production Webhook Secret'),
      '#default_value' => $config->get('webhook_secret_production'),
      '#description' => $this->t('Enter your Paddle production webhook secret. Can be overridden via environment variable PADDLE_WEBHOOK_SECRET_PRODUCTION.'),
    ];

    $form['production']['client_token_production'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Production Client-Side Token'),
      '#default_value' => $config->get('client_token_production'),
      '#description' => $this->t('Enter your Paddle production client-side token (for Paddle.js).'),
    ];

    $form['production']['product_id_production'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Production Product ID'),
      '#default_value' => $config->get('product_id_production'),
      '#description' => $this->t('Enter your Paddle production product ID.'),
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['registration_token_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Registration Token Lifetime'),
      '#default_value' => $config->get('registration_token_lifetime') ?: 604800,
      '#description' => $this->t('How long registration tokens are valid (in seconds). Default: 604800 (7 days).'),
      '#min' => 3600,
      '#max' => 2592000,
      '#required' => TRUE,
    ];

    $form['general']['individual_client_nid'] = [
      '#type' => 'number',
      '#title' => $this->t('Individual Client Node ID'),
      '#default_value' => $config->get('individual_client_nid'),
      '#description' => $this->t('The Node ID of the "Individual Subscribers" client. This is automatically set during module installation.'),
      '#min' => 1,
    ];

    $form['webhook_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhook Configuration'),
      '#open' => TRUE,
    ];

    $webhook_url = \Drupal::request()->getSchemeAndHttpHost() . '/paddle/webhook';
    $form['webhook_info']['webhook_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook URL'),
      '#markup' => '<code>' . $webhook_url . '</code>',
      '#description' => $this->t('Configure this URL in your Paddle dashboard as the webhook destination.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('paddle_integration.settings')
      ->set('environment', $form_state->getValue('environment'))
      ->set('api_key_sandbox', $form_state->getValue('api_key_sandbox'))
      ->set('webhook_secret_sandbox', $form_state->getValue('webhook_secret_sandbox'))
      ->set('client_token_sandbox', $form_state->getValue('client_token_sandbox'))
      ->set('product_id_sandbox', $form_state->getValue('product_id_sandbox'))
      ->set('api_key_production', $form_state->getValue('api_key_production'))
      ->set('webhook_secret_production', $form_state->getValue('webhook_secret_production'))
      ->set('client_token_production', $form_state->getValue('client_token_production'))
      ->set('product_id_production', $form_state->getValue('product_id_production'))
      ->set('registration_token_lifetime', $form_state->getValue('registration_token_lifetime'))
      ->set('individual_client_nid', $form_state->getValue('individual_client_nid'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
