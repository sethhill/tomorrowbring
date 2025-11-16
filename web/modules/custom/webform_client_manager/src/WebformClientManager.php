<?php

namespace Drupal\webform_client_manager;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform_client_manager\Entity\Client;

/**
 * Service for managing webform client access and flow.
 */
class WebformClientManager {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a WebformClientManager object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Get the client for the current user.
   *
   * @return \Drupal\webform_client_manager\ClientInterface|null
   *   The client entity or NULL.
   */
  public function getCurrentUserClient() {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if ($user && $user->hasField('field_client') && !$user->get('field_client')->isEmpty()) {
      $client_id = $user->get('field_client')->target_id;
      return $this->entityTypeManager->getStorage('client')->load($client_id);
    }

    return NULL;
  }

  /**
   * Get the client for a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\webform_client_manager\ClientInterface|null
   *   The client entity or NULL.
   */
  public function getUserClient($uid) {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    if ($user && $user->hasField('field_client') && !$user->get('field_client')->isEmpty()) {
      $client_id = $user->get('field_client')->target_id;
      return $this->entityTypeManager->getStorage('client')->load($client_id);
    }

    return NULL;
  }

  /**
   * Check if the current user has access to a webform.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return bool
   *   TRUE if user has access, FALSE otherwise.
   */
  public function userHasAccessToWebform($webform_id) {
    // Allow admin users full access.
    if ($this->currentUser->hasPermission('administer clients')) {
      return TRUE;
    }

    $client = $this->getCurrentUserClient();

    if (!$client) {
      return FALSE;
    }

    return in_array($webform_id, $client->getEnabledModules());
  }

  /**
   * Get the next webform in the sequence after the given webform.
   *
   * @param string $current_webform_id
   *   The current webform ID.
   *
   * @return string|null
   *   The next webform ID or NULL if this is the last one.
   */
  public function getNextWebform($current_webform_id) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return NULL;
    }

    $sorted_modules = $client->getSortedEnabledModules();
    $current_index = array_search($current_webform_id, $sorted_modules);

    if ($current_index === FALSE) {
      return NULL;
    }

    // Check if there's a next module.
    if (isset($sorted_modules[$current_index + 1])) {
      return $sorted_modules[$current_index + 1];
    }

    return NULL;
  }

  /**
   * Get the previous webform in the sequence before the given webform.
   *
   * @param string $current_webform_id
   *   The current webform ID.
   *
   * @return string|null
   *   The previous webform ID or NULL if this is the first one.
   */
  public function getPreviousWebform($current_webform_id) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return NULL;
    }

    $sorted_modules = $client->getSortedEnabledModules();
    $current_index = array_search($current_webform_id, $sorted_modules);

    if ($current_index === FALSE || $current_index === 0) {
      return NULL;
    }

    return $sorted_modules[$current_index - 1];
  }

  /**
   * Check if the given webform is the last one for the user's client.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return bool
   *   TRUE if this is the last webform, FALSE otherwise.
   */
  public function isLastWebform($webform_id) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return FALSE;
    }

    $sorted_modules = $client->getSortedEnabledModules();

    if (empty($sorted_modules)) {
      return FALSE;
    }

    return end($sorted_modules) === $webform_id;
  }

  /**
   * Get all enabled webforms for the current user's client.
   *
   * @return array
   *   Array of webform IDs.
   */
  public function getEnabledWebforms() {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return [];
    }

    return $client->getSortedEnabledModules();
  }

  /**
   * Get the completion redirect URL for the current user's client.
   *
   * @return string|null
   *   The redirect URL or NULL.
   */
  public function getCompletionRedirectUrl() {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return NULL;
    }

    return $client->getCompletionRedirectUrl();
  }

}
