<?php

namespace Drupal\webform_client_manager\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Webform Client Manager.
 */
class WebformClientManagerCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a WebformClientManagerCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Add the Client Module Flow handler to all webforms referenced by Module nodes.
   *
   * @command webform-client-manager:add-handlers
   * @aliases wcm-add-handlers
   * @usage webform-client-manager:add-handlers
   *   Add the Client Module Flow handler to all webforms referenced by Module nodes.
   */
  public function addHandlers() {
    // Get all Module nodes.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'module')
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if (empty($nids)) {
      $this->output()->writeln('No Module nodes found.');
      return;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $count = 0;
    $webform_ids = [];

    // Collect all webform IDs from Module nodes.
    foreach ($nodes as $node) {
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_id = $node->get('field_form')->target_id;
        if (!in_array($webform_id, $webform_ids)) {
          $webform_ids[] = $webform_id;
        }
      }
    }

    if (empty($webform_ids)) {
      $this->output()->writeln('No webforms found in Module nodes.');
      return;
    }

    // Load webforms and add handlers.
    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple($webform_ids);

    foreach ($webforms as $webform) {
      // Check if handler already exists.
      $handlers = $webform->getHandlers();
      $has_handler = FALSE;

      foreach ($handlers as $handler) {
        if ($handler->getPluginId() === 'client_module_flow') {
          $has_handler = TRUE;
          break;
        }
      }

      if ($has_handler) {
        $this->output()->writeln(sprintf('Handler already exists on %s', $webform->label()));
        continue;
      }

      // Add the handler.
      $webform->addWebformHandler([
        'id' => 'client_module_flow',
        'label' => 'Client Module Flow',
        'handler_id' => 'client_module_flow',
        'status' => TRUE,
        'weight' => 0,
        'settings' => [],
      ]);

      $webform->save();
      $count++;

      $this->output()->writeln(sprintf('Added handler to %s', $webform->label()));
    }

    $this->output()->writeln(sprintf('Added handlers to %d webforms.', $count));
  }

  /**
   * Remove the Client Module Flow handler from all webforms referenced by Module nodes.
   *
   * @command webform-client-manager:remove-handlers
   * @aliases wcm-remove-handlers
   * @usage webform-client-manager:remove-handlers
   *   Remove the Client Module Flow handler from all webforms referenced by Module nodes.
   */
  public function removeHandlers() {
    // Get all Module nodes.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'module')
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if (empty($nids)) {
      $this->output()->writeln('No Module nodes found.');
      return;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $count = 0;
    $webform_ids = [];

    // Collect all webform IDs from Module nodes.
    foreach ($nodes as $node) {
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_id = $node->get('field_form')->target_id;
        if (!in_array($webform_id, $webform_ids)) {
          $webform_ids[] = $webform_id;
        }
      }
    }

    if (empty($webform_ids)) {
      $this->output()->writeln('No webforms found in Module nodes.');
      return;
    }

    // Load webforms and remove handlers.
    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple($webform_ids);

    foreach ($webforms as $webform) {
      // Check if handler exists and remove it.
      $handlers = $webform->getHandlers();

      foreach ($handlers as $instance_id => $handler) {
        if ($handler->getPluginId() === 'client_module_flow') {
          $webform->deleteWebformHandler($instance_id);
          $webform->save();
          $count++;

          $this->output()->writeln(sprintf('Removed handler from %s', $webform->label()));
          break;
        }
      }
    }

    $this->output()->writeln(sprintf('Removed handlers from %d webforms.', $count));
  }

  /**
   * Configure webforms referenced by Module nodes to allow viewing own submissions.
   *
   * @command webform-client-manager:configure-access
   * @aliases wcm-configure-access
   * @usage webform-client-manager:configure-access
   *   Configure webforms to allow users to view their own submissions.
   */
  public function configureAccess() {
    // Get all Module nodes.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'module')
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if (empty($nids)) {
      $this->output()->writeln('No Module nodes found.');
      return;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $count = 0;
    $webform_ids = [];

    // Collect all webform IDs from Module nodes.
    foreach ($nodes as $node) {
      if ($node->hasField('field_form') && !$node->get('field_form')->isEmpty()) {
        $webform_id = $node->get('field_form')->target_id;
        if (!in_array($webform_id, $webform_ids)) {
          $webform_ids[] = $webform_id;
        }
      }
    }

    if (empty($webform_ids)) {
      $this->output()->writeln('No webforms found in Module nodes.');
      return;
    }

    // Load webforms and configure access.
    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple($webform_ids);

    foreach ($webforms as $webform) {
      // Get current access settings.
      $access = $webform->getAccessRules();

      // Enable view_own access for authenticated users.
      if (!isset($access['view_own'])) {
        $access['view_own'] = [];
      }

      if (!isset($access['view_own']['roles']) || !in_array('authenticated', $access['view_own']['roles'])) {
        if (!isset($access['view_own']['roles'])) {
          $access['view_own']['roles'] = [];
        }
        $access['view_own']['roles'][] = 'authenticated';
        $access['view_own']['roles'][] = 'member';

        $webform->setAccessRules($access);
        $webform->save();
        $count++;

        $this->output()->writeln(sprintf('Configured access for %s', $webform->label()));
      }
    }

    $this->output()->writeln(sprintf('Configured access for %d webforms.', $count));
  }

  /**
   * Migrate client configurations from webform IDs to Module node IDs.
   *
   * @command webform-client-manager:migrate-to-modules
   * @aliases wcm-migrate
   * @usage webform-client-manager:migrate-to-modules
   *   Migrate client enabled_modules from webform IDs to Module node IDs.
   */
  public function migrateToModules() {
    $client_storage = $this->entityTypeManager->getStorage('client');
    $clients = $client_storage->loadMultiple();

    if (empty($clients)) {
      $this->output()->writeln('No clients found.');
      return;
    }

    $migrated_count = 0;

    foreach ($clients as $client) {
      $enabled_modules = $client->getEnabledModules();

      if (empty($enabled_modules)) {
        $this->output()->writeln(sprintf('Client "%s" has no enabled modules.', $client->label()));
        continue;
      }

      // Check if the first item is a webform ID or node ID.
      $first_item = reset($enabled_modules);

      // If it's numeric, assume it's already a node ID.
      if (is_numeric($first_item)) {
        $this->output()->writeln(sprintf('Client "%s" appears to already use Module node IDs.', $client->label()));
        continue;
      }

      // It's a webform ID, migrate to Module node IDs.
      $new_module_ids = [];

      foreach ($enabled_modules as $webform_id) {
        // Find the Module node that references this webform.
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', 'module')
          ->condition('field_form', $webform_id)
          ->accessCheck(FALSE)
          ->range(0, 1);
        $nids = $query->execute();

        if (!empty($nids)) {
          $nid = reset($nids);
          $new_module_ids[] = $nid;
          $this->output()->writeln(sprintf('  Mapped webform "%s" to Module node %d', $webform_id, $nid));
        }
        else {
          $this->output()->writeln(sprintf('  WARNING: No Module node found for webform "%s"', $webform_id));
        }
      }

      if (!empty($new_module_ids)) {
        $client->setEnabledModules($new_module_ids);
        $client->save();
        $migrated_count++;
        $this->output()->writeln(sprintf('Migrated client "%s" from webform IDs to Module node IDs.', $client->label()));
      }
    }

    $this->output()->writeln(sprintf('Successfully migrated %d clients.', $migrated_count));
  }

}
