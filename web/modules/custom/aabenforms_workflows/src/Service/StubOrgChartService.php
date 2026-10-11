<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

/**
 * No-op org-chart implementation kept for tests and as a documented base.
 *
 * Production uses ConfigOrgChartService. This returns empty results rather
 * than echoing a self-asserted fallback. A site wiring LDAP / AD provides
 * its own implementation of OrgChartServiceInterface.
 */
final class StubOrgChartService implements OrgChartServiceInterface {

  /**
   * {@inheritdoc}
   */
  public function findManagerEmail(string $employee_id, string $fallback = ''): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function tierLimitCents(string $employee_id): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function employeeIdForAccountName(string $account_name): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function institutionNumbers(string $employee_id): array {
    return [];
  }

}
