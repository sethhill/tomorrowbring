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



    // Benefits section (from original template).
    $form['benefits'] = [
      '#type' => 'markup',
      '#markup' => '<div class="checkout-benefits">
        <div class="checkout-price">$29.00 <span class="">one-time payment</span></div>
        <h2>' . $this->t("What you’ll get") . '</h2>
        <h3>' . $this->t('8 personalized career reports') . '</h3>
        <ul>
          <li>' . $this->t('Industry Insights') . '</li>
          <li>' . $this->t('Evolution of Your Role') . '</li>
          <li>' . $this->t('Improving Your Skills') . '</li>
          <li>' . $this->t('Automating Tasks') . '</li>
          <li>' . $this->t('Career Opportunities') . '</li>
          <li>' . $this->t('Learning Resources') . '</li>
          <li>' . $this->t('Strategies for Change') . '</li>
          <li>' . $this->t('Navigating Your Concerns') . '</li>
        </ul>
      </div>',
    ];


    // Form fields container.
    $form['form_fields'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['checkout-form-fields'],
      ],
    ];

    // Email field.
    $form['form_fields']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
      '#attributes' => [
        // 'placeholder' => $this->t('your.email@example.com'),
      ],
    ];

    // First name field.
    $form['form_fields']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        // 'placeholder' => $this->t('John'),
      ],
    ];

    // Last name field.
    $form['form_fields']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        // 'placeholder' => $this->t('Doe'),
      ],
    ];

    // Submit button.
    $form['form_fields']['actions'] = [
      '#type' => 'actions',
    ];
    $form['form_fields']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#attributes' => [
        'class' => ['paddle-checkout-btn'],
      ],
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

    // Check if user already exists with this email only if email is not empty.
    if (!empty($email)) {
      $user_storage = \Drupal::entityTypeManager()->getStorage('user');
      $existing_users = $user_storage->loadByProperties(['mail' => $email]);
      if (!empty($existing_users)) {
        $form_state->setErrorByName('email', $this->t('An account with this email address already exists. Please <a href="@login">log in</a> instead.', [
          '@login' => Url::fromRoute('user.login')->toString(),
        ]));
      }
    }

    // Validate names contain only letters, spaces, hyphens, apostrophes.
    if (!empty($first_name) && !preg_match("/^[\p{L}\s\-']+$/u", $first_name)) {
      $form_state->setErrorByName('first_name', $this->t('First name can only contain letters, spaces, hyphens, and apostrophes.'));
    }
    if (!empty($last_name) && !preg_match("/^[\p{L}\s\-']+$/u", $last_name)) {
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
