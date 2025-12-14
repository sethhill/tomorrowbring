<?php

namespace Drupal\paddle_integration;

use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Handles Paddle webhook events.
 */
class PaddleWebhookHandler {

  /**
   * The Paddle API client.
   *
   * @var \Drupal\paddle_integration\PaddleApiClient
   */
  protected $apiClient;

  /**
   * The customer manager.
   *
   * @var \Drupal\paddle_integration\PaddleCustomerManager
   */
  protected $customerManager;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a PaddleWebhookHandler.
   *
   * @param \Drupal\paddle_integration\PaddleApiClient $api_client
   *   The Paddle API client.
   * @param \Drupal\paddle_integration\PaddleCustomerManager $customer_manager
   *   The customer manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    PaddleApiClient $api_client,
    PaddleCustomerManager $customer_manager,
    QueueFactory $queue_factory,
    LoggerInterface $logger
  ) {
    $this->apiClient = $api_client;
    $this->customerManager = $customer_manager;
    $this->queueFactory = $queue_factory;
    $this->logger = $logger;
  }

  /**
   * Process a webhook event.
   *
   * @param array $data
   *   The webhook event data.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function processWebhook(array $data) {
    $event_type = $data['event_type'] ?? '';

    $this->logger->info('Processing Paddle webhook: @type', ['@type' => $event_type]);

    switch ($event_type) {
      case 'transaction.completed':
        return $this->handleTransactionCompleted($data);

      case 'transaction.payment_failed':
        return $this->handleTransactionFailed($data);

      case 'transaction.refunded':
        return $this->handleTransactionRefunded($data);

      default:
        $this->logger->info('Unhandled webhook event type: @type', ['@type' => $event_type]);
        return TRUE;
    }
  }

  /**
   * Handle transaction.completed event.
   *
   * @param array $data
   *   The webhook event data.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected function handleTransactionCompleted(array $data) {
    $event_data = $data['data'] ?? [];

    // Extract transaction details.
    $transaction_id = $event_data['id'] ?? NULL;
    $customer_email = $event_data['customer_email'] ?? NULL;
    $customer_id = $event_data['customer_id'] ?? NULL;

    if (empty($transaction_id) || empty($customer_email)) {
      $this->logger->error('Invalid transaction data: missing transaction ID or email');
      return FALSE;
    }

    // Check for duplicate transaction (idempotency).
    $existing = $this->customerManager->getPurchaseByTransactionId($transaction_id);
    if ($existing && $existing->status === 'completed') {
      $this->logger->info('Duplicate transaction @txn already completed', ['@txn' => $transaction_id]);
      return TRUE;
    }

    // Extract amount and currency.
    $amount = NULL;
    $currency = NULL;
    if (isset($event_data['details']['totals'])) {
      $totals = $event_data['details']['totals'];
      // Paddle returns amount in cents, convert to decimal.
      $amount = isset($totals['total']) ? $totals['total'] / 100 : NULL;
      $currency = $totals['currency_code'] ?? NULL;
    }

    // Extract product ID.
    $product_id = NULL;
    if (isset($event_data['items']) && is_array($event_data['items']) && !empty($event_data['items'])) {
      $product_id = $event_data['items'][0]['product_id'] ?? NULL;
    }

    // Prepare transaction data.
    $transaction_data = [
      'transaction_id' => $transaction_id,
      'customer_email' => $customer_email,
      'customer_id' => $customer_id,
      'amount' => $amount,
      'currency' => $currency,
      'product_id' => $product_id,
      'metadata' => $event_data['custom_data'] ?? [],
    ];

    // Create pending purchase record.
    $purchase_id = $this->customerManager->createPendingPurchase($transaction_data);

    if (!$purchase_id) {
      $this->logger->error('Failed to create purchase record for transaction @txn', ['@txn' => $transaction_id]);
      return FALSE;
    }

    // Get the purchase record to retrieve the token.
    $purchase = $this->customerManager->getPurchaseByTransactionId($transaction_id);

    if (!$purchase) {
      $this->logger->error('Failed to retrieve purchase record after creation');
      return FALSE;
    }

    // Queue registration email.
    $queue = $this->queueFactory->get('paddle_registration_emails');
    $queue->createItem([
      'purchase_id' => $purchase->id,
      'email' => $purchase->customer_email,
      'token' => $purchase->registration_token,
    ]);

    $this->logger->info('Queued registration email for purchase @id', ['@id' => $purchase->id]);

    return TRUE;
  }

  /**
   * Handle transaction.payment_failed event.
   *
   * @param array $data
   *   The webhook event data.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function handleTransactionFailed(array $data) {
    $event_data = $data['data'] ?? [];
    $transaction_id = $event_data['id'] ?? NULL;

    $this->logger->warning('Payment failed for transaction @txn', ['@txn' => $transaction_id]);

    // Optionally update purchase record status to 'failed' if it exists.
    // For now, we just log it since the record may not exist yet.

    return TRUE;
  }

  /**
   * Handle transaction.refunded event.
   *
   * @param array $data
   *   The webhook event data.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function handleTransactionRefunded(array $data) {
    $event_data = $data['data'] ?? [];
    $transaction_id = $event_data['id'] ?? NULL;

    $this->logger->warning('Transaction refunded: @txn', ['@txn' => $transaction_id]);

    // Future: Update purchase status to 'refunded' and potentially revoke user access.

    return TRUE;
  }

}
