<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Computes statutory deadlines (frister) and their traffic-light state.
 *
 * Deadlines are driven by per-area configuration in aabenforms_case.settings
 * (key "frister"), so a new casework area only needs config, not code. Each
 * area defines a duration as either calendar hours or working days
 * (hverdage), the latter skipping weekends AND Danish public holidays per
 * RSL/forvaltningslov practice.
 *
 * Working-day math runs in Europe/Copenhagen (not UTC), so the weekday of a
 * receipt logged near local midnight is classified correctly, and the due
 * moment is normalised to end-of-day (23:59:59 local) on the final working
 * day - the citizen-favourable reading (the authority has the whole day).
 *
 * Danish public holidays subtracted: nytårsdag, skærtorsdag, langfredag,
 * 2. påskedag, Kristi himmelfartsdag, 2. pinsedag, juledag, 2. juledag.
 * Store bededag is deliberately NOT included: it was abolished as a public
 * holiday from 2024 (lov nr. 214 af 13/03/2023). Grundlovsdag and juleaften
 * are not statutory helligdage and are left out of the statutory clock; a
 * kommune that also closes those days can extend the set via the
 * "ekstra_lukkedage" config key (list of MM-DD strings).
 */
class FristClock {

  /**
   * The timezone all working-day math is evaluated in.
   */
  protected const TZ = 'Europe/Copenhagen';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Computes the deadline timestamp for a case area.
   *
   * @param int $receiptTs
   *   The receipt (modtagelse) Unix timestamp the clock starts from.
   * @param string $area
   *   The casework area key (e.g. "underretning"). Falls back to the
   *   "default" area when the key is not configured.
   *
   * @return int
   *   The due Unix timestamp.
   */
  public function computeDue(int $receiptTs, string $area): int {
    [$unit, $amount] = $this->resolveArea($area);

    return match ($unit) {
      'hours' => $receiptTs + ($amount * 3600),
      'hverdage' => $this->addWorkingDays($receiptTs, $amount),
      // "straks" (immediate) and any unknown unit: due is the receipt itself.
      default => $receiptTs,
    };
  }

  /**
   * Computes the appeal deadline (klagefrist) from the notification moment.
   *
   * The statutory klagefrist is four weeks in calendar time (RSL §67 stk. 1
   * for sociale sager), counted from when the citizen is notified of the
   * decision, not from the decision itself (FVL meddelelseskrav). It is
   * calendar weeks, not working days, so it does not go through the
   * per-area hverdage math above. Both SetKlagefristAction and the demo
   * case seeder use this so the two can never disagree.
   *
   * @param int $notifiedTs
   *   The Unix timestamp the decision letter was dispatched / notified.
   * @param int $weeks
   *   The deadline length in weeks (minimum 1, default 4).
   *
   * @return int
   *   The klagefrist Unix timestamp.
   */
  public function computeKlagefrist(int $notifiedTs, int $weeks = 4): int {
    $weeks = max(1, $weeks);
    return $notifiedTs + ($weeks * 7 * 86400);
  }

  /**
   * Default assumed postal delivery time for a physical letter, in days.
   */
  public const DEFAULT_PHYSICAL_POST_DELIVERY_DAYS = 5;

  /**
   * Returns the assumed moment the citizen received a decision letter.
   *
   * Digital Post (or an unrecorded channel): the send moment, exactly as
   * before. A physical letter (the citizen is fritaget for Digital Post) or
   * digital_and_physical (registration unknown, both sent): the send moment
   * plus aabenforms_case.settings:physical_post_delivery_days calendar days
   * (default 5). Using the later date for "both" is the citizen-favourable
   * reading: the authority cannot assume an exempt citizen saw the digital
   * copy. The delivery days are a conservative assumption, not law - the
   * kommune's jurist must confirm the value.
   *
   * @param int $sentTs
   *   The Unix timestamp the letter was dispatched.
   * @param string|null $channel
   *   digital, physical, digital_and_physical, or NULL/'' when unknown.
   *
   * @return int
   *   The assumed receipt Unix timestamp.
   */
  public function assumedReceipt(int $sentTs, ?string $channel): int {
    if ($channel !== 'physical' && $channel !== 'digital_and_physical') {
      return $sentTs;
    }
    return $sentTs + ($this->physicalPostDeliveryDays() * 86400);
  }

  /**
   * The configured physical post delivery days (non-negative, capped at 30).
   */
  public function physicalPostDeliveryDays(): int {
    $days = $this->configFactory->get('aabenforms_case.settings')->get('physical_post_delivery_days');
    if (!is_numeric($days)) {
      return self::DEFAULT_PHYSICAL_POST_DELIVERY_DAYS;
    }
    return max(0, min(30, (int) $days));
  }

  /**
   * Adds whole calendar weeks to a timestamp (no working-day math).
   *
   * Calendar arithmetic in Europe/Copenhagen, so a local midnight stays a
   * local midnight across a daylight-saving change.
   *
   * Used for periods counted in calendar weeks, e.g. the sygedagpenge
   * opfølgning horizon (the kommune vurderer the case within 8 weeks,
   * borger.dk "Sygedagpenge, hvis du er ledig").
   *
   * @param int $startTs
   *   The Unix timestamp the period starts from.
   * @param int $weeks
   *   The number of calendar weeks (negative values count as 0).
   *
   * @return int
   *   The Unix timestamp $weeks weeks later.
   */
  public function addCalendarWeeks(int $startTs, int $weeks): int {
    return (new \DateTimeImmutable('@' . $startTs))
      ->setTimezone(new \DateTimeZone(self::TZ))
      ->modify(sprintf('+%d weeks', max(0, $weeks)))
      ->getTimestamp();
  }

  /**
   * Returns the traffic-light state for a deadline.
   *
   * @param int $dueTs
   *   The due Unix timestamp.
   * @param int $nowTs
   *   The current Unix timestamp.
   *
   * @return string
   *   One of "groen", "gul", "roed".
   */
  public function computeState(int $dueTs, int $nowTs): string {
    if ($nowTs >= $dueTs) {
      return 'roed';
    }
    // Amber when the deadline is less than 24 hours away.
    if (($dueTs - $nowTs) <= 86400) {
      return 'gul';
    }
    return 'groen';
  }

  /**
   * Resolves the configured [unit, amount] for an area.
   *
   * @return array{0:string,1:int}
   *   The unit ("hours", "hverdage", "straks", ...) and integer amount.
   */
  protected function resolveArea(string $area): array {
    $frister = $this->configFactory->get('aabenforms_case.settings')->get('frister') ?? [];
    $config = $frister[$area] ?? $frister['default'] ?? ['unit' => 'hverdage', 'amount' => 28];
    $unit = (string) ($config['unit'] ?? 'hverdage');
    $amount = (int) ($config['amount'] ?? 0);
    return [$unit, $amount];
  }

  /**
   * Adds N working days to a timestamp, skipping weekends and Danish holidays.
   *
   * Evaluated in Europe/Copenhagen. The due moment is the end of the final
   * working day (23:59:59 local), so a "20 hverdage" frist expires at the
   * close of the 20th working day rather than at the receipt's time-of-day.
   */
  protected function addWorkingDays(int $receiptTs, int $days): int {
    if ($days <= 0) {
      return $receiptTs;
    }
    $tz = new \DateTimeZone(self::TZ);
    $date = (new \DateTimeImmutable('@' . $receiptTs))->setTimezone($tz);

    $added = 0;
    while ($added < $days) {
      $date = $date->modify('+1 day');
      if ($this->isWorkingDay($date)) {
        $added++;
      }
    }

    // Normalise to end-of-day local so "day" boundaries do not drift with the
    // receipt's time-of-day.
    return (int) $date->setTime(23, 59, 59)->getTimestamp();
  }

  /**
   * TRUE when the date is a Danish working day (Mon-Fri, not a helligdag).
   */
  protected function isWorkingDay(\DateTimeInterface $date): bool {
    // N: 1 (Mon) .. 7 (Sun).
    if ((int) $date->format('N') >= 6) {
      return FALSE;
    }
    return !$this->isHoliday($date);
  }

  /**
   * TRUE when the date is a Danish public holiday or configured lukkedag.
   */
  protected function isHoliday(\DateTimeInterface $date): bool {
    $year = (int) $date->format('Y');
    $md = $date->format('m-d');
    if (in_array($md, $this->holidayMonthDays($year), TRUE)) {
      return TRUE;
    }
    // Kommune-specific extra closing days (e.g. grundlovsdag, juleaften).
    $extra = $this->configFactory->get('aabenforms_case.settings')->get('ekstra_lukkedage') ?? [];
    return in_array($md, array_map('strval', $extra), TRUE);
  }

  /**
   * Returns the Danish public holidays for a year as MM-DD strings.
   *
   * @return string[]
   *   Holiday dates formatted as "m-d" in the Copenhagen calendar year.
   */
  protected function holidayMonthDays(int $year): array {
    $easter = $this->easterSunday($year);
    $days = [
      // Fixed helligdage.
      '01-01',
      '12-25',
      '12-26',
    ];
    // Moveable feasts relative to Easter Sunday.
    foreach ([-3, -2, 1, 39, 50] as $offset) {
      // -3 skærtorsdag, -2 langfredag, +1 2. påskedag,
      // +39 Kristi himmelfart, +50 2. pinsedag.
      $days[] = $easter->modify(sprintf('%+d days', $offset))->format('m-d');
    }
    return $days;
  }

  /**
   * Computes Easter Sunday for a Gregorian year (Anonymous Gregorian algorithm).
   */
  protected function easterSunday(int $year): \DateTimeImmutable {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new \DateTimeImmutable(
      sprintf('%04d-%02d-%02d', $year, $month, $day),
      new \DateTimeZone(self::TZ)
    );
  }

}
