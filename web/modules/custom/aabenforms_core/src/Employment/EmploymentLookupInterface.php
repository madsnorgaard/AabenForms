<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Employment;

/**
 * Looks up a citizen's employment history and the employer's sickness report.
 *
 * Sygedagpenge from the kommune depend on the beskæftigelseskrav: for a
 * lønmodtager at least 240 hours in the last 6 completed calendar months
 * before the first day of absence, with at least 40 hours in each of at
 * least 5 of those months (sygedagpengeloven §32 stk. 1 nr. 1; borger.dk
 * "Sygedagpenge, hvis du er lønmodtager", read 2026-10-10). The authority
 * already holds this in eIndkomst (Skatteforvaltningen) and receives the
 * employer's sickness report through NemRefusion, so the citizen must not
 * be asked for it.
 *
 * No eIndkomst or NemRefusion integration is established, so the only
 * implementation is DemoEmploymentLookup; consumers depend on this interface
 * so a real client is a service swap. Only what the case needs is returned
 * (data minimisation): employer, hours per month, monthly pay, and the
 * sickness report - nothing about other household members.
 *
 * The CPR is plaintext (10 digits). Implementations must never log or
 * return it; callers own auditing of every lookup.
 */
interface EmploymentLookupInterface {

  /**
   * Hours required in the reference period (sygedagpengeloven §32).
   */
  public const REQUIRED_HOURS = 240;

  /**
   * Months in the reference period.
   */
  public const REFERENCE_MONTHS = 6;

  /**
   * Monthly hours required in at least MIN_QUALIFYING_MONTHS months.
   */
  public const MONTHLY_HOURS = 40;

  /**
   * Months that must reach MONTHLY_HOURS.
   */
  public const MIN_QUALIFYING_MONTHS = 5;

  /**
   * Returns the employment summary for a CPR, or NULL when none is known.
   *
   * @param string $cpr
   *   The citizen's CPR number.
   *
   * @return array|null
   *   Keys: employer_name (string), employer_cvr (string, 8 digits),
   *   months (list of {month: 'YYYY-MM', hours: int}, oldest first, the last
   *   REFERENCE_MONTHS completed months), monthly_pay (int, DKK gross),
   *   total_hours (int), requirement_met (bool).
   */
  public function employment(string $cpr): ?array;

  /**
   * Returns the employer's sickness report (NemRefusion), or NULL.
   *
   * @param string $cpr
   *   The citizen's CPR number.
   *
   * @return array|null
   *   Keys: employer_cvr (string), first_sick_day (Y-m-d),
   *   employer_period_end (Y-m-d, end of the 30 calendar-day
   *   arbejdsgiverperiode), reported_at (Y-m-d).
   */
  public function sicknessReport(string $cpr): ?array;

  /**
   * Whether answers come from demo data rather than the registers.
   *
   * Consumers MUST label outputs as demo when this is TRUE.
   *
   * @return bool
   *   TRUE when demo data is in use.
   */
  public function isDemoMode(): bool;

}
