<?php

namespace Drupal\webform_client_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\webform_client_manager\ClientInterface;

/**
 * Defines the Client entity.
 *
 * @ConfigEntityType(
 *   id = "client",
 *   label = @Translation("Client"),
 *   label_collection = @Translation("Clients"),
 *   label_singular = @Translation("client"),
 *   label_plural = @Translation("clients"),
 *   label_count = @PluralTranslation(
 *     singular = "@count client",
 *     plural = "@count clients",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\webform_client_manager\ClientListBuilder",
 *     "form" = {
 *       "add" = "Drupal\webform_client_manager\Form\ClientForm",
 *       "edit" = "Drupal\webform_client_manager\Form\ClientForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "client",
 *   admin_permission = "administer clients",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "enabled_modules",
 *     "completion_redirect_url"
 *   },
 *   links = {
 *     "collection" = "/admin/structure/client",
 *     "add-form" = "/admin/structure/client/add",
 *     "edit-form" = "/admin/structure/client/{client}/edit",
 *     "delete-form" = "/admin/structure/client/{client}/delete"
 *   }
 * )
 */
class Client extends ConfigEntityBase implements ClientInterface {

  /**
   * The Client ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Client label.
   *
   * @var string
   */
  protected $label;

  /**
   * Enabled webform module IDs.
   *
   * @var array
   */
  protected $enabled_modules = [];

  /**
   * The URL to redirect to after completing all modules.
   *
   * @var string
   */
  protected $completion_redirect_url = '';

  /**
   * {@inheritdoc}
   */
  public function getEnabledModules() {
    return $this->enabled_modules ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setEnabledModules(array $modules) {
    $this->enabled_modules = $modules;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCompletionRedirectUrl() {
    return $this->completion_redirect_url ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setCompletionRedirectUrl($url) {
    $this->completion_redirect_url = $url;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSortedEnabledModules() {
    $modules = $this->getEnabledModules();

    // Sort by extracting module number from webform ID
    // Assuming format like "module_2", "task_analysis" (Module 2), etc.
    usort($modules, function($a, $b) {
      $num_a = $this->extractModuleNumber($a);
      $num_b = $this->extractModuleNumber($b);
      return $num_a <=> $num_b;
    });

    return $modules;
  }

  /**
   * Extract module number from webform ID.
   *
   * @param string $webform_id
   *   The webform ID.
   *
   * @return int
   *   The module number.
   */
  protected function extractModuleNumber($webform_id) {
    // Load the webform and extract module number from title.
    $webform = \Drupal::entityTypeManager()->getStorage('webform')->load($webform_id);

    if (!$webform) {
      return 999;
    }

    $title = $webform->label();

    // Extract number from "Module X: Name" format.
    if (preg_match('/^Module (\d+):/i', $title, $matches)) {
      return (int) $matches[1];
    }

    return 999;
  }

}
