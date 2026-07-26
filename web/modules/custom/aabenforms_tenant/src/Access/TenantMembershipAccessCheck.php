<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Access;

use Drupal\aabenforms_core\Service\TenantResolver;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Blocks a tenant-bound user from case admin pages on a foreign tenant host.
 *
 * Defense-in-depth on top of the data-layer isolation (entity access + query
 * alter): a caseworker bound to their kommune who lands on another tenant's
 * host gets a proper "Access denied" page instead of an empty listing. Unbound
 * users and `bypass tenant isolation` operators are unaffected.
 */
final class TenantMembershipAccessCheck implements AccessInterface {

  public function __construct(
    private readonly TenantResolver $tenantResolver,
  ) {
  }

  /**
   * Checks whether the account may act within the active tenant.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Forbidden when the bound user is on a tenant outside their set.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('bypass tenant isolation')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    $bound = _aabenforms_tenant_user_domains($account);
    if ($bound === NULL) {
      // Unbound user: host-based behavior, no route-level restriction.
      return AccessResult::allowed()->addCacheContexts(['user']);
    }
    $current = $this->tenantResolver->getCurrentTenantId();
    if ($current !== NULL && !in_array($current, $bound, TRUE)) {
      return AccessResult::forbidden('User is not a member of the active tenant.')
        ->addCacheContexts(['user', 'url.site']);
    }
    return AccessResult::allowed()->addCacheContexts(['user', 'url.site']);
  }

}
