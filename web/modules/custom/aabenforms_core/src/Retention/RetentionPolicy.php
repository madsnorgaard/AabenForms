<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Retention;

/**
 * The retention policy maths, free of Drupal services so it is unit-testable.
 *
 * Built from the aabenforms_core.retention config. All periods are whole days;
 * a value below 1 means "never purge" for that scope, so a misconfigured or
 * missing value fails closed (keeps data) rather than purging immediately.
 */
final class RetentionPolicy {

  /**
   * Seconds in a day.
   */
  public const DAY = 86400;

  /**
   * Per-webform overrides, keyed by webform id.
   *
   * @var array<string, array{days_after_close: int|null, unlinked_submission_days: int|null}>
   */
  private array $overrides = [];

  /**
   * Constructs a policy.
   *
   * @param bool $enabled
   *   Whether scheduled purging is enabled.
   * @param int $defaultDaysAfterClose
   *   Days after case closure before purge.
   * @param int $unlinkedSubmissionDays
   *   Days after the last change before an unlinked submission is purged.
   * @param int $digitalPostLogDays
   *   Days after sending before a Digital Post log row is redacted.
   * @param int $batchSize
   *   Maximum purge units per run.
   * @param array<int, array<string, mixed>> $webforms
   *   Overrides: list of {id, days_after_close, unlinked_submission_days}.
   * @param bool $requireEsdhRef
   *   TRUE: a case counts as journalised only with an ESDH reference (the
   *   system of record). FALSE also accepts the SF1470 index reference.
   */
  public function __construct(
    public readonly bool $enabled,
    public readonly int $defaultDaysAfterClose,
    public readonly int $unlinkedSubmissionDays,
    public readonly int $digitalPostLogDays,
    public readonly int $batchSize,
    array $webforms = [],
    public readonly bool $requireEsdhRef = TRUE,
  ) {
    foreach ($webforms as $row) {
      $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
      if ($id === '') {
        continue;
      }
      $this->overrides[$id] = [
        'days_after_close' => self::intOrNull($row['days_after_close'] ?? NULL),
        'unlinked_submission_days' => self::intOrNull($row['unlinked_submission_days'] ?? NULL),
      ];
    }
  }

  /**
   * Builds a policy from the raw config array.
   *
   * @param array<string, mixed> $config
   *   The aabenforms_core.retention data. Missing keys fail closed.
   */
  public static function fromConfig(array $config): self {
    return new self(
      (bool) ($config['enabled'] ?? FALSE),
      (int) ($config['default_days_after_close'] ?? 0),
      (int) ($config['unlinked_submission_days'] ?? 0),
      (int) ($config['digital_post_log_days'] ?? 0),
      max(1, (int) ($config['batch_size'] ?? 50)),
      is_array($config['webforms'] ?? NULL) ? $config['webforms'] : [],
      // Fail closed: only an explicit FALSE accepts the SF1470 index alone.
      ($config['require_esdh_ref'] ?? TRUE) !== FALSE,
    );
  }

  /**
   * Days after closure that apply to a case opened from the given webform.
   *
   * @param string|null $webformId
   *   The webform of the case's submission, or NULL when unknown.
   */
  public function daysAfterClose(?string $webformId): int {
    if ($webformId !== NULL && isset($this->overrides[$webformId]) && $this->overrides[$webformId]['days_after_close'] !== NULL) {
      return $this->overrides[$webformId]['days_after_close'];
    }
    return $this->defaultDaysAfterClose;
  }

  /**
   * Days before an unlinked submission of the given webform is purged.
   */
  public function unlinkedDays(string $webformId): int {
    if (isset($this->overrides[$webformId]) && $this->overrides[$webformId]['unlinked_submission_days'] !== NULL) {
      return $this->overrides[$webformId]['unlinked_submission_days'];
    }
    return $this->unlinkedSubmissionDays;
  }

  /**
   * Whether a case closed at $closedAt is due for purge at $now.
   *
   * @param int|null $closedAt
   *   When the case was closed. NULL (unknown) is never due.
   * @param string|null $webformId
   *   The webform of the case's submission.
   * @param int $now
   *   The current Unix time.
   */
  public function isCaseDue(?int $closedAt, ?string $webformId, int $now): bool {
    return self::isDue($closedAt, $this->daysAfterClose($webformId), $now);
  }

  /**
   * Whether an unlinked submission last changed at $changed is due at $now.
   */
  public function isUnlinkedSubmissionDue(?int $changed, string $webformId, int $now): bool {
    return self::isDue($changed, $this->unlinkedDays($webformId), $now);
  }

  /**
   * The newest closure time that can possibly be due, across all webforms.
   *
   * Used to pre-filter candidates in SQL; returns NULL when no scope purges.
   */
  public function earliestCaseCutoff(int $now): ?int {
    return self::cutoff($now, [$this->defaultDaysAfterClose, ...array_column($this->overrides, 'days_after_close')]);
  }

  /**
   * The newest change time an unlinked submission can have and still be due.
   */
  public function earliestUnlinkedCutoff(int $now): ?int {
    $periods = array_column($this->overrides, 'unlinked_submission_days');
    return self::cutoff($now, [$this->unlinkedSubmissionDays, ...$periods]);
  }

  /**
   * The send time before which a Digital Post log row is redacted.
   */
  public function digitalPostCutoff(int $now): ?int {
    return $this->digitalPostLogDays < 1 ? NULL : $now - ($this->digitalPostLogDays * self::DAY);
  }

  /**
   * Shared due check: a period below 1 day or an unknown start is never due.
   *
   * @param int|null $start
   *   When the period started.
   * @param int $days
   *   The period in days.
   * @param int $now
   *   The current Unix time.
   */
  private static function isDue(?int $start, int $days, int $now): bool {
    if ($start === NULL || $start <= 0 || $days < 1) {
      return FALSE;
    }
    return $start + ($days * self::DAY) <= $now;
  }

  /**
   * The cutoff for the shortest enabled period, or NULL when none is enabled.
   *
   * @param int $now
   *   The current Unix time.
   * @param array<int, int|null> $periods
   *   Candidate periods in days.
   */
  private static function cutoff(int $now, array $periods): ?int {
    $enabled = array_filter($periods, static fn ($d): bool => $d !== NULL && $d >= 1);
    return $enabled === [] ? NULL : $now - (min($enabled) * self::DAY);
  }

  /**
   * Normalises an optional integer config value.
   */
  private static function intOrNull(mixed $value): ?int {
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

}
