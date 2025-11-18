<?php

namespace Drupal\client_webform;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

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
   * @return \Drupal\node\NodeInterface|null
   *   The client node or NULL.
   */
  public function getCurrentUserClient() {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if ($user && $user->hasField('field_client') && !$user->get('field_client')->isEmpty()) {
      $client_id = $user->get('field_client')->target_id;
      return $this->entityTypeManager->getStorage('node')->load($client_id);
    }

    return NULL;
  }

  /**
   * Get the client for a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The client node or NULL.
   */
  public function getUserClient($uid) {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    if ($user && $user->hasField('field_client') && !$user->get('field_client')->isEmpty()) {
      $client_id = $user->get('field_client')->target_id;
      return $this->entityTypeManager->getStorage('node')->load($client_id);
    }

    return NULL;
  }

  /**
   * Get enabled module IDs from a client node.
   *
   * @param \Drupal\node\NodeInterface $client
   *   The client node.
   *
   * @return array
   *   Array of module node IDs.
   */
  protected function getClientEnabledModules($client) {
    if (!$client || !$client->hasField('field_enabled_modules')) {
      return [];
    }

    $module_ids = [];
    foreach ($client->get('field_enabled_modules') as $item) {
      if (!empty($item->target_id)) {
        $module_ids[] = $item->target_id;
      }
    }

    return $module_ids;
  }

  /**
   * Get sorted enabled module IDs from a client node.
   *
   * @param \Drupal\node\NodeInterface $client
   *   The client node.
   *
   * @return array
   *   Array of module node IDs sorted by field_number.
   */
  protected function getClientSortedEnabledModules($client) {
    $modules = $this->getClientEnabledModules($client);

    // Sort by extracting module number from node's field_number.
    usort($modules, function($a, $b) {
      $num_a = $this->extractModuleNumber($a);
      $num_b = $this->extractModuleNumber($b);
      return $num_a <=> $num_b;
    });

    return $modules;
  }

  /**
   * Extract module number from Module node ID.
   *
   * @param int $nid
   *   The Module node ID.
   *
   * @return int
   *   The module number.
   */
  protected function extractModuleNumber($nid) {
    // Load the Module node and get field_number value.
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'module') {
      return 999;
    }

    // Get the module number from field_number.
    if ($node->hasField('field_number') && !$node->get('field_number')->isEmpty()) {
      return (int) $node->get('field_number')->value;
    }

    return 999;
  }

  /**
   * Get completion redirect URL from a client node.
   *
   * @param \Drupal\node\NodeInterface $client
   *   The client node.
   *
   * @return string|null
   *   The redirect URL or NULL.
   */
  protected function getClientCompletionRedirectUrl($client) {
    if (!$client || !$client->hasField('field_completion_redirect_url')) {
      return NULL;
    }

    if ($client->get('field_completion_redirect_url')->isEmpty()) {
      return NULL;
    }

    return $client->get('field_completion_redirect_url')->uri;
  }

  /**
   * Check if the current user has access to a Module node.
   *
   * @param int $nid
   *   The Module node ID.
   *
   * @return bool
   *   TRUE if user has access, FALSE otherwise.
   */
  public function userHasAccessToModule($nid) {
    // Allow admin users full access.
    if ($this->currentUser->hasPermission('administer clients')) {
      return TRUE;
    }

    $client = $this->getCurrentUserClient();

    if (!$client) {
      return FALSE;
    }

    $enabled_modules = $this->getClientEnabledModules($client);
    return in_array($nid, $enabled_modules);
  }

  /**
   * Check if the current user has access to a webform (via Module node).
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

    // Get enabled module node IDs.
    $enabled_modules = $this->getClientEnabledModules($client);

    // Load module nodes and check their field_form values.
    if (!empty($enabled_modules)) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($enabled_modules);
      foreach ($nodes as $node) {
        if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
          if ($node->get('field_form')->target_id === $webform_id) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Get the next Module node in the sequence after the given Module.
   *
   * @param int $current_nid
   *   The current Module node ID.
   *
   * @return int|null
   *   The next Module node ID or NULL if this is the last one.
   */
  public function getNextModule($current_nid) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return NULL;
    }

    $sorted_modules = $this->getClientSortedEnabledModules($client);
    $current_index = array_search($current_nid, $sorted_modules);

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

    // Find the Module node that contains this webform.
    $current_module_nid = $this->getModuleByWebform($current_webform_id);

    if (!$current_module_nid) {
      return NULL;
    }

    // Get the next Module node.
    $next_module_nid = $this->getNextModule($current_module_nid);

    if (!$next_module_nid) {
      return NULL;
    }

    // Load the next Module node and get its webform.
    $next_module = $this->entityTypeManager->getStorage('node')->load($next_module_nid);

    if ($next_module && $next_module->hasField('field_form') && !$next_module->get('field_form')->isEmpty()) {
      return $next_module->get('field_form')->target_id;
    }

    return NULL;
  }

  /**
   * Get the previous Module node in the sequence before the given Module.
   *
   * @param int $current_nid
   *   The current Module node ID.
   *
   * @return int|null
   *   The previous Module node ID or NULL if this is the first one.
   */
  public function getPreviousModule($current_nid) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return NULL;
    }

    $sorted_modules = $this->getClientSortedEnabledModules($client);
    $current_index = array_search($current_nid, $sorted_modules);

    if ($current_index === FALSE || $current_index === 0) {
      return NULL;
    }

    return $sorted_modules[$current_index - 1];
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

    // Find the Module node that contains this webform.
    $current_module_nid = $this->getModuleByWebform($current_webform_id);

    if (!$current_module_nid) {
      return NULL;
    }

    // Get the previous Module node.
    $previous_module_nid = $this->getPreviousModule($current_module_nid);

    if (!$previous_module_nid) {
      return NULL;
    }

    // Load the previous Module node and get its webform.
    $previous_module = $this->entityTypeManager->getStorage('node')->load($previous_module_nid);

    if ($previous_module && $previous_module->hasField('field_form') && !$previous_module->get('field_form')->isEmpty()) {
      return $previous_module->get('field_form')->target_id;
    }

    return NULL;
  }

  /**
   * Check if the given Module is the last one for the user's client.
   *
   * @param int $nid
   *   The Module node ID.
   *
   * @return bool
   *   TRUE if this is the last module, FALSE otherwise.
   */
  public function isLastModule($nid) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return FALSE;
    }

    $sorted_modules = $this->getClientSortedEnabledModules($client);

    if (empty($sorted_modules)) {
      return FALSE;
    }

    return end($sorted_modules) === $nid;
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
    // Find the Module node that contains this webform.
    $module_nid = $this->getModuleByWebform($webform_id);

    if (!$module_nid) {
      return FALSE;
    }

    return $this->isLastModule($module_nid);
  }

  /**
   * Get all enabled Module nodes for the current user's client.
   *
   * @return array
   *   Array of Module node IDs.
   */
  public function getEnabledModules() {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return [];
    }

    return $this->getClientSortedEnabledModules($client);
  }

  /**
   * Get all enabled webforms for the current user's client.
   *
   * @return array
   *   Array of webform IDs.
   */
  public function getEnabledWebforms() {
    $module_nids = $this->getEnabledModules();

    if (empty($module_nids)) {
      return [];
    }

    $webform_ids = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($module_nids);

    foreach ($nodes as $node) {
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_ids[] = $node->get('field_form')->target_id;
      }
    }

    return $webform_ids;
  }

  /**
   * Get the Module node that contains the given webform.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return int|null
   *   The Module node ID or NULL if not found.
   */
  public function getModuleByWebform($webform_id) {
    $client = $this->getCurrentUserClient();

    if (!$client) {
      return NULL;
    }

    $enabled_modules = $this->getClientEnabledModules($client);

    if (empty($enabled_modules)) {
      return NULL;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($enabled_modules);

    foreach ($nodes as $nid => $node) {
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        if ($node->get('field_form')->target_id === $webform_id) {
          return $nid;
        }
      }
    }

    return NULL;
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

    return $this->getClientCompletionRedirectUrl($client);
  }

}
