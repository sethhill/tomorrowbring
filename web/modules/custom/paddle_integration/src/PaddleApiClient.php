<?php

namespace Drupal\paddle_integration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Paddle API client service.
 */
class PaddleApiClient {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a PaddleApiClient.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Get the current environment (sandbox or production).
   *
   * @return string
   *   The environment: 'sandbox' or 'production'.
   */
  protected function getEnvironment() {
    $config = $this->configFactory->get('paddle_integration.settings');
    return $config->get('environment') ?: 'sandbox';
  }

  /**
   * Get the API key for the current environment.
   *
   * @return string|null
   *   The API key.
   */
  protected function getApiKey() {
    $config = $this->configFactory->get('paddle_integration.settings');
    $environment = $this->getEnvironment();
    $key = $environment === 'production'
      ? $config->get('api_key_production')
      : $config->get('api_key_sandbox');
    return $key;
  }

  /**
   * Get the webhook secret for the current environment.
   *
   * @return string|null
   *   The webhook secret.
   */
  protected function getWebhookSecret() {
    $config = $this->configFactory->get('paddle_integration.settings');
    $environment = $this->getEnvironment();
    $secret = $environment === 'production'
      ? $config->get('webhook_secret_production')
      : $config->get('webhook_secret_sandbox');
    return $secret;
  }

  /**
   * Get the client-side token for the current environment.
   *
   * @return string|null
   *   The client-side token.
   */
  public function getClientToken() {
    $config = $this->configFactory->get('paddle_integration.settings');
    $environment = $this->getEnvironment();
    $token = $environment === 'production'
      ? $config->get('client_token_production')
      : $config->get('client_token_sandbox');
    return $token;
  }

  /**
   * Get the product ID for the current environment.
   *
   * @return string|null
   *   The product ID.
   */
  public function getProductId() {
    $config = $this->configFactory->get('paddle_integration.settings');
    $environment = $this->getEnvironment();
    $product_id = $environment === 'production'
      ? $config->get('product_id_production')
      : $config->get('product_id_sandbox');
    return $product_id;
  }

  /**
   * Validate Paddle webhook signature.
   *
   * @param string $payload
   *   The raw POST body.
   * @param string $signature
   *   The Paddle-Signature header value.
   *
   * @return bool
   *   TRUE if signature is valid, FALSE otherwise.
   */
  public function validateWebhookSignature($payload, $signature) {
    $secret = $this->getWebhookSecret();

    if (empty($secret)) {
      $this->logger->error('Webhook secret not configured');
      return FALSE;
    }

    if (empty($signature)) {
      $this->logger->warning('Webhook signature header missing');
      return FALSE;
    }

    // Parse signature header (format: "ts=1234567890;h1=abc123...")
    $sig_parts = [];
    parse_str(str_replace(';', '&', $signature), $sig_parts);

    $timestamp = $sig_parts['ts'] ?? '';
    $received_sig = $sig_parts['h1'] ?? '';

    if (empty($timestamp) || empty($received_sig)) {
      $this->logger->warning('Invalid signature format');
      return FALSE;
    }

    // Construct signed payload.
    $signed_payload = $timestamp . ':' . $payload;

    // Calculate expected signature.
    $expected_sig = hash_hmac('sha256', $signed_payload, $secret);

    // Use constant-time comparison to prevent timing attacks.
    $is_valid = hash_equals($expected_sig, $received_sig);

    if (!$is_valid) {
      $this->logger->error('Webhook signature validation failed');
    }

    return $is_valid;
  }

  /**
   * Get the Paddle API base URL.
   *
   * @return string
   *   The API base URL.
   */
  protected function getApiBaseUrl() {
    $environment = $this->getEnvironment();
    return $environment === 'production'
      ? 'https://api.paddle.com'
      : 'https://sandbox-api.paddle.com';
  }

}
