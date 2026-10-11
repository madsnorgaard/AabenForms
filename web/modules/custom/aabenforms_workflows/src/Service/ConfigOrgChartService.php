<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Directory-backed org-chart, sourced from aabenforms_workflows.org_chart.
 *
 * Resolves the manager email, the per-claim policy limit, and the
 * account-to-employee binding from config. Unlike the previous stub, it
 * never echoes a self-asserted fallback: an unknown employee resolves to
 * an empty manager email so a submitted "manager_email" field cannot route
 * an approval.
 */
final class ConfigOrgChartService implements OrgChartServiceInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a ConfigOrgChartService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the employee directory keyed by employee id.
   *
   * @return array<string, array<string, mixed>>
   *   The employees map from config.
   */
  protected function employees(): array {
    $employees = $this->configFactory->get('aabenforms_workflows.org_chart')->get('employees');
    return is_array($employees) ? $employees : [];
  }

  /**
   * {@inheritdoc}
   */
  public function findManagerEmail(string $employee_id, string $fallback = ''): string {
    $employee = $this->employees()[$employee_id] ?? NULL;
    if (is_array($employee) && !empty($employee['manager_email'])) {
      return (string) $employee['manager_email'];
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function tierLimitCents(string $employee_id): int {
    $employee = $this->employees()[$employee_id] ?? NULL;
    if (is_array($employee) && isset($employee['tier_limit_cents'])) {
      return (int) $employee['tier_limit_cents'];
    }
    $default = $this->configFactory->get('aabenforms_workflows.org_chart')->get('default_tier_limit_cents');
    return $default !== NULL ? (int) $default : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function institutionNumbers(string $employee_id): array {
    $numbers = $this->employees()[$employee_id]['institution_numbers'] ?? [];
    return is_array($numbers) ? array_values(array_map('strval', $numbers)) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function employeeIdForAccountName(string $account_name): string {
    if ($account_name === '') {
      return '';
    }
    foreach ($this->employees() as $employee_id => $employee) {
      if (is_array($employee) && ($employee['account_name'] ?? '') === $account_name) {
        return (string) $employee_id;
      }
    }
    return '';
  }

}
