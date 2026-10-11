<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

/**
 * Resolves an employee's manager and policy data from their identifier.
 *
 * The default implementation is ConfigOrgChartService, a directory backed
 * by aabenforms_workflows.org_chart config. A site that wires LDAP / Active
 * Directory / a real HR system decorates aabenforms_workflows.org_chart and
 * replaces these methods with real lookups.
 */
interface OrgChartServiceInterface {

  /**
   * Returns the manager's email for the given employee identifier.
   *
   * @param string $employee_id
   *   Implementation-specific identifier (CPR, employee number, email).
   * @param string $fallback
   *   Legacy graceful-degrade value. The config-backed implementation
   *   ignores it so a self-asserted webform "manager_email" field can no
   *   longer route an approval; pass '' from trusted callers.
   *
   * @return string
   *   The resolved manager email, or '' if the employee is unknown.
   */
  public function findManagerEmail(string $employee_id, string $fallback = ''): string;

  /**
   * Returns the per-claim policy limit (in cents) for an employee.
   *
   * @param string $employee_id
   *   The employee identifier.
   *
   * @return int
   *   The maximum allowed claim amount in cents, or the directory default.
   */
  public function tierLimitCents(string $employee_id): int;

  /**
   * Resolves the employee identifier bound to a Drupal account name.
   *
   * Used to bind a submission to the authenticated user rather than a
   * self-asserted form field.
   *
   * @param string $account_name
   *   The Drupal account (user) name.
   *
   * @return string
   *   The employee identifier, or '' if the account maps to no employee.
   */
  public function employeeIdForAccountName(string $account_name): string;

  /**
   * The institutions (institutionsnumre) an employee leads or acts for.
   *
   * Scopes institution-bound case work: a school leader decides only the
   * cases of their own school. Comes from the administered directory, never
   * from anything the employee can edit (such as their own e-mail).
   *
   * @param string $employee_id
   *   The employee identifier.
   *
   * @return string[]
   *   Institution numbers, or [] when the employee is bound to none.
   */
  public function institutionNumbers(string $employee_id): array;

}
