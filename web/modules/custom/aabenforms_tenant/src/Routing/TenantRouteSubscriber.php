<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds the tenant-membership access requirement to the case admin routes.
 *
 * Pairs with TenantMembershipAccessCheck so a bound caseworker visiting the
 * case inbox / edit / delete pages on a foreign tenant host is hard-blocked
 * with an Access denied page.
 */
final class TenantRouteSubscriber extends RouteSubscriberBase {

  /**
   * The case admin routes that must enforce tenant membership.
   */
  private const GUARDED_ROUTES = [
    'entity.aabenforms_case.collection',
    'entity.aabenforms_case.edit_form',
    'entity.aabenforms_case.delete_form',
  ];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (self::GUARDED_ROUTES as $name) {
      $route = $collection->get($name);
      if ($route !== NULL) {
        $route->setRequirement('_aabenforms_tenant_member', 'TRUE');
      }
    }
  }

}
