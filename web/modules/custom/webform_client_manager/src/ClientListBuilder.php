<?php

namespace Drupal\webform_client_manager;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of Client entities.
 */
class ClientListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Client Name');
    $header['id'] = $this->t('Machine name');
    $header['modules'] = $this->t('Enabled Modules');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\webform_client_manager\ClientInterface $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['modules'] = count($entity->getEnabledModules()) . ' modules';
    return $row + parent::buildRow($entity);
  }

}
