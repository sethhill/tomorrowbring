<?php

namespace Drupal\ai_report_storage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access controller for the AI Report entity.
 */
class AiReportAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\ai_report_storage\Entity\AiReportInterface $entity */

    // Admin permission grants all access.
    if ($account->hasPermission('administer ai reports')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    switch ($operation) {
      case 'view':
        // Users can view their own reports.
        if ($account->hasPermission('view own ai reports') && $entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        return AccessResult::neutral()
          ->cachePerPermissions()
          ->cachePerUser();

      case 'delete':
        // Users can delete their own reports.
        if ($account->hasPermission('delete own ai reports') && $entity->getOwnerId() == $account->id()) {
          return AccessResult::allowed()
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        return AccessResult::neutral()
          ->cachePerPermissions()
          ->cachePerUser();

      default:
        return AccessResult::neutral()->cachePerPermissions();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Only allow programmatic creation - users don't create reports directly.
    return AccessResult::allowedIfHasPermission($account, 'administer ai reports');
  }

}
