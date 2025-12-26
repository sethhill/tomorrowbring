<?php

namespace Drupal\paddle_integration;

use Drupal\Core\Database\Connection;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages Paddle purchase records and registration tokens.
 */
class PaddleCustomerManager {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The password generator service.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface
   */
  protected $passwordGenerator;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a PaddleCustomerManager.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param object $password_generator
   *   The password generator service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(Connection $database, $password_generator, LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->passwordGenerator = $password_generator;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
  }

  /**
   * Generate a unique registration token.
   *
   * @return string
   *   A unique token.
   */
  protected function generateToken() {
    // Generate a cryptographically secure random token.
    return Crypt::randomBytesBase64(48);
  }

  /**
   * Create a pending purchase record.
   *
   * @param array $transaction_data
   *   Transaction data from Paddle webhook.
   *
   * @return int|false
   *   The purchase ID, or FALSE on failure.
   */
  public function createPendingPurchase(array $transaction_data) {
    $config = $this->configFactory->get('paddle_integration.settings');
    $token_lifetime = $config->get('registration_token_lifetime') ?: 604800;

    $token = $this->generateToken();
    $expiry = time() + $token_lifetime;

    try {
      $id = $this->database->insert('paddle_purchases')
        ->fields([
          'transaction_id' => $transaction_data['transaction_id'],
          'customer_email' => $transaction_data['customer_email'],
          'paddle_customer_id' => $transaction_data['customer_id'] ?? NULL,
          'status' => 'pending',
          'amount' => $transaction_data['amount'] ?? NULL,
          'currency' => $transaction_data['currency'] ?? NULL,
          'product_id' => $transaction_data['product_id'] ?? NULL,
          'registration_token' => $token,
          'token_expiry' => $expiry,
          'created' => time(),
          'metadata' => json_encode($transaction_data['metadata'] ?? []),
        ])
        ->execute();

      $this->logger->info('Created pending purchase record @id for transaction @txn', [
        '@id' => $id,
        '@txn' => $transaction_data['transaction_id'],
      ]);

      return $id;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create purchase record: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get a pending purchase by registration token.
   *
   * @param string $token
   *   The registration token.
   *
   * @return object|false
   *   The purchase record, or FALSE if not found/invalid.
   */
  public function getPendingPurchaseByToken($token) {
    try {
      $purchase = $this->database->select('paddle_purchases', 'p')
        ->fields('p')
        ->condition('registration_token', $token)
        ->condition('status', 'pending')
        ->condition('token_expiry', time(), '>')
        ->execute()
        ->fetchObject();

      return $purchase ?: FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch purchase by token: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get a purchase by transaction ID.
   *
   * @param string $transaction_id
   *   The Paddle transaction ID.
   *
   * @return object|false
   *   The purchase record, or FALSE if not found.
   */
  public function getPurchaseByTransactionId($transaction_id) {
    try {
      $purchase = $this->database->select('paddle_purchases', 'p')
        ->fields('p')
        ->condition('transaction_id', $transaction_id)
        ->execute()
        ->fetchObject();

      return $purchase ?: FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch purchase by transaction ID: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Complete a purchase (update to completed status).
   *
   * @param int $purchase_id
   *   The purchase ID.
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function completePurchase($purchase_id, $uid) {
    try {
      $this->database->update('paddle_purchases')
        ->fields([
          'status' => 'completed',
          'uid' => $uid,
          'completed' => time(),
        ])
        ->condition('id', $purchase_id)
        ->execute();

      $this->logger->info('Completed purchase @id for user @uid', [
        '@id' => $purchase_id,
        '@uid' => $uid,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to complete purchase: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Update email sent timestamp.
   *
   * @param int $purchase_id
   *   The purchase ID.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function markEmailSent($purchase_id) {
    try {
      $this->database->update('paddle_purchases')
        ->fields(['email_sent' => time()])
        ->condition('id', $purchase_id)
        ->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to mark email sent: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Check if a user has a valid purchase.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return bool
   *   TRUE if user has a completed purchase.
   */
  public function isPurchaseValid($uid) {
    try {
      $count = $this->database->select('paddle_purchases', 'p')
        ->condition('uid', $uid)
        ->condition('status', 'completed')
        ->countQuery()
        ->execute()
        ->fetchField();

      return $count > 0;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check purchase validity: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Create a pre-checkout record before payment.
   *
   * @param array $data
   *   Array containing:
   *   - checkout_session_id: Unique UUID for this checkout session
   *   - customer_email: Customer email address
   *   - customer_first_name: Customer first name
   *   - customer_last_name: Customer last name
   *
   * @return int|false
   *   The purchase ID, or FALSE on failure.
   */
  public function createPreCheckoutRecord(array $data) {
    try {
      $id = $this->database->insert('paddle_purchases')
        ->fields([
          'checkout_session_id' => $data['checkout_session_id'],
          'customer_email' => $data['customer_email'],
          'customer_first_name' => $data['customer_first_name'],
          'customer_last_name' => $data['customer_last_name'],
          'status' => 'pre_checkout',
          'created' => time(),
          'transaction_id' => NULL,  // Will be filled when webhook arrives.
        ])
        ->execute();

      $this->logger->info('Created pre-checkout record @id for session @session', [
        '@id' => $id,
        '@session' => $data['checkout_session_id'],
      ]);

      return $id;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create pre-checkout record: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get a pre-checkout record by session ID.
   *
   * @param string $session_id
   *   The checkout session ID.
   *
   * @return object|false
   *   The purchase record, or FALSE if not found.
   */
  public function getPreCheckoutBySessionId($session_id) {
    try {
      $purchase = $this->database->select('paddle_purchases', 'p')
        ->fields('p')
        ->condition('checkout_session_id', $session_id)
        ->execute()
        ->fetchObject();

      return $purchase ?: FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch pre-checkout by session ID: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Update a pre-checkout record to pending status.
   *
   * @param string $session_id
   *   The checkout session ID.
   * @param array $transaction_data
   *   Transaction data from Paddle webhook.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function updateToPending($session_id, array $transaction_data) {
    $config = $this->configFactory->get('paddle_integration.settings');
    $token_lifetime = $config->get('registration_token_lifetime') ?: 604800;

    $token = $this->generateToken();
    $expiry = time() + $token_lifetime;

    try {
      // First, get the existing record to preserve names.
      $existing = $this->getPreCheckoutBySessionId($session_id);
      if (!$existing) {
        $this->logger->warning('No pre-checkout record found for session @session', [
          '@session' => $session_id,
        ]);
        return FALSE;
      }

      // Update the record with transaction details.
      $this->database->update('paddle_purchases')
        ->fields([
          'transaction_id' => $transaction_data['transaction_id'],
          'paddle_customer_id' => $transaction_data['customer_id'] ?? NULL,
          'status' => 'pending',
          'amount' => $transaction_data['amount'] ?? NULL,
          'currency' => $transaction_data['currency'] ?? NULL,
          'product_id' => $transaction_data['product_id'] ?? NULL,
          'registration_token' => $token,
          'token_expiry' => $expiry,
          'metadata' => json_encode($transaction_data['metadata'] ?? []),
          // Preserve customer_first_name and customer_last_name from existing record.
          // Override customer_email if it changed in Paddle.
          'customer_email' => $transaction_data['customer_email'] ?? $existing->customer_email,
        ])
        ->condition('checkout_session_id', $session_id)
        ->execute();

      $this->logger->info('Updated pre-checkout @session to pending with transaction @txn', [
        '@session' => $session_id,
        '@txn' => $transaction_data['transaction_id'],
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update pre-checkout to pending: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Clean up abandoned pre-checkout records.
   *
   * Deletes records with status 'pre_checkout' older than 24 hours.
   *
   * @return int
   *   Number of records deleted.
   */
  public function cleanupAbandonedCheckouts() {
    try {
      $cutoff = time() - 86400; // 24 hours ago.
      $num_deleted = $this->database->delete('paddle_purchases')
        ->condition('status', 'pre_checkout')
        ->condition('created', $cutoff, '<')
        ->execute();

      if ($num_deleted > 0) {
        $this->logger->info('Cleaned up @count abandoned pre-checkout records', [
          '@count' => $num_deleted,
        ]);
      }

      return $num_deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clean up abandoned checkouts: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
