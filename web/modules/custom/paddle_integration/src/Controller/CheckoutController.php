<?php

namespace Drupal\paddle_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\paddle_integration\PaddleApiClient;
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
   * Constructs a CheckoutController.
   *
   * @param \Drupal\paddle_integration\PaddleApiClient $api_client
   *   The Paddle API client.
   */
  public function __construct(PaddleApiClient $api_client) {
    $this->apiClient = $api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('paddle_integration.api_client')
    );
  }

  /**
   * Checkout page.
   *
   * @return array
   *   Render array for checkout page.
   */
  public function checkout() {
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

    $success_url = Url::fromRoute('paddle_integration.checkout_success', [], ['absolute' => TRUE])->toString();
    $cancel_url = Url::fromRoute('paddle_integration.checkout_cancel', [], ['absolute' => TRUE])->toString();

    return [
      '#theme' => 'paddle_checkout',
      '#client_token' => $client_token,
      '#product_id' => $product_id,
      '#environment' => $environment,
      '#success_url' => $success_url,
      '#cancel_url' => $cancel_url,
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
