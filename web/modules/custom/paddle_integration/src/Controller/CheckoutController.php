<?php

namespace Drupal\paddle_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\paddle_integration\PaddleApiClient;
use Drupal\paddle_integration\PaddleCustomerManager;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Controller for Paddle checkout pages.
 */
class CheckoutController extends ControllerBase {

  /**
   * The Paddle API client.
   *
   * @var \Drupal\paddle_integration\PaddleApiClient
   */
  protected $apiClient;

  /**
   * The Paddle customer manager.
   *
   * @var \Drupal\paddle_integration\PaddleCustomerManager
   */
  protected $customerManager;

  /**
   * The private temp store.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a CheckoutController.
   *
   * @param \Drupal\paddle_integration\PaddleApiClient $api_client
   *   The Paddle API client.
   * @param \Drupal\paddle_integration\PaddleCustomerManager $customer_manager
   *   The Paddle customer manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp store factory.
   */
  public function __construct(PaddleApiClient $api_client, PaddleCustomerManager $customer_manager, PrivateTempStoreFactory $temp_store_factory) {
    $this->apiClient = $api_client;
    $this->customerManager = $customer_manager;
    $this->tempStore = $temp_store_factory->get('paddle_integration');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('paddle_integration.api_client'),
      $container->get('paddle_integration.customer_manager'),
      $container->get('tempstore.private')
    );
  }

  /**
   * Checkout page (Screen 1 - Overview).
   *
   * Note: This is now handled by PaddleCheckoutForm.
   * This method redirects to the form.
   *
   * @return array
   *   Render array for checkout overview page.
   */
  public function checkout() {
    // This route now uses _form in routing.yml, so this method won't be called.
    // Keeping it for backward compatibility.
    $confirm_url = Url::fromRoute('paddle_integration.checkout_confirm')->toString();

    return [
      '#theme' => 'paddle_checkout_overview',
      '#confirm_url' => $confirm_url,
    ];
  }

  /**
   * Checkout confirmation page (Screen 2 - Acknowledgments and Payment).
   *
   * @return array
   *   Render array for checkout confirmation page.
   */
  public function confirm() {
    $client_token = $this->apiClient->getClientToken();
    $product_id = $this->apiClient->getProductId();
    $config = $this->config('paddle_integration.settings');
    $environment = $config->get('environment') ?: 'sandbox';

    if (empty($client_token) || empty($product_id)) {
      $this->messenger()->addError($this->t('Checkout is not configured. Please contact the site administrator.'));
      return [
        '#markup' => $this->t('Checkout is currently unavailable.'),
      ];
    }

    // Retrieve checkout session ID from temp store.
    $checkout_session_id = $this->tempStore->get('checkout_session_id');
    if (empty($checkout_session_id)) {
      $this->messenger()->addError($this->t('Your checkout session has expired. Please start again.'));
      return $this->redirect('paddle_integration.checkout');
    }

    // Load pre-checkout record.
    $purchase = $this->customerManager->getPreCheckoutBySessionId($checkout_session_id);
    if (!$purchase) {
      $this->messenger()->addError($this->t('Your checkout session is invalid. Please start again.'));
      return $this->redirect('paddle_integration.checkout');
    }

    $success_url = Url::fromRoute('paddle_integration.checkout_success', [], ['absolute' => TRUE])->toString();
    $cancel_url = Url::fromRoute('paddle_integration.checkout_cancel', [], ['absolute' => TRUE])->toString();

    // Terms page route - using path alias.
    $terms_url = '/terms-and-conditions';
    // Privacy page - node 36.
    $privacy_url = Url::fromRoute('entity.node.canonical', ['node' => 36])->toString();

    return [
      '#theme' => 'paddle_checkout_confirm',
      '#client_token' => $client_token,
      '#product_id' => $product_id,
      '#environment' => $environment,
      '#success_url' => $success_url,
      '#cancel_url' => $cancel_url,
      '#terms_url' => $terms_url,
      '#privacy_url' => $privacy_url,
      '#customer_email' => $purchase->customer_email,
      '#customer_first_name' => $purchase->customer_first_name,
      '#customer_last_name' => $purchase->customer_last_name,
      '#checkout_session_id' => $checkout_session_id,
      '#attached' => [
        'library' => [
          'paddle_integration/paddle_checkout',
        ],
      ],
    ];
  }

  /**
   * Checkout success page.
   *
   * @return array
   *   Render array for success page.
   */
  public function success() {
    return [
      '#theme' => 'paddle_success',
      '#message' => $this->t('Your payment has been processed successfully!'),
    ];
  }

  /**
   * Checkout cancel page.
   *
   * @return array
   *   Render array for cancel page.
   */
  public function cancel() {
    $checkout_url = Url::fromRoute('paddle_integration.checkout')->toString();

    return [
      '#theme' => 'paddle_cancel',
      '#message' => $this->t('Your payment was cancelled.'),
      '#checkout_url' => $checkout_url,
    ];
  }

}
