<?php

namespace Drupal\fact_drift\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Controls who may view and change findings.
 *
 * Viewing needs "view fact drift findings", updating needs "manage fact drift
 * findings", and deleting needs "administer fact drift". The administer
 * permission passes every operation.
 */
class FindingAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $permission = match ($operation) {
      'view' => 'view fact drift findings',
      'update' => 'manage fact drift findings',
      default => 'administer fact drift',
    };
    return AccessResult::allowedIfHasPermissions($account, ['administer fact drift', $permission], 'OR');
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param array<string, mixed> $context
   *   The creation context.
   * @param string|null $entity_bundle
   *   The bundle, always NULL for this entity type.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer fact drift');
  }

}
