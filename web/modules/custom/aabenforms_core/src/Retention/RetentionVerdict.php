<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Retention;

/**
 * Whether a case or submission may be purged now, and why not.
 *
 * The verdict ignores retention periods: it answers the legal question "may
 * this data be deleted at all" (closed, journalised, not held, or never a
 * case). Whether a purge is DUE is the RetentionPolicy's separate question.
 */
final class RetentionVerdict {

  /**
   * The target may be purged.
   */
  public const PURGEABLE = 'purgeable';

  /**
   * The target does not exist.
   */
  public const NOT_FOUND = 'not_found';

  /**
   * The case is not closed (lukket).
   */
  public const OPEN = 'open';

  /**
   * The case is closed but carries no journal or ESDH reference.
   */
  public const UNJOURNALISED = 'unjournalised';

  /**
   * The case or one of its submissions is under legal hold.
   */
  public const LEGAL_HOLD = 'legal_hold';

  /**
   * An unlinked submission still awaits a decision.
   *
   * A caseworker, consent or parent status is pending, so the authority must
   * still act on it.
   */
  public const PENDING_DECISION = 'pending_decision';

  /**
   * Constructs a verdict.
   *
   * @param string $reason
   *   One of the class constants.
   * @param int|null $caseId
   *   The case the target resolves to, or NULL for an unlinked submission.
   * @param int[] $submissionIds
   *   Every submission that would be purged with the target.
   * @param string|null $webformId
   *   The webform of the (first) submission, for policy lookups.
   * @param int|null $closedAt
   *   When the case was closed, if it is.
   */
  public function __construct(
    public readonly string $reason,
    public readonly ?int $caseId = NULL,
    public readonly array $submissionIds = [],
    public readonly ?string $webformId = NULL,
    public readonly ?int $closedAt = NULL,
  ) {}

  /**
   * Whether the target may be purged.
   */
  public function isPurgeable(): bool {
    return $this->reason === self::PURGEABLE;
  }

}
