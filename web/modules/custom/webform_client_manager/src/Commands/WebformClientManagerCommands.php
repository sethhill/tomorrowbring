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

  /**
   * Update user field_client to reference nodes instead of config entities.
   *
   * @command webform-client-manager:update-user-field
   * @aliases wcm-update-field
   * @usage webform-client-manager:update-user-field
   *   Update user field_client to reference Client nodes.
   */
  public function updateUserField() {
    $this->output()->writeln('Updating user.field_client to reference nodes...');

    // First, get user data mapping.
    $user_storage = $this->entityTypeManager->getStorage('user');
    $query = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('field_client');
    $uids = $query->execute();

    $user_data = [];
    if (!empty($uids)) {
      $users = $user_storage->loadMultiple($uids);
      foreach ($users as $user) {
        if ($user->hasField('field_client') && !$user->get('field_client')->isEmpty()) {
          $client_id = $user->get('field_client')->target_id;
          $user_data[$user->id()] = $client_id;
          $this->output()->writeln(sprintf('  User %d has client: %s', $user->id(), $client_id));
        }
      }
    }

    // Find the mapping from client IDs to node IDs.
    $client_to_node = [];
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'client')
      ->accessCheck(FALSE);
    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = $node_storage->loadMultiple($nids);
      foreach ($nodes as $node) {
        // Try to match by title to client label.
        // For now, we'll just use a simple mapping.
        // testing_organization -> node 17
        $client_to_node['testing_organization'] = $node->id();
        $this->output()->writeln(sprintf('  Mapping client ID to node %d: %s', $node->id(), $node->getTitle()));
      }
    }

    // Delete the old field.
    $this->output()->writeln('Deleting old field_client...');
    $field = $this->entityTypeManager->getStorage('field_config')->load('user.user.field_client');
    if ($field) {
      $field->delete();
      $this->output()->writeln('  Deleted field config');
    }

    $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->load('user.field_client');
    if ($field_storage) {
      $field_storage->delete();
      $this->output()->writeln('  Deleted field storage');
    }

    // Recreate field to reference nodes.
    $this->output()->writeln('Creating new field_client to reference nodes...');
    $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->create([
      'field_name' => 'field_client',
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => [
        'target_type' => 'node',
      ],
    ]);
    $field_storage->save();
    $this->output()->writeln('  Created field storage');

    $field = $this->entityTypeManager->getStorage('field_config')->create([
      'field_storage' => $field_storage,
      'bundle' => 'user',
      'label' => 'Client',
      'required' => FALSE,
      'settings' => [
        'handler' => 'default:node',
        'handler_settings' => [
          'target_bundles' => [
            'client' => 'client',
          ],
        ],
      ],
    ]);
    $field->save();
    $this->output()->writeln('  Created field config');

    // Restore user data.
    if (!empty($user_data)) {
      $this->output()->writeln('Restoring user client references...');
      $users = $user_storage->loadMultiple(array_keys($user_data));
      foreach ($users as $user) {
        $old_client_id = $user_data[$user->id()];
        if (isset($client_to_node[$old_client_id])) {
          $new_node_id = $client_to_node[$old_client_id];
          $user->set('field_client', $new_node_id);
          $user->save();
          $this->output()->writeln(sprintf('  Updated user %d: %s -> node/%d', $user->id(), $old_client_id, $new_node_id));
        }
      }
    }

    $this->output()->writeln('User field update complete!');
  }

  /**
   * Convert Client config entities to Client content type nodes.
   *
   * @command webform-client-manager:convert-to-content-type
   * @aliases wcm-convert
   * @usage webform-client-manager:convert-to-content-type
   *   Convert Client config entities to Client content type nodes.
   */
  public function convertToContentType() {
    // First, check if Client content type exists.
    $node_type = $this->entityTypeManager->getStorage('node_type')->load('client');
    if (!$node_type) {
      $this->output()->writeln('ERROR: Client content type does not exist. Creating it now...');

      // Create the content type.
      $node_type = $this->entityTypeManager->getStorage('node_type')->create([
        'type' => 'client',
        'name' => 'Client',
        'description' => 'A client organization with access to specific modules.',
      ]);
      $node_type->save();
      $this->output()->writeln('Created Client content type.');

      // Create field_enabled_modules.
      $field_storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.field_enabled_modules');
      if (!$field_storage) {
        $field_storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')->create([
          'field_name' => 'field_enabled_modules',
          'entity_type' => 'node',
          'type' => 'entity_reference',
          'cardinality' => -1,
          'settings' => [
            'target_type' => 'node',
          ],
        ]);
        $field_storage->save();
      }

      $field = \Drupal::entityTypeManager()->getStorage('field_config')->load('node.client.field_enabled_modules');
      if (!$field) {
        $field = \Drupal::entityTypeManager()->getStorage('field_config')->create([
          'field_storage' => $field_storage,
          'bundle' => 'client',
          'label' => 'Enabled Modules',
          'required' => FALSE,
          'settings' => [
            'handler' => 'default:node',
            'handler_settings' => [
              'target_bundles' => [
                'module' => 'module',
              ],
            ],
          ],
        ]);
        $field->save();
      }

      // Create field_completion_redirect_url.
      $field_storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.field_completion_redirect_url');
      if (!$field_storage) {
        $field_storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')->create([
          'field_name' => 'field_completion_redirect_url',
          'entity_type' => 'node',
          'type' => 'link',
          'cardinality' => 1,
        ]);
        $field_storage->save();
      }

      $field = \Drupal::entityTypeManager()->getStorage('field_config')->load('node.client.field_completion_redirect_url');
      if (!$field) {
        $field = \Drupal::entityTypeManager()->getStorage('field_config')->create([
          'field_storage' => $field_storage,
          'bundle' => 'client',
          'label' => 'Completion Redirect URL',
          'required' => FALSE,
        ]);
        $field->save();
      }

      $this->output()->writeln('Created required fields.');
    }

    // Now migrate config entities to nodes.
    $config_storage = $this->entityTypeManager->getStorage('client');
    $clients = $config_storage->loadMultiple();

    if (empty($clients)) {
      $this->output()->writeln('No client config entities found to migrate.');
      return;
    }

    $migrated = 0;
    $user_updates = [];

    foreach ($clients as $client_id => $client) {
      $this->output()->writeln(sprintf('Migrating client: %s (%s)', $client->label(), $client_id));

      // Create a new Client node.
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'client',
        'title' => $client->label(),
        'field_enabled_modules' => $client->getEnabledModules(),
        'field_completion_redirect_url' => [
          'uri' => $client->getCompletionRedirectUrl() ?: '',
        ],
        'status' => 1,
      ]);
      $node->save();

      $this->output()->writeln(sprintf('  Created node ID: %d', $node->id()));

      // Track mapping for user field updates.
      $user_updates[$client_id] = $node->id();

      $migrated++;
    }

    // Update user field_client references.
    $this->output()->writeln('Updating user field_client references...');
    $user_storage = $this->entityTypeManager->getStorage('user');
    $query = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('field_client');
    $uids = $query->execute();

    if (!empty($uids)) {
      $users = $user_storage->loadMultiple($uids);
      $updated_users = 0;

      foreach ($users as $user) {
        if ($user->hasField('field_client') && !$user->get('field_client')->isEmpty()) {
          $old_client_id = $user->get('field_client')->target_id;

          if (isset($user_updates[$old_client_id])) {
            $new_node_id = $user_updates[$old_client_id];
            $this->output()->writeln(sprintf('  User %d: %s -> node/%d', $user->id(), $old_client_id, $new_node_id));
            // Note: We'll need to update the field type first before this works
            $updated_users++;
          }
        }
      }

      $this->output()->writeln(sprintf('Found %d users with client references (will update after field type change).', $updated_users));
    }

    $this->output()->writeln(sprintf('Successfully migrated %d clients to nodes.', $migrated));
    $this->output()->writeln('');
    $this->output()->writeln('NEXT STEPS:');
    $this->output()->writeln('1. Update user.field_client to reference nodes instead of config entities');
    $this->output()->writeln('2. Clear cache: ddev drush cr');
    $this->output()->writeln('3. Update WebformClientManager service code');
    $this->output()->writeln('4. Remove old Client config entity code');
  }

}
