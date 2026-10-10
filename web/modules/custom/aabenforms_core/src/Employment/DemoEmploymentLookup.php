<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Employment;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Demo employment + sickness-report rail, keyed on the demo persona CPRs.
 *
 * Stands in for eIndkomst (employment and pay) and NemRefusion (the
 * employer's sickness report) until data agreements and integrations exist.
 * Always reports demo mode, so nothing built on it can be presented as live.
 * The employer is fictitious.
 *
 * Dates are relative to the request time so the demo stays current: the six
 * completed calendar months before the first sick day form the reference
 * period, and the first sick day is a few days ago.
 */
final class DemoEmploymentLookup implements EmploymentLookupInterface {

  /**
   * Demo employment keyed by CPR. Hours per month, oldest first.
   */
  private const EMPLOYMENT = [
    // Mikkel Jensen (DemoPersonas 'mikkel'): full-time, meets the requirement.
    '1502856234' => [
      'employer_name' => 'Østjysk Lager & Logistik ApS (demo)',
      'employer_cvr' => '12345678',
      'hours' => [160, 160, 152, 160, 160, 148],
      'monthly_pay' => 31500,
      // Days before today the employer reports as the first sick day.
      'sick_days_ago' => 5,
    ],
  ];

  /**
   * Length of the arbejdsgiverperiode in calendar days (borger.dk).
   */
  public const EMPLOYER_PERIOD_DAYS = 30;

  public function __construct(
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function employment(string $cpr): ?array {
    $row = $this->row($cpr);
    if ($row === NULL) {
      return NULL;
    }
    // Reference period: the last REFERENCE_MONTHS completed calendar months
    // before the first sick day.
    $firstOfSickMonth = $this->firstSickDay($row)->modify('first day of this month');
    $months = [];
    foreach (array_values($row['hours']) as $i => $hours) {
      $offset = self::REFERENCE_MONTHS - $i;
      $months[] = [
        'month' => $firstOfSickMonth->modify(sprintf('-%d months', $offset))->format('Y-m'),
        'hours' => (int) $hours,
      ];
    }
    $total = array_sum(array_column($months, 'hours'));
    $qualifying = count(array_filter($months, static fn (array $m): bool => $m['hours'] >= self::MONTHLY_HOURS));
    return [
      'employer_name' => $row['employer_name'],
      'employer_cvr' => $row['employer_cvr'],
      'months' => $months,
      'monthly_pay' => $row['monthly_pay'],
      'total_hours' => $total,
      'requirement_met' => $total >= self::REQUIRED_HOURS && $qualifying >= self::MIN_QUALIFYING_MONTHS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function sicknessReport(string $cpr): ?array {
    $row = $this->row($cpr);
    if ($row === NULL) {
      return NULL;
    }
    $first = $this->firstSickDay($row);
    return [
      'employer_cvr' => $row['employer_cvr'],
      'first_sick_day' => $first->format('Y-m-d'),
      // The employer pays for the first 30 calendar days (day 1 is day 1).
      'employer_period_end' => $first->modify(sprintf('+%d days', self::EMPLOYER_PERIOD_DAYS - 1))->format('Y-m-d'),
      'reported_at' => $first->modify('+1 day')->format('Y-m-d'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isDemoMode(): bool {
    return TRUE;
  }

  /**
   * The demo row for a CPR.
   */
  private function row(string $cpr): ?array {
    return self::EMPLOYMENT[preg_replace('/\D/', '', $cpr) ?? ''] ?? NULL;
  }

  /**
   * The first sick day, relative to the request time (Copenhagen date).
   */
  private function firstSickDay(array $row): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->setTime(0, 0)
      ->modify(sprintf('-%d days', (int) $row['sick_days_ago']));
  }

}
