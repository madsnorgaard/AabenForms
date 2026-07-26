<?php

declare(strict_types=1);

namespace Drupal\aabenforms_institution\Service;

use Drupal\aabenforms_workflows\Service\OrgChartServiceInterface;

/**
 * Institution-aware decoration of the org chart.
 *
 * The org-chart interface docblock anticipates decoration for real
 * directories; this decorator adds exactly one capability: employee ids of
 * the form "inst:<institutionsnummer>" resolve their manager through the
 * institution registry (school leader, escalating up the parent chain).
 * Every other id delegates unchanged to the decorated directory, so the
 * existing HR flows are unaffected.
 */
final class InstitutionOrgChartService implements OrgChartServiceInterface {

  /**
   * Prefix marking an institution-scoped employee identifier.
   */
  public const INSTITUTION_PREFIX = 'inst:';

  public function __construct(
    protected OrgChartServiceInterface $inner,
    protected InstitutionRegistry $registry,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function findManagerEmail(string $employee_id, string $fallback = ''): string {
    if (str_starts_with($employee_id, self::INSTITUTION_PREFIX)) {
      return $this->registry->leaderEmailFor(substr($employee_id, strlen(self::INSTITUTION_PREFIX)));
    }
    return $this->inner->findManagerEmail($employee_id, $fallback);
  }

  /**
   * {@inheritdoc}
   */
  public function tierLimitCents(string $employee_id): int {
    return $this->inner->tierLimitCents($employee_id);
  }

  /**
   * {@inheritdoc}
   */
  public function employeeIdForAccountName(string $account_name): string {
    return $this->inner->employeeIdForAccountName($account_name);
  }

}
