<?php

namespace Drupal\webform_client_manager;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining a Client entity.
 */
interface ClientInterface extends ConfigEntityInterface {

  /**
   * Gets the enabled webform modules.
   *
   * @return array
   *   Array of webform IDs.
   */
  public function getEnabledModules();

  /**
   * Sets the enabled webform modules.
   *
   * @param array $modules
   *   Array of webform IDs.
   *
   * @return $this
   */
  public function setEnabledModules(array $modules);

  /**
   * Gets the completion redirect URL.
   *
   * @return string
   *   The redirect URL.
   */
  public function getCompletionRedirectUrl();

  /**
   * Sets the completion redirect URL.
   *
   * @param string $url
   *   The redirect URL.
   *
   * @return $this
   */
  public function setCompletionRedirectUrl($url);

  /**
   * Gets the enabled modules sorted by module number.
   *
   * @return array
   *   Array of webform IDs sorted by module number.
   */
  public function getSortedEnabledModules();

}
