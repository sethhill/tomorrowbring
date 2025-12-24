<?php

namespace Drupal\paddle_integration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paddle_integration\PaddleCustomerManager;
use Drupal\paddle_integration\PaddleRegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form for completing user registration after payment.
 */
class PaddleRegistrationForm extends FormBase {

  /**
   * The customer manager.
   *
   * @var \Drupal\paddle_integration\PaddleCustomerManager
   */
  protected $customerManager;

  /**
   * The registration service.
   *
   * @var \Drupal\paddle_integration\PaddleRegistrationService
   */
  protected $registrationService;

  /**
   * The purchase record.
   *
   * @var object
   */
  protected $purchase;

  /**
   * Constructs a PaddleRegistrationForm.
   *
   * @param \Drupal\paddle_integration\PaddleCustomerManager $customer_manager
   *   The customer manager.
   * @param \Drupal\paddle_integration\PaddleRegistrationService $registration_service
   *   The registration service.
   */
  public function __construct(
    PaddleCustomerManager $customer_manager,
    PaddleRegistrationService $registration_service
  ) {
    $this->customerManager = $customer_manager;
    $this->registrationService = $registration_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('paddle_integration.customer_manager'),
      $container->get('paddle_integration.registration')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'paddle_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $token = NULL) {
    // Load purchase by token.
    $this->purchase = $this->customerManager->getPendingPurchaseByToken($token);

    if (!$this->purchase) {
      $this->messenger()->addError($this->t('This registration link is invalid or has expired. Please contact support if you believe this is an error.'));
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->send();
      return [];
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Welcome! Complete the form below to create your account and access the AI Career Impact Analysis platform. You will use your email address to log in.') . '</p>',
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $this->purchase->customer_email,
      '#disabled' => TRUE,
      '#description' => $this->t('This is the email address associated with your payment. Use this to log in.'),
    ];

    $form['password'] = [
      '#type' => 'password_confirm',
      '#title' => $this->t('Password'),
      '#required' => TRUE,
      '#size' => 25,
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What should we call you?'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    // Load industry taxonomy terms.
    $industry_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree('industry');

    $industry_options = [];
    foreach ($industry_terms as $term) {
      $industry_options[$term->tid] = $term->name;
    }

    $form['industry'] = [
      '#type' => 'select',
      '#title' => $this->t('What industry do you work in (or want to work in)?'),
      '#options' => $industry_options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
    ];

    // Load company size taxonomy terms.
    $company_size_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree('company_size');

    $company_size_options = [];
    foreach ($company_size_terms as $term) {
      $company_size_options[$term->tid] = $term->name;
    }

    $form['company_size'] = [
      '#type' => 'select',
      '#title' => $this->t('What size company do you work for (or want to work for)?'),
      '#options' => $company_size_options,
      '#required' => FALSE,
      '#empty_option' => $this->t('- Select -'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create My Account'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // No additional validation needed - username will be auto-generated from email.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Auto-generate username from email address.
    // Drupal requires a unique username, but users will login with email.
    $username = $this->purchase->customer_email;

    // Ensure username is unique (in case email is already used as username).
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);

    if (!empty($existing_users)) {
      // If email is taken as username, append timestamp for uniqueness.
      $username = $this->purchase->customer_email . '_' . time();
    }

    // Prepare form data.
    $form_data = [
      'username' => $username,
      'password' => $form_state->getValue('password'),
      'name' => $form_state->getValue('name'),
      'industry' => $form_state->getValue('industry'),
      'company_size' => $form_state->getValue('company_size'),
    ];

    // Create user account.
    $user = $this->registrationService->createUserAccount($this->purchase, $form_data);

    if (!$user) {
      $this->messenger()->addError($this->t('Failed to create your account. Please contact support.'));
      return;
    }

    // Update purchase record.
    $this->customerManager->completePurchase($this->purchase->id, $user->id());

    // Log user in.
    user_login_finalize($user);

    $this->messenger()->addStatus($this->t('Welcome, @name! Your account has been created successfully.', [
      '@name' => $user->getDisplayName(),
    ]));

    // Redirect to dashboard (login_destination module will handle this).
    $form_state->setRedirect('client_dashboard.dashboard');
  }

}
