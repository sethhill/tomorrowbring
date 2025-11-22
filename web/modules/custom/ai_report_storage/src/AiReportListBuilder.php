<?php

namespace Drupal\ai_report_storage;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Defines a class to build a listing of AI Report entities.
 */
class AiReportListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('ID');
    $header['type'] = $this->t('Type');
    $header['version'] = $this->t('Version');
    $header['owner'] = $this->t('Owner');
    $header['status'] = $this->t('Status');
    $header['generated'] = $this->t('Generated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_report_storage\Entity\AiReportInterface $entity */
    $row['id'] = $entity->id();
    $row['type'] = $entity->getType();
    $row['version'] = $entity->getVersion();
    $row['owner'] = $entity->getOwner() ? $entity->getOwner()->getDisplayName() : $this->t('N/A');
    $row['status'] = $entity->getStatus();
    $row['generated'] = \Drupal::service('date.formatter')->format($entity->getGeneratedAt(), 'short');
    return $row + parent::buildRow($entity);
  }

}
