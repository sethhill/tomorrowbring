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
   * Add the Client Module Flow handler to all module webforms.
   *
   * @command webform-client-manager:add-handlers
   * @aliases wcm-add-handlers
   * @usage webform-client-manager:add-handlers
   *   Add the Client Module Flow handler to all module webforms.
   */
  public function addHandlers() {
    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple();
    $count = 0;

    foreach ($webforms as $webform) {
      // Check if this is a module webform.
      if (strpos($webform->label(), 'Module') !== 0) {
        continue;
      }

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
   * Remove the Client Module Flow handler from all module webforms.
   *
   * @command webform-client-manager:remove-handlers
   * @aliases wcm-remove-handlers
   * @usage webform-client-manager:remove-handlers
   *   Remove the Client Module Flow handler from all module webforms.
   */
  public function removeHandlers() {
    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple();
    $count = 0;

    foreach ($webforms as $webform) {
      // Check if this is a module webform.
      if (strpos($webform->label(), 'Module') !== 0) {
        continue;
      }

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

}
