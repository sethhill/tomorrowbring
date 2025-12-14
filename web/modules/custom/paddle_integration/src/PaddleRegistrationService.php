<?php

namespace Drupal\paddle_integration;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling user registration after payment.
 */
class PaddleRegistrationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a PaddleRegistrationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param object $password_generator
   *   The password generator service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    $password_generator,
    LoggerInterface $logger,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->passwordGenerator = $password_generator;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
  }

  /**
   * Create a user account from purchase and form data.
   *
   * @param object $purchase
   *   The purchase record.
   * @param array $form_data
   *   Data from the registration form.
   *
   * @return \Drupal\user\UserInterface|false
   *   The created user, or FALSE on failure.
   */
  public function createUserAccount($purchase, array $form_data) {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');

      // Check if email already exists.
      $existing_users = $user_storage->loadByProperties(['mail' => $purchase->customer_email]);
      if (!empty($existing_users)) {
        $this->logger->error('User with email @email already exists', ['@email' => $purchase->customer_email]);
        return FALSE;
      }

      // Create user account.
      $user = $user_storage->create([
        'name' => $form_data['username'],
        'mail' => $purchase->customer_email,
        'pass' => $form_data['password'],
        'status' => 1,
        'init' => $purchase->customer_email,
      ]);

      $user->save();

      $this->logger->info('Created user account @uid for @email', [
        '@uid' => $user->id(),
        '@email' => $purchase->customer_email,
      ]);

      // Member role is auto-assigned by client_webform_user_insert() hook.

      // Assign to Individual client.
      $this->assignToIndividualClient($user);

      // Setup member profile.
      $this->setupMemberProfile($user, $form_data);

      // Store registration source metadata.
      \Drupal::service('user.data')->set(
        'paddle_integration',
        $user->id(),
        'registration_source',
        'paddle_self_service'
      );

      return $user;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create user account: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Assign user to the Individual client.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to assign.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function assignToIndividualClient($user) {
    try {
      $config = $this->configFactory->get('paddle_integration.settings');
      $client_nid = $config->get('individual_client_nid');

      if (empty($client_nid)) {
        throw new \Exception('Individual client not configured');
      }

      // Verify client exists.
      $client = $this->entityTypeManager->getStorage('node')->load($client_nid);
      if (!$client || $client->bundle() !== 'client') {
        throw new \Exception('Invalid individual client configuration');
      }

      // Assign user to client.
      $user->set('field_client', $client_nid);
      $user->save();

      $this->logger->info('Assigned user @uid to Individual client (NID: @nid)', [
        '@uid' => $user->id(),
        '@nid' => $client_nid,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to assign user to Individual client: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Setup member profile with form data.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param array $form_data
   *   Form data containing profile fields.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function setupMemberProfile($user, array $form_data) {
    try {
      $profile_storage = $this->entityTypeManager->getStorage('profile');

      // Load or create member profile.
      $profiles = $profile_storage->loadByProperties([
        'uid' => $user->id(),
        'type' => 'member',
      ]);

      if (empty($profiles)) {
        $profile = $profile_storage->create([
          'type' => 'member',
          'uid' => $user->id(),
        ]);
      }
      else {
        $profile = reset($profiles);
      }

      // Set profile fields from form data.
      if (isset($form_data['name'])) {
        $profile->set('field_name', $form_data['name']);
      }
      if (isset($form_data['industry'])) {
        $profile->set('field_industry', $form_data['industry']);
      }
      if (isset($form_data['company_size'])) {
        $profile->set('field_company_size', $form_data['company_size']);
      }

      $profile->save();

      $this->logger->info('Created/updated member profile for user @uid', ['@uid' => $user->id()]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to setup member profile: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

}
