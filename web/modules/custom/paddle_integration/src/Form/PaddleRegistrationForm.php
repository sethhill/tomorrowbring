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
      '#markup' => '<p>' . $this->t('Welcome! Complete the form below to create your account and access the AI Career Impact Analysis platform.') . '</p>',
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $this->purchase->customer_email,
      '#disabled' => TRUE,
      '#description' => $this->t('This is the email address associated with your payment.'),
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#maxlength' => 60,
      '#description' => $this->t('Choose a username for logging in.'),
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

    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the Terms of Service and Privacy Policy'),
      '#required' => TRUE,
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
    $username = $form_state->getValue('username');

    // Check username uniqueness.
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);

    if (!empty($existing_users)) {
      $form_state->setErrorByName('username', $this->t('The username %name is already taken.', ['%name' => $username]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Prepare form data.
    $form_data = [
      'username' => $form_state->getValue('username'),
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
