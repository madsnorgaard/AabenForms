<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Retention;

use Drupal\aabenforms_core\Retention\ErasureService;
use Drupal\aabenforms_core\Retention\RetentionPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the retention policy maths and the erasure target syntax.
 */
#[Group('aabenforms_core')]
class RetentionPolicyTest extends UnitTestCase {

  /**
   * A fixed "now".
   */
  protected const NOW = 1_800_000_000;

  /**
   * The shipped defaults.
   */
  protected function defaults(array $overrides = []): RetentionPolicy {
    return RetentionPolicy::fromConfig($overrides + [
      'enabled' => TRUE,
      'default_days_after_close' => 90,
      'webforms' => [],
      'unlinked_submission_days' => 30,
      'digital_post_log_days' => 180,
      'batch_size' => 50,
    ]);
  }

  /**
   * A case is due exactly when the period has elapsed, not a second before.
   */
  public function testCaseDueBoundary(): void {
    $policy = $this->defaults();
    $closed = self::NOW - 90 * RetentionPolicy::DAY;
    $this->assertTrue($policy->isCaseDue($closed, 'intake', self::NOW));
    $this->assertFalse($policy->isCaseDue($closed + 1, 'intake', self::NOW));
    $this->assertFalse($policy->isCaseDue(NULL, 'intake', self::NOW), 'Unknown closure time is never due');
    $this->assertFalse($policy->isCaseDue(0, 'intake', self::NOW));
  }

  /**
   * Per-webform overrides win; a NULL override falls back to the default.
   */
  public function testWebformOverrides(): void {
    $policy = $this->defaults([
      'webforms' => [
      ['id' => 'byggesag', 'days_after_close' => 3650, 'unlinked_submission_days' => NULL],
      ['id' => 'kontakt', 'days_after_close' => NULL, 'unlinked_submission_days' => 7],
      ['id' => '', 'days_after_close' => 1],
      ],
    ]);
    $this->assertSame(3650, $policy->daysAfterClose('byggesag'));
    $this->assertSame(90, $policy->daysAfterClose('kontakt'));
    $this->assertSame(90, $policy->daysAfterClose(NULL));
    $this->assertSame(90, $policy->daysAfterClose('ukendt'));
    $this->assertSame(30, $policy->unlinkedDays('byggesag'));
    $this->assertSame(7, $policy->unlinkedDays('kontakt'));

    $closed = self::NOW - 100 * RetentionPolicy::DAY;
    $this->assertTrue($policy->isCaseDue($closed, 'kontakt', self::NOW));
    $this->assertFalse($policy->isCaseDue($closed, 'byggesag', self::NOW));
  }

  /**
   * A period below one day disables purging for that scope (fail closed).
   */
  #[DataProvider('disabledPeriods')]
  public function testDisabledPeriodsNeverPurge(int $days): void {
    $policy = $this->defaults([
      'default_days_after_close' => $days,
      'unlinked_submission_days' => $days,
      'digital_post_log_days' => $days,
    ]);
    $ancient = 1;
    $this->assertFalse($policy->isCaseDue($ancient, NULL, self::NOW));
    $this->assertFalse($policy->isUnlinkedSubmissionDue($ancient, 'intake', self::NOW));
    $this->assertNull($policy->earliestCaseCutoff(self::NOW));
    $this->assertNull($policy->earliestUnlinkedCutoff(self::NOW));
    $this->assertNull($policy->digitalPostCutoff(self::NOW));
  }

  /**
   * Periods that mean "never".
   */
  public static function disabledPeriods(): array {
    return [[0], [-1]];
  }

  /**
   * Missing config fails closed: disabled, nothing due.
   */
  public function testEmptyConfigFailsClosed(): void {
    $policy = RetentionPolicy::fromConfig([]);
    $this->assertFalse($policy->enabled);
    $this->assertFalse($policy->isCaseDue(1, NULL, self::NOW));
    $this->assertSame(50, $policy->batchSize);
    $this->assertSame(1, RetentionPolicy::fromConfig(['batch_size' => 0])->batchSize);
  }

  /**
   * The SQL pre-filter cutoff uses the shortest enabled period.
   */
  public function testEarliestCutoffs(): void {
    $policy = $this->defaults([
      'webforms' => [
      ['id' => 'a', 'days_after_close' => 30, 'unlinked_submission_days' => 0],
      ['id' => 'b', 'days_after_close' => 0, 'unlinked_submission_days' => 14],
      ],
    ]);
    $this->assertSame(self::NOW - 30 * RetentionPolicy::DAY, $policy->earliestCaseCutoff(self::NOW));
    $this->assertSame(self::NOW - 14 * RetentionPolicy::DAY, $policy->earliestUnlinkedCutoff(self::NOW));
    $this->assertSame(self::NOW - 180 * RetentionPolicy::DAY, $policy->digitalPostCutoff(self::NOW));
  }

  /**
   * Erasure targets must say what they are; a bare id only where defined.
   */
  public function testParseTarget(): void {
    $this->assertSame(['case', 42], ErasureService::parseTarget('case:42'));
    $this->assertSame(['submission', 7], ErasureService::parseTarget(' submission:7 '));
    $this->assertNull(ErasureService::parseTarget('42'), 'Bare ids are ambiguous');
    $this->assertSame(['case', 42], ErasureService::parseTarget('42', 'case'));
    $this->assertNull(ErasureService::parseTarget('case:0'));
    $this->assertNull(ErasureService::parseTarget('case:-1'));
    $this->assertNull(ErasureService::parseTarget('node:1'));
    $this->assertNull(ErasureService::parseTarget('2403482210x'));
    $this->assertNull(ErasureService::parseTarget('case:1 OR 1=1'));
    $this->assertNull(ErasureService::parseTarget('2403482210', 'case'), 'A CPR is never taken for an id');
    $this->assertNull(ErasureService::parseTarget('submission:2403482210'));
  }

}
