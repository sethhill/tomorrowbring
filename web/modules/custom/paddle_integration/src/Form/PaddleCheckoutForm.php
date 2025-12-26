<?php

namespace Drupal\paddle_integration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\paddle_integration\PaddleCustomerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Form for collecting user information before checkout.
 */
class PaddleCheckoutForm extends FormBase {

  /**
   * The private temp store.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * The Paddle customer manager.
   *
   * @var \Drupal\paddle_integration\PaddleCustomerManager
   */
  protected $customerManager;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * Constructs a PaddleCheckoutForm.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp store factory.
   * @param \Drupal\paddle_integration\PaddleCustomerManager $customer_manager
   *   The Paddle customer manager.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, PaddleCustomerManager $customer_manager, UuidInterface $uuid) {
    $this->tempStore = $temp_store_factory->get('paddle_integration');
    $this->customerManager = $customer_manager;
    $this->uuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private'),
      $container->get('paddle_integration.customer_manager'),
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'paddle_checkout_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'paddle_integration/paddle_checkout';

    // Header section.
    $form['header'] = [
      '#type' => 'markup',
      '#markup' => '<div class="checkout-header">
        <h1>' . $this->t('Get Started with AI Career Impact Analysis') . '</h1>
        <p>' . $this->t('Please provide your information to begin the checkout process.') . '</p>
      </div>',
    ];

    // Email field.
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('your.email@example.com'),
      ],
    ];

    // First name field.
    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'placeholder' => $this->t('John'),
      ],
    ];

    // Last name field.
    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'placeholder' => $this->t('Doe'),
      ],
    ];

    // Benefits section (from original template).
    $form['benefits'] = [
      '#type' => 'markup',
      '#markup' => '<div class="checkout-benefits">
        <h2>' . $this->t("What You'll Get:") . '</h2>
        <ul>
          <li>' . $this->t('30 comprehensive assessment modules') . '</li>
          <li>' . $this->t('8 personalized AI-generated career reports') . '</li>
          <li>' . $this->t('Role impact analysis') . '</li>
          <li>' . $this->t('Career transition opportunities') . '</li>
          <li>' . $this->t('Task automation recommendations') . '</li>
          <li>' . $this->t('Industry insights and trends') . '</li>
          <li>' . $this->t('Skills gap analysis') . '</li>
          <li>' . $this->t('Personalized learning resources') . '</li>
        </ul>
      </div>',
    ];

    // Submit button.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue to Purchase'),
      '#attributes' => [
        'class' => ['paddle-checkout-btn'],
      ],
    ];

    // Security notice.
    $form['security'] = [
      '#type' => 'markup',
      '#markup' => '<div class="checkout-security">
        <p>' . $this->t('Secure payment powered by Paddle.com') . '</p>
      </div>',
    ];

    // Add CSS styling.
    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .paddle-checkout-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
          }
          .checkout-header {
            text-align: center;
            margin-bottom: 2rem;
          }
          .checkout-header h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
          }
          .checkout-benefits {
            background: #f5f5f5;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
          }
          .checkout-benefits h2 {
            margin-top: 0;
          }
          .checkout-benefits ul {
            list-style: none;
            padding-left: 0;
          }
          .checkout-benefits li {
            padding: 0.5rem 0;
            padding-left: 1.5rem;
            position: relative;
          }
          .checkout-benefits li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
          }
          .form-item-email,
          .form-item-first-name,
          .form-item-last-name {
            margin-bottom: 1.5rem;
          }
          .form-item-email input,
          .form-item-first-name input,
          .form-item-last-name input {
            width: 100%;
            max-width: 500px;
            padding: 0.75rem;
            font-size: 1rem;
            border: 1px solid #ccc;
            border-radius: 4px;
          }
          .paddle-checkout-btn {
            display: inline-block;
            background: #007bff;
            color: white;
            border: none;
            padding: 1rem 3rem;
            font-size: 1.25rem;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
          }
          .paddle-checkout-btn:hover {
            background: #0056b3;
          }
          .form-actions {
            text-align: center;
            margin: 2rem 0;
          }
          .checkout-security {
            text-align: center;
            color: #666;
            font-size: 0.9rem;
            margin-top: 1rem;
          }
        ',
      ],
      'paddle-checkout-form-styles',
    ];

    $form['#prefix'] = '<div class="paddle-checkout-container">';
    $form['#suffix'] = '</div>';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $email = $form_state->getValue('email');
    $first_name = $form_state->getValue('first_name');
    $last_name = $form_state->getValue('last_name');

    // Validate email format.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }

    // Check if user already exists with this email.
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $existing_users = $user_storage->loadByProperties(['mail' => $email]);
    if (!empty($existing_users)) {
      $form_state->setErrorByName('email', $this->t('An account with this email address already exists. Please <a href="@login">log in</a> instead.', [
        '@login' => Url::fromRoute('user.login')->toString(),
      ]));
    }

    // Validate names contain only letters, spaces, hyphens, apostrophes.
    if (!preg_match("/^[\p{L}\s\-']+$/u", $first_name)) {
      $form_state->setErrorByName('first_name', $this->t('First name can only contain letters, spaces, hyphens, and apostrophes.'));
    }
    if (!preg_match("/^[\p{L}\s\-']+$/u", $last_name)) {
      $form_state->setErrorByName('last_name', $this->t('Last name can only contain letters, spaces, hyphens, and apostrophes.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email = $form_state->getValue('email');
    $first_name = trim($form_state->getValue('first_name'));
    $last_name = trim($form_state->getValue('last_name'));

    // Generate unique checkout session ID (UUID v4).
    $checkout_session_id = $this->uuid->generate();

    // Create pre-checkout record in database.
    $record_id = $this->customerManager->createPreCheckoutRecord([
      'checkout_session_id' => $checkout_session_id,
      'customer_email' => $email,
      'customer_first_name' => $first_name,
      'customer_last_name' => $last_name,
    ]);

    if ($record_id) {
      // Store checkout session ID in temp store for confirm page.
      $this->tempStore->set('checkout_session_id', $checkout_session_id);

      // Log successful pre-checkout creation.
      $this->logger('paddle_integration')->info('Pre-checkout record created for @email (session: @session)', [
        '@email' => $email,
        '@session' => $checkout_session_id,
      ]);

      // Redirect to confirmation page.
      $form_state->setRedirect('paddle_integration.checkout_confirm');
    }
    else {
      // Error creating record.
      $this->messenger()->addError($this->t('An error occurred. Please try again.'));
      $this->logger('paddle_integration')->error('Failed to create pre-checkout record for @email', [
        '@email' => $email,
      ]);
    }
  }

}
