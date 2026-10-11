<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_case\Unit\Service;

use Drupal\aabenforms_case\Service\FristClock;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aabenforms_case\Service\FristClock
 *
 * @group aabenforms_case
 */
class FristClockTest extends UnitTestCase {

  /**
   * Monday 2024-01-01 09:00:00 UTC.
   */
  protected const MONDAY_0900 = 1704099600;

  /**
   * Builds a FristClock with the given per-area config.
   *
   * @param array<string, array{unit:string, amount:int}> $frister
   *   The per-area deadline config.
   * @param string[] $extraClosingDays
   *   Kommune-specific extra closing days as "m-d" strings.
   */
  protected function clock(array $frister, array $extraClosingDays = []): FristClock {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['frister', $frister],
      ['ekstra_lukkedage', $extraClosingDays],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('aabenforms_case.settings')->willReturn($config);

    return new FristClock($factory);
  }

  /**
   * Formats a due timestamp as a Copenhagen "Y-m-d H:i:s" string.
   */
  protected function due(FristClock $clock, string $receiptCph, string $area): string {
    $receipt = new \DateTimeImmutable($receiptCph, new \DateTimeZone('Europe/Copenhagen'));
    $ts = $clock->computeDue($receipt->getTimestamp(), $area);
    return (new \DateTimeImmutable('@' . $ts))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format('Y-m-d H:i:s');
  }

  /**
   * @covers ::computeDue
   */
  public function testComputeDueHours(): void {
    $clock = $this->clock(['underretning' => ['unit' => 'hours', 'amount' => 24]]);
    $this->assertSame(
      self::MONDAY_0900 + (24 * 3600),
      $clock->computeDue(self::MONDAY_0900, 'underretning')
    );
  }

  /**
   * Working days skip weekends and land at end-of-day (Copenhagen).
   *
   * @covers ::computeDue
   * @covers ::addWorkingDays
   */
  public function testWorkingDaysSkipWeekendEndOfDay(): void {
    $clock = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 5]]);
    // Mon 2025-03-03 10:00 + 5 hverdage: Tue/Wed/Thu/Fri, skip Sat/Sun, Mon.
    $this->assertSame('2025-03-10 23:59:59', $this->due($clock, '2025-03-03 10:00:00', 'x'));
  }

  /**
   * Danish public holidays around Easter are not counted as working days.
   *
   * @covers ::addWorkingDays
   */
  public function testEasterHolidaysSkipped(): void {
    $clock = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 3]]);
    // Easter 2025-04-20. Receipt Wed 04-16; skærtorsdag 04-17, langfredag
    // 04-18, weekend, 2. påskedag 04-21 are all skipped, so 3 hverdage lands
    // Thu 04-24 (eight calendar days later).
    $this->assertSame('2025-04-24 23:59:59', $this->due($clock, '2025-04-16 09:00:00', 'x'));
  }

  /**
   * Christmas holidays (25-26 Dec) are skipped.
   *
   * @covers ::addWorkingDays
   */
  public function testChristmasHolidaysSkipped(): void {
    $clock = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 2]]);
    // Receipt Wed 2025-12-24; juledag 12-25 + 2. juledag 12-26 + weekend
    // skipped, so 2 hverdage lands Tue 2025-12-30.
    $this->assertSame('2025-12-30 23:59:59', $this->due($clock, '2025-12-24 09:00:00', 'x'));
  }

  /**
   * Store bededag is NOT a holiday (abolished 2024): it counts as a working day.
   *
   * @covers ::addWorkingDays
   */
  public function testStoreBededagCountsAsWorkingDay(): void {
    $clock = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 1]]);
    // 4th Friday after Easter 2025 = 2025-05-16 (former store bededag).
    // Receipt Thu 2025-05-15 + 1 hverdag must land on that Friday, not skip it.
    $this->assertSame('2025-05-16 23:59:59', $this->due($clock, '2025-05-15 09:00:00', 'x'));
  }

  /**
   * Kommune-specific extra closing days (e.g. grundlovsdag) are honoured.
   *
   * @covers ::isHoliday
   */
  public function testExtraClosingDaysSkipped(): void {
    // Grundlovsdag 2025-06-05 is a Thursday, not a statutory helligdag.
    $withExtra = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 1]], ['06-05']);
    $this->assertSame('2025-06-06 23:59:59', $this->due($withExtra, '2025-06-04 09:00:00', 'x'));

    $withoutExtra = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 1]]);
    $this->assertSame('2025-06-05 23:59:59', $this->due($withoutExtra, '2025-06-04 09:00:00', 'x'));
  }

  /**
   * Weekday is computed in Copenhagen time, not UTC.
   *
   * @covers ::addWorkingDays
   */
  public function testWeekdayComputedInCopenhagenTimezone(): void {
    $clock = $this->clock(['x' => ['unit' => 'hverdage', 'amount' => 1]]);
    // 2025-01-05 23:30 UTC is Monday 2025-01-06 00:30 CET. In UTC the weekday
    // would be Sunday; in Copenhagen it is Monday, so 1 hverdag lands Tue 01-07.
    $receipt = new \DateTimeImmutable('2025-01-05 23:30:00', new \DateTimeZone('UTC'));
    $ts = $clock->computeDue($receipt->getTimestamp(), 'x');
    $due = (new \DateTimeImmutable('@' . $ts))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format('Y-m-d');
    $this->assertSame('2025-01-07', $due);
  }

  /**
   * @covers ::computeDue
   */
  public function testComputeDueStraksIsImmediate(): void {
    $clock = $this->clock(['friplads' => ['unit' => 'straks', 'amount' => 0]]);
    $this->assertSame(self::MONDAY_0900, $clock->computeDue(self::MONDAY_0900, 'friplads'));
  }

  /**
   * @covers ::computeDue
   */
  public function testUnknownAreaFallsBackToDefault(): void {
    $clock = $this->clock(['default' => ['unit' => 'hours', 'amount' => 1]]);
    $this->assertSame(
      self::MONDAY_0900 + 3600,
      $clock->computeDue(self::MONDAY_0900, 'no_such_area')
    );
  }

  /**
   * @covers ::computeState
   * @dataProvider stateProvider
   */
  public function testComputeState(int $due, int $now, string $expected): void {
    $clock = $this->clock([]);
    $this->assertSame($expected, $clock->computeState($due, $now));
  }

  /**
   * Data provider for ::testComputeState.
   *
   * @return array<string, array{0:int,1:int,2:string}>
   *   Cases: [due, now, expected].
   */
  public static function stateProvider(): array {
    $now = self::MONDAY_0900;
    return [
      'overdue' => [$now - 1, $now, 'roed'],
      'due now' => [$now, $now, 'roed'],
      'within 24h' => [$now + 3600, $now, 'gul'],
      'exactly 24h' => [$now + 86400, $now, 'gul'],
      'more than 24h' => [$now + 86401, $now, 'groen'],
    ];
  }

  /**
   * The klagefrist is calendar weeks from notification, default four.
   *
   * @covers ::computeKlagefrist
   */
  public function testKlagefristIsCalendarWeeksFromNotification(): void {
    $clock = $this->clock([]);
    $this->assertSame(self::MONDAY_0900 + (4 * 7 * 86400), $clock->computeKlagefrist(self::MONDAY_0900));
    $this->assertSame(self::MONDAY_0900 + (2 * 7 * 86400), $clock->computeKlagefrist(self::MONDAY_0900, 2));
    // Never shorter than one week, whatever a flow is configured with.
    $this->assertSame(self::MONDAY_0900 + (7 * 86400), $clock->computeKlagefrist(self::MONDAY_0900, 0));
  }

}
