<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_beskedfordeler\Service;

use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconciles an asynchronous Beskedfordeler receipt to its send and case.
 *
 * A live SF1601 send returns `pending`: accepted, not delivered. The real
 * outcome arrives later as an SF1461/Beskedfordeler receipt carrying the send
 * transaction id. This handler correlates that receipt to the log row and to
 * the case (via the queryable digital_post_tx field) and records the outcome.
 *
 * Honours the "never act on a transient failure" contract: a transient failure
 * (timeout / 5xx at the carrier) leaves both the log and the case `pending` so
 * the carrier's retry can still succeed; only a permanent outcome
 * (delivered / permanent failure) finalises the state with an audited case
 * revision. Delivery is orthogonal to the case lifecycle, so it writes the
 * delivery field, never an allowedTransitions() status move.
 */
class DigitalPostReceiptHandler {

  public const OUTCOME_DELIVERED = 'delivered';
  public const OUTCOME_FAILED = 'failed';

  /**
   * The reconciled states written to the log/case.
   */
  public const STATE_DELIVERED = 'delivered';
  public const STATE_FAILED = 'failed';
  public const STATE_PENDING = 'pending';
  public const STATE_UNKNOWN = 'unknown';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly AuditLogger $auditLogger,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Reconciles a receipt for a transaction id.
   *
   * @param string $transactionId
   *   The send transaction id the receipt refers to.
   * @param string $outcome
   *   OUTCOME_DELIVERED or OUTCOME_FAILED.
   * @param bool $transient
   *   For a failure, TRUE when it is retry-able (the carrier may still deliver).
   * @param string $reason
   *   A short human-readable reason for the audit trail.
   *
   * @return string
   *   The reconciled state (delivered|failed|pending|unknown).
   */
  public function handle(string $transactionId, string $outcome, bool $transient = FALSE, string $reason = ''): string {
    if ($transactionId === '') {
      return self::STATE_UNKNOWN;
    }

    $state = $this->resolveState($outcome, $transient);
    $logMatched = $this->updateLogRow($transactionId, $state, $reason);
    $caseMatched = $this->updateCase($transactionId, $state, $reason);

    if (!$logMatched && !$caseMatched) {
      $this->logger->warning('Beskedfordeler receipt for unknown transaction @tx (ignored).', ['@tx' => $transactionId]);
      return self::STATE_UNKNOWN;
    }
    return $state;
  }

  /**
   * Maps an outcome + transient flag to the state to persist.
   */
  private function resolveState(string $outcome, bool $transient): string {
    if ($outcome === self::OUTCOME_DELIVERED) {
      return self::STATE_DELIVERED;
    }
    // A transient failure is NOT final - keep it pending for the carrier retry.
    return $transient ? self::STATE_PENDING : self::STATE_FAILED;
  }

  /**
   * Writes the receipt columns onto the matching log row.
   *
   * @return bool
   *   TRUE when a log row matched.
   */
  private function updateLogRow(string $transactionId, string $state, string $reason): bool {
    try {
      $fields = [
        'receipt_status' => $state,
        'receipt_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : NULL,
      ];
      if ($state === self::STATE_DELIVERED) {
        $fields['delivered_at'] = $this->time->getRequestTime();
      }
      $updated = $this->database->update('aabenforms_digital_post_log')
        ->fields($fields)
        ->condition('transaction_id', $transactionId)
        ->execute();
      return (int) $updated > 0;
    }
    catch (\Throwable $e) {
      $this->logger->error('Beskedfordeler log update failed for @tx: @msg', [
        '@tx' => $transactionId,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Writes the delivery outcome onto the correlated case (audited revision).
   *
   * A transient (still-pending) outcome makes no case change - the case stays
   * pending for the retry.
   *
   * @return bool
   *   TRUE when a case matched.
   */
  private function updateCase(string $transactionId, string $state, string $reason): bool {
    try {
      $storage = $this->entityTypeManager->getStorage('aabenforms_case');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('digital_post_tx', $transactionId)
        ->range(0, 1)
        ->execute();
      if ($ids === []) {
        return FALSE;
      }
      $case = $storage->load((int) reset($ids));
      if ($case === NULL) {
        return FALSE;
      }

      // Transient failure: leave the case pending (no finalisation, no revision).
      if ($state === self::STATE_PENDING) {
        return TRUE;
      }

      $case->set('digital_post_receipt_status', $state);
      if ($state === self::STATE_DELIVERED) {
        $case->set('digital_post_delivered_at', $this->time->getRequestTime());
      }
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage(sprintf('Digital Post: %s%s', $state, $reason !== '' ? ' (' . $reason . ')' : ''));
      $case->setRevisionCreationTime($this->time->getRequestTime());
      $case->save();

      $this->auditLogger->log(
        'digital_post_receipt',
        (string) $case->id(),
        $state,
        $state === self::STATE_DELIVERED ? 'success' : 'failure',
        ['transaction_id' => $transactionId],
      );
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Beskedfordeler case update failed for @tx: @msg', [
        '@tx' => $transactionId,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
