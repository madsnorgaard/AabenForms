<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Retention;

use Drupal\aabenforms_core\Privacy\PiiRedactor;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Core\Database\Connection;

/**
 * Right to erasure (GDPR art. 17) and legal holds, for the DPO.
 *
 * A public authority processes under art. 6(1)(c)/(e), and art. 17(3)(b)
 * exempts data it must keep to meet a legal obligation or perform a task in
 * the public interest; arkivloven and the journaliseringspligt are such
 * obligations. So an erasure request is REFUSED for an open case, a closed
 * but unjournalised case, and anything under legal hold. For a closed and
 * journalised case (the record lives in the ESDH) or a submission that never
 * became a case, the intake copy is purged now through the RetentionManager.
 * Every request is audited as erasure_requested with its outcome.
 */
class ErasureService {

  /**
   * Outcome: purged.
   */
  public const PURGED = 'purged';

  /**
   * Outcome: refused, data must be kept.
   */
  public const REFUSED = 'refused';

  /**
   * Outcome: the target does not exist.
   */
  public const NOT_FOUND = 'not_found';

  /**
   * Outcome: malformed target or missing reason.
   */
  public const INVALID = 'invalid';

  public function __construct(
    protected readonly RetentionManager $retentionManager,
    protected readonly LegalHoldStore $legalHolds,
    protected readonly AuditLogger $auditLogger,
    protected readonly PiiRedactor $redactor,
    protected readonly Connection $database,
  ) {}

  /**
   * Parses "case:<id>" or "submission:<sid>".
   *
   * A bare number is ambiguous (case and submission ids overlap) and is only
   * accepted when $bareType names what it means. Ids are capped at nine
   * digits so a CPR typed by mistake is rejected instead of being written to
   * the audit log as an id.
   *
   * @return array{0: string, 1: int}|null
   *   [type, id], or NULL when the target is malformed.
   */
  public static function parseTarget(string $target, ?string $bareType = NULL): ?array {
    $target = trim($target);
    if ($bareType !== NULL && preg_match('/^[1-9]\d{0,8}$/', $target)) {
      return [$bareType, (int) $target];
    }
    if (preg_match('/^(case|submission):([1-9]\d{0,8})$/', $target, $m)) {
      return [$m[1], (int) $m[2]];
    }
    return NULL;
  }

  /**
   * Handles an erasure request.
   *
   * @param string $target
   *   The target, "case:<id>" or "submission:<sid>".
   * @param string $reason
   *   The DPO's reason or reference. Required. CPR numbers are masked.
   *
   * @return array{outcome: string, refusal: string|null, case_id: int|null, submission_ids: int[], revisions: int, trace_rows: int, digital_post_rows: int}
   *   The outcome and, when purged, what was removed.
   */
  public function requestErasure(string $target, string $reason): array {
    $result = [
      'outcome' => self::INVALID,
      'refusal' => NULL,
      'case_id' => NULL,
      'submission_ids' => [],
      'revisions' => 0,
      'trace_rows' => 0,
      'digital_post_rows' => 0,
    ];
    $reason = $this->cleanReason($reason);
    $parsed = self::parseTarget($target);
    if ($parsed === NULL || $reason === '') {
      // The raw target is never stored: it may be a mistyped CPR.
      $this->auditOrFail('erasure_requested', 'invalid', self::INVALID, ['refusal' => $parsed === NULL ? 'malformed_target' : 'missing_reason']);
      return $result;
    }
    [$type, $id] = $parsed;
    $verdict = $type === LegalHoldStore::CASE
      ? $this->retentionManager->assessCase($id)
      : $this->retentionManager->assessSubmission($id);
    $result['case_id'] = $verdict->caseId;
    $result['submission_ids'] = $verdict->submissionIds;
    $context = [
      'target' => $type,
      'id' => $id,
      'case_id' => $verdict->caseId,
      'reason' => $reason,
    ];

    if ($verdict->reason === RetentionVerdict::NOT_FOUND) {
      $result['outcome'] = self::NOT_FOUND;
      $this->auditOrFail('erasure_requested', $type . ':' . $id, self::NOT_FOUND, $context);
      return $result;
    }
    if (!$verdict->isPurgeable()) {
      $result['outcome'] = self::REFUSED;
      $result['refusal'] = $verdict->reason;
      $this->auditOrFail('erasure_requested', $type . ':' . $id, self::REFUSED, $context + ['refusal' => $verdict->reason]);
      return $result;
    }

    // Purge and audit the request in one transaction: if the request cannot
    // be audited, nothing is deleted.
    $transaction = $this->database->startTransaction();
    try {
      $counts = $verdict->caseId !== NULL
        ? $this->retentionManager->purgeCase($verdict->caseId, RetentionManager::PURPOSE_ERASURE, 'erasure')
        : $this->retentionManager->purgeSubmission($id, RetentionManager::PURPOSE_ERASURE, 'erasure');
      $this->auditOrFail('erasure_requested', $type . ':' . $id, self::PURGED, $context + [
        'submission_ids' => $counts['submission_ids'],
        'revisions' => $counts['revisions'],
      ]);
    }
    catch (RetentionRefusedException $e) {
      $transaction->rollBack();
      $result['outcome'] = self::REFUSED;
      $result['refusal'] = $e->verdict->reason;
      $this->auditOrFail('erasure_requested', $type . ':' . $id, self::REFUSED, $context + ['refusal' => $e->verdict->reason]);
      return $result;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    return ['outcome' => self::PURGED, 'refusal' => NULL] + $counts;
  }

  /**
   * Places or releases a legal hold.
   *
   * @param string $target
   *   A case id, "case:<id>" or "submission:<sid>".
   * @param bool $on
   *   TRUE to place, FALSE to release.
   * @param string $reason
   *   Required. CPR numbers are masked.
   *
   * @return string
   *   'placed', 'released', 'not_held', 'not_found' or 'invalid'.
   */
  public function setLegalHold(string $target, bool $on, string $reason): string {
    $reason = $this->cleanReason($reason);
    $parsed = self::parseTarget($target, LegalHoldStore::CASE);
    if ($parsed === NULL || $reason === '') {
      $this->auditOrFail('legal_hold', 'invalid', self::INVALID, ['refusal' => $parsed === NULL ? 'malformed_target' : 'missing_reason']);
      return self::INVALID;
    }
    [$type, $id] = $parsed;
    $identifier = $type . ':' . $id;
    $context = ['target' => $type, 'id' => $id, 'reason' => $reason];

    if (!$on) {
      // Releasing is allowed even if the record is gone, so a stale hold can
      // always be cleared.
      $outcome = $this->legalHolds->release($type, $id) ? 'released' : 'not_held';
      $this->auditOrFail('legal_hold', $identifier, $outcome, $context);
      return $outcome;
    }

    $exists = $type === LegalHoldStore::CASE
      ? $this->retentionManager->assessCase($id)->reason !== RetentionVerdict::NOT_FOUND
      : $this->retentionManager->assessSubmission($id)->reason !== RetentionVerdict::NOT_FOUND;
    if (!$exists) {
      $this->auditOrFail('legal_hold', $identifier, self::NOT_FOUND, $context);
      return self::NOT_FOUND;
    }
    // Audit first: a hold that cannot be audited is not placed.
    $this->auditOrFail('legal_hold', $identifier, 'placed', $context);
    $this->legalHolds->place($type, $id, $reason);
    return 'placed';
  }

  /**
   * Masks CPR numbers and bounds the length of a free-text reason.
   */
  protected function cleanReason(string $reason): string {
    return mb_substr(trim($this->redactor->redactString($reason)), 0, 255);
  }

  /**
   * Writes an audit row or throws, so no unaudited change happens.
   *
   * @param string $action
   *   The audit action.
   * @param string $identifier
   *   The target identifier; the logger hashes it.
   * @param string $status
   *   The outcome.
   * @param array<string, mixed> $context
   *   Ids, outcome and the masked reason; never a CPR.
   */
  protected function auditOrFail(string $action, string $identifier, string $status, array $context): void {
    if ($this->auditLogger->log($action, $identifier, 'dpo_request', $status, $context) === NULL) {
      throw new \RuntimeException('The audit row could not be written; the request was not carried out.');
    }
  }

}
