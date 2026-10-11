<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Retention;

use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds intake copies whose purpose has ended and purges them (issue #91).
 *
 * AabenForms is not the system of record: the authority's record is the
 * journalised case in the ESDH / SF1470. What lives here is an intake copy
 * that must not outlive its purpose, and must not disappear before the record
 * exists elsewhere (arkivloven, journaliseringspligt). So a case is purged
 * only when it is closed (lukket) AND journalised (esdh_ref; journal_ref
 * only when require_esdh_ref is off)
 * AND not under legal hold. A submission that never became a case is purged
 * after the unlinked period. Everything else is kept, fail closed.
 *
 * A purge removes, in one transaction, after writing a retention_purged
 * audit row (no personal data in its context):
 * - the aabenforms_case entity with every revision,
 * - every submission linked to it (submission_ref on any revision, or a
 *   case_id element pointing at it, as the klage form does) and their data,
 * - the aabenforms_trace rows for those submissions and the case,
 * - the letter body, MeMo XML and recipient hash of the Digital Post log
 *   rows the case sent (the row itself stays: idempotency and receipts
 *   correlate on its transaction_id).
 * Separately, Digital Post log rows older than digital_post_log_days are
 * redacted the same way whatever case they belong to, since the log has no
 * case column and its MeMo XML carries the recipient CPR in clear.
 *
 * Kept: the audit log (its own retention), the MitID session and handoff
 * keyvalue (15-minute expiry), the payroll and election tables (not
 * submission-linked).
 */
class RetentionManager {

  /**
   * Audit purpose for scheduled purges.
   */
  public const PURPOSE_RETENTION = 'retention';

  /**
   * Audit purpose for a purge made on an erasure request.
   */
  public const PURPOSE_ERASURE = 'erasure';

  /**
   * The webform element that ties a non-intake submission to a case.
   */
  public const CASE_REF_ELEMENT = 'case_id';

  /**
   * Decision-state values that mean the authority has not finished.
   */
  public const PENDING_DECISION_VALUES = ['pending', 'afventer', 'awaiting', 'in_progress'];

  /**
   * What a redacted Digital Post log payload is replaced with.
   */
  public const REDACTED_PAYLOAD = '{"redacted":"retention"}';

  /**
   * The lock that keeps cron and Drush from purging concurrently.
   */
  protected const LOCK = 'aabenforms_core_retention';

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    protected readonly AuditLogger $auditLogger,
    protected readonly LegalHoldStore $legalHolds,
    protected readonly LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('aabenforms_retention');
  }

  /**
   * The policy from aabenforms_core.retention.
   */
  public function policy(): RetentionPolicy {
    return RetentionPolicy::fromConfig($this->configFactory->get('aabenforms_core.retention')->get() ?? []);
  }

  /**
   * Decides whether a case may be purged at all (periods aside).
   */
  public function assessCase(int $caseId): RetentionVerdict {
    if (!$this->casesSupported() || $caseId < 1) {
      return new RetentionVerdict(RetentionVerdict::NOT_FOUND);
    }
    $fields = ['id', 'status', 'journal_ref', 'changed', 'submission_ref'];
    if ($this->hasEsdhRef()) {
      $fields[] = 'esdh_ref';
    }
    $row = $this->database->select('aabenforms_case', 'c')
      ->fields('c', $fields)
      ->condition('c.id', $caseId)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return new RetentionVerdict(RetentionVerdict::NOT_FOUND);
    }

    $sids = $this->linkedSubmissionIds($caseId);
    $webformId = $this->webformOf($row['submission_ref'] !== NULL ? (int) $row['submission_ref'] : ($sids[0] ?? NULL));
    $make = fn (string $reason, ?int $closedAt = NULL): RetentionVerdict => new RetentionVerdict($reason, $caseId, $sids, $webformId, $closedAt);

    if ($row['status'] !== 'lukket') {
      return $make(RetentionVerdict::OPEN);
    }
    if (!$this->isJournalised($row)) {
      return $make(RetentionVerdict::UNJOURNALISED);
    }
    if ($this->legalHolds->isHeld(LegalHoldStore::CASE, $caseId) || $this->legalHolds->heldAmong(LegalHoldStore::SUBMISSION, $sids) !== []) {
      return $make(RetentionVerdict::LEGAL_HOLD);
    }
    $closedAt = $this->closedAt($caseId) ?? ((int) $row['changed'] ?: NULL);
    return $make(RetentionVerdict::PURGEABLE, $closedAt);
  }

  /**
   * Decides whether a submission may be purged; a linked one follows its case.
   */
  public function assessSubmission(int $sid): RetentionVerdict {
    if ($sid < 1 || !$this->submissionsSupported()) {
      return new RetentionVerdict(RetentionVerdict::NOT_FOUND);
    }
    $webformId = $this->webformOf($sid);
    if ($webformId === NULL) {
      return new RetentionVerdict(RetentionVerdict::NOT_FOUND);
    }
    $caseId = $this->caseIdForSubmission($sid);
    if ($caseId !== NULL) {
      return $this->assessCase($caseId);
    }
    if ($this->legalHolds->isHeld(LegalHoldStore::SUBMISSION, $sid)) {
      return new RetentionVerdict(RetentionVerdict::LEGAL_HOLD, NULL, [$sid], $webformId);
    }
    if ($this->submissionsPendingDecision([$sid]) !== []) {
      return new RetentionVerdict(RetentionVerdict::PENDING_DECISION, NULL, [$sid], $webformId);
    }
    return new RetentionVerdict(RetentionVerdict::PURGEABLE, NULL, [$sid], $webformId);
  }

  /**
   * Finds what is due under the policy.
   *
   * @param int|null $limit
   *   Maximum purge units to return (cases plus unlinked submissions), or
   *   NULL for all.
   *
   * @return array{cases: array<int, RetentionVerdict>, submissions: array<int, RetentionVerdict>, skipped: array<string, int>, more: bool}
   *   Due cases and unlinked submissions keyed by id, skip counts by reason,
   *   and whether more were due beyond the limit.
   */
  public function findDue(?int $limit = NULL): array {
    $policy = $this->policy();
    $now = $this->time->getRequestTime();
    $result = [
      'cases' => [],
      'submissions' => [],
      'skipped' => [
        RetentionVerdict::OPEN => 0,
        RetentionVerdict::UNJOURNALISED => 0,
        RetentionVerdict::LEGAL_HOLD => 0,
        RetentionVerdict::PENDING_DECISION => 0,
        'not_due' => 0,
      ],
      'more' => FALSE,
    ];
    $room = static function () use (&$result, $limit): bool {
      return $limit === NULL || (count($result['cases']) + count($result['submissions'])) < $limit;
    };

    if ($this->casesSupported()) {
      $result['skipped'][RetentionVerdict::OPEN] = (int) $this->database->select('aabenforms_case', 'c')
        ->condition('c.status', 'lukket', '<>')
        ->countQuery()->execute()->fetchField();

      foreach ($this->closedCaseCandidates() as $row) {
        if (!$this->isJournalised($row)) {
          $result['skipped'][RetentionVerdict::UNJOURNALISED]++;
          continue;
        }
        $closedAt = $row['closed_at'] !== NULL ? (int) $row['closed_at'] : ((int) $row['changed'] ?: NULL);
        if (!$policy->isCaseDue($closedAt, $row['webform_id'] ?? NULL, $now)) {
          $result['skipped']['not_due']++;
          continue;
        }
        if (!$room()) {
          $result['more'] = TRUE;
          break;
        }
        $verdict = $this->assessCase((int) $row['id']);
        // Re-check with the full verdict: the webform used for the period is
        // the one the verdict resolved, which may differ when submission_ref
        // is empty but a linked submission exists.
        if ($verdict->isPurgeable() && $policy->isCaseDue($verdict->closedAt, $verdict->webformId, $now)) {
          $result['cases'][(int) $row['id']] = $verdict;
        }
        elseif ($verdict->reason === RetentionVerdict::LEGAL_HOLD) {
          $result['skipped'][RetentionVerdict::LEGAL_HOLD]++;
        }
        else {
          $result['skipped']['not_due']++;
        }
      }
    }

    if (!$result['more'] && $this->submissionsSupported()) {
      $cutoff = $policy->earliestUnlinkedCutoff($now);
      if ($cutoff !== NULL) {
        $rows = array_filter(
          $this->unlinkedSubmissionCandidates($cutoff),
          fn (array $row): bool => $policy->isUnlinkedSubmissionDue((int) $row['changed'], (string) $row['webform_id'], $now),
        );
        $pending = array_flip($this->submissionsPendingDecision(array_map('intval', array_column($rows, 'sid'))));
        foreach ($rows as $row) {
          $sid = (int) $row['sid'];
          if ($this->legalHolds->isHeld(LegalHoldStore::SUBMISSION, $sid)) {
            $result['skipped'][RetentionVerdict::LEGAL_HOLD]++;
            continue;
          }
          if (isset($pending[$sid])) {
            $result['skipped'][RetentionVerdict::PENDING_DECISION]++;
            continue;
          }
          if (!$room()) {
            $result['more'] = TRUE;
            break;
          }
          $result['submissions'][$sid] = new RetentionVerdict(RetentionVerdict::PURGEABLE, NULL, [$sid], (string) $row['webform_id']);
        }
      }
    }

    return $result;
  }

  /**
   * Runs one retention pass.
   *
   * @param bool $dryRun
   *   Report what would be purged without changing anything.
   * @param int|null $limit
   *   Maximum purge units; NULL uses batch_size for a real run and "all" for
   *   a dry run.
   * @param string $trigger
   *   What started the run (cron, drush), recorded in the audit context.
   *
   * @return array<string, mixed>
   *   The report: dry_run, cases, submissions (purged with cases),
   *   unlinked_submissions, revisions, trace_rows, digital_post_redacted,
   *   digital_post_aged, skipped, more, failed, locked.
   */
  public function run(bool $dryRun = FALSE, ?int $limit = NULL, string $trigger = 'cron'): array {
    $policy = $this->policy();
    $limit ??= $dryRun ? NULL : $policy->batchSize;
    $report = [
      'dry_run' => $dryRun,
      'enabled' => $policy->enabled,
      'cases' => [],
      'submissions' => [],
      'unlinked_submissions' => [],
      'revisions' => 0,
      'trace_rows' => 0,
      'digital_post_redacted' => 0,
      'digital_post_aged' => 0,
      'skipped' => [],
      'more' => FALSE,
      'failed' => [],
      'locked' => FALSE,
    ];

    if (!$dryRun && !$this->lock->acquire(self::LOCK, 600.0)) {
      $report['locked'] = TRUE;
      return $report;
    }

    try {
      $due = $this->findDue($limit);
      $report['skipped'] = $due['skipped'];
      $report['more'] = $due['more'];

      foreach ($due['cases'] as $caseId => $verdict) {
        try {
          $counts = $dryRun ? $this->plan($verdict) : $this->purgeCase($caseId, self::PURPOSE_RETENTION, $trigger);
          $report['cases'][] = $caseId;
          array_push($report['submissions'], ...$counts['submission_ids']);
          $report['revisions'] += $counts['revisions'];
          $report['trace_rows'] += $counts['trace_rows'];
          $report['digital_post_redacted'] += $counts['digital_post_rows'];
        }
        catch (\Throwable $e) {
          $report['failed']['case:' . $caseId] = $this->failureReason($e);
          $this->logger->error('Retention purge of case @id failed: @class', [
            '@id' => $caseId,
            '@class' => get_class($e),
          ]);
        }
      }

      foreach ($due['submissions'] as $sid => $verdict) {
        try {
          $counts = $dryRun ? $this->plan($verdict) : $this->purgeSubmission($sid, self::PURPOSE_RETENTION, $trigger);
          $report['unlinked_submissions'][] = $sid;
          $report['trace_rows'] += $counts['trace_rows'];
        }
        catch (\Throwable $e) {
          $report['failed']['submission:' . $sid] = $this->failureReason($e);
          $this->logger->error('Retention purge of submission @id failed: @class', [
            '@id' => $sid,
            '@class' => get_class($e),
          ]);
        }
      }

      $report['digital_post_aged'] = $this->redactAgedDigitalPost($policy, $dryRun, $limit === NULL ? NULL : $limit * 20, $trigger);
    }
    finally {
      if (!$dryRun) {
        $this->lock->release(self::LOCK);
      }
    }

    return $report;
  }

  /**
   * Purges a case with its revisions, submissions, trace and letter bodies.
   *
   * Re-assesses inside the transaction, so a case reopened, held or never
   * journalised is refused even if it looked due a moment ago.
   *
   * @return array{case_id: int|null, submission_ids: int[], revisions: int, trace_rows: int, digital_post_rows: int}
   *   What was purged.
   *
   * @throws \Drupal\aabenforms_core\Retention\RetentionRefusedException
   *   When the case must be kept.
   */
  public function purgeCase(int $caseId, string $purpose = self::PURPOSE_RETENTION, string $trigger = 'cron'): array {
    $transaction = $this->database->startTransaction();
    try {
      $verdict = $this->assessCase($caseId);
      if (!$verdict->isPurgeable() || $verdict->caseId !== $caseId) {
        throw new RetentionRefusedException($verdict);
      }
      $plan = $this->plan($verdict);
      $this->audit('case:' . $caseId, $purpose, [
        'target' => 'case',
        'case_id' => $caseId,
        'webform_id' => $verdict->webformId,
        'submission_ids' => $plan['submission_ids'],
        'revisions' => $plan['revisions'],
        'trace_rows' => $plan['trace_rows'],
        'digital_post_rows' => $plan['digital_post_rows'],
        'trigger' => $trigger,
      ]);

      $plan['trace_rows'] = $this->deleteTraceRows($plan['submission_ids'], $caseId);
      $plan['digital_post_rows'] = $this->redactDigitalPostRows($this->caseDigitalPostTransactions($caseId));

      $caseStorage = $this->entityTypeManager->getStorage('aabenforms_case');
      $case = $caseStorage->load($caseId);
      if ($case !== NULL) {
        $caseStorage->delete([$case]);
      }
      $this->deleteSubmissions($plan['submission_ids']);
      return $plan;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Purges a submission that never became a case.
   *
   * @return array{case_id: int|null, submission_ids: int[], revisions: int, trace_rows: int, digital_post_rows: int}
   *   What was purged.
   *
   * @throws \Drupal\aabenforms_core\Retention\RetentionRefusedException
   *   When the submission is linked to a case or held.
   */
  public function purgeSubmission(int $sid, string $purpose = self::PURPOSE_RETENTION, string $trigger = 'cron'): array {
    $transaction = $this->database->startTransaction();
    try {
      $verdict = $this->assessSubmission($sid);
      if (!$verdict->isPurgeable() || $verdict->caseId !== NULL) {
        // A linked submission is purged only together with its case.
        throw new RetentionRefusedException($verdict);
      }
      $plan = $this->plan($verdict);
      $this->audit('submission:' . $sid, $purpose, [
        'target' => 'submission',
        'submission_id' => $sid,
        'webform_id' => $verdict->webformId,
        'trace_rows' => $plan['trace_rows'],
        'trigger' => $trigger,
      ]);
      $plan['trace_rows'] = $this->deleteTraceRows([$sid], NULL);
      $this->deleteSubmissions([$sid]);
      return $plan;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Counts what a purge of the verdict's target would remove, read-only.
   *
   * @return array{case_id: int|null, submission_ids: int[], revisions: int, trace_rows: int, digital_post_rows: int}
   *   The plan.
   */
  public function plan(RetentionVerdict $verdict): array {
    $caseId = $verdict->caseId;
    $sids = $verdict->submissionIds;
    $revisions = 0;
    $dpRows = 0;
    if ($caseId !== NULL) {
      $revisions = (int) $this->database->select('aabenforms_case_revision', 'r')
        ->condition('r.id', $caseId)
        ->countQuery()->execute()->fetchField();
      $tx = $this->caseDigitalPostTransactions($caseId);
      if ($tx !== [] && $this->database->schema()->tableExists('aabenforms_digital_post_log')) {
        $dpRows = (int) $this->database->select('aabenforms_digital_post_log', 'l')
          ->condition('l.transaction_id', $tx, 'IN')
          ->condition('l.payload', self::REDACTED_PAYLOAD, '<>')
          ->countQuery()->execute()->fetchField();
      }
    }
    $traceRows = 0;
    if ($this->database->schema()->tableExists('aabenforms_trace') && ($sids !== [] || $caseId !== NULL)) {
      $query = $this->database->select('aabenforms_trace', 't');
      $or = $query->orConditionGroup();
      if ($sids !== []) {
        $or->condition('t.sid', $sids, 'IN');
      }
      if ($caseId !== NULL) {
        $or->condition('t.case_id', $caseId);
      }
      $traceRows = (int) $query->condition($or)->countQuery()->execute()->fetchField();
    }
    return [
      'case_id' => $caseId,
      'submission_ids' => $sids,
      'revisions' => $revisions,
      'trace_rows' => $traceRows,
      'digital_post_rows' => $dpRows,
    ];
  }

  /**
   * Redacts Digital Post log rows older than the policy's period.
   *
   * @return int
   *   Rows redacted (or that would be, on a dry run).
   */
  protected function redactAgedDigitalPost(RetentionPolicy $policy, bool $dryRun, ?int $limit, string $trigger): int {
    $cutoff = $policy->digitalPostCutoff($this->time->getRequestTime());
    if ($cutoff === NULL || !$this->database->schema()->tableExists('aabenforms_digital_post_log')) {
      return 0;
    }
    $query = $this->database->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['id'])
      ->condition('l.created', $cutoff, '<')
      ->condition('l.payload', self::REDACTED_PAYLOAD, '<>')
      ->orderBy('l.id');
    if ($limit !== NULL) {
      $query->range(0, $limit);
    }
    $ids = array_map('intval', $query->execute()->fetchCol());
    if ($ids === [] || $dryRun) {
      return count($ids);
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->audit('digital_post_log', self::PURPOSE_RETENTION, [
        'target' => 'digital_post_log',
        'rows' => count($ids),
        'first_id' => min($ids),
        'last_id' => max($ids),
        'trigger' => $trigger,
      ]);
      return $this->redactDigitalPost('id', $ids);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * The report reason for a failed purge, without exception detail.
   */
  protected function failureReason(\Throwable $e): string {
    return $e instanceof RetentionRefusedException ? $e->verdict->reason : 'error';
  }

  /**
   * Writes the retention_purged audit row, failing closed if it cannot.
   *
   * @param string $identifier
   *   The target, "case:<id>" or "submission:<sid>", or a table name; the
   *   logger hashes it.
   * @param string $purpose
   *   self::PURPOSE_RETENTION or self::PURPOSE_ERASURE.
   * @param array<string, mixed> $context
   *   Ids and counts only; never personal data.
   */
  protected function audit(string $identifier, string $purpose, array $context): void {
    if ($this->auditLogger->log('retention_purged', $identifier, $purpose, 'purged', $context) === NULL) {
      throw new \RuntimeException('The audit row could not be written; nothing was purged.');
    }
  }

  /**
   * Closed cases with their journal fields, webform and closure time.
   *
   * @return \Generator<array<string, mixed>>
   *   Rows ordered oldest closure first.
   */
  protected function closedCaseCandidates(): \Generator {
    $query = $this->database->select('aabenforms_case', 'c');
    $query->fields('c', ['id', 'journal_ref', 'changed']);
    if ($this->hasEsdhRef()) {
      $query->addField('c', 'esdh_ref');
    }
    if ($this->submissionsSupported()) {
      $query->leftJoin('webform_submission', 's', '[s].[sid] = [c].[submission_ref]');
      $query->addField('s', 'webform_id');
    }
    $query->addExpression('(SELECT MIN([r].[revision_created]) FROM {aabenforms_case_revision} [r] WHERE [r].[id] = [c].[id] AND [r].[status] = :lukket)', 'closed_at', [':lukket' => 'lukket']);
    $query->condition('c.status', 'lukket');
    $query->orderBy('c.id');
    foreach ($query->execute() as $row) {
      yield (array) $row;
    }
  }

  /**
   * Submissions referenced by no case, last changed at or before $cutoff.
   *
   * @return array<int, array<string, mixed>>
   *   Rows with sid, webform_id, changed; oldest first.
   */
  protected function unlinkedSubmissionCandidates(int $cutoff): array {
    $query = $this->database->select('webform_submission', 's')
      ->fields('s', ['sid', 'webform_id', 'changed'])
      ->condition('s.changed', $cutoff, '<=')
      ->orderBy('s.changed')
      ->orderBy('s.sid');
    if ($this->casesSupported()) {
      $linked = $this->database->select('aabenforms_case_revision', 'r')
        ->fields('r', ['submission_ref'])
        ->isNotNull('r.submission_ref');
      $query->condition('s.sid', $linked, 'NOT IN');
    }
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // A submission whose case_id element points at an existing case (the
    // klage form) is that case's data and waits for the case.
    $referencing = $this->casesSupported() ? $this->submissionsReferencingExistingCases(array_map('intval', array_column($rows, 'sid'))) : [];
    return array_values(array_filter($rows, static fn (array $row): bool => !isset($referencing[(int) $row['sid']])));
  }

  /**
   * Which submissions still carry a pending decision-state value.
   *
   * The decision-state elements are the ones aabenforms_core guards on
   * create: caseworker_status, consent_status and parent<N>_status. While
   * one of them is pending, the authority still has to act on the
   * submission, so it is kept whatever its age.
   *
   * @param int[] $sids
   *   Submission ids.
   *
   * @return int[]
   *   The ids with a pending decision.
   */
  protected function submissionsPendingDecision(array $sids): array {
    if ($sids === [] || !$this->submissionsSupported()) {
      return [];
    }
    $pending = [];
    foreach (array_chunk($sids, 500) as $chunk) {
      $query = $this->database->select('webform_submission_data', 'd')
        ->fields('d', ['sid', 'name'])
        ->condition('d.sid', $chunk, 'IN')
        ->condition('d.value', self::PENDING_DECISION_VALUES, 'IN');
      $names = $query->orConditionGroup()
        ->condition('d.name', ['caseworker_status', 'consent_status'], 'IN')
        ->condition('d.name', 'parent%_status', 'LIKE');
      foreach ($query->condition($names)->execute() as $row) {
        if (in_array($row->name, ['caseworker_status', 'consent_status'], TRUE) || preg_match('/^parent\d+_status$/', (string) $row->name)) {
          $pending[(int) $row->sid] = (int) $row->sid;
        }
      }
    }
    return array_values($pending);
  }

  /**
   * Every submission tied to a case, on any revision or by case_id element.
   *
   * @return int[]
   *   Submission ids, ascending.
   */
  protected function linkedSubmissionIds(int $caseId): array {
    $sids = $this->database->select('aabenforms_case_revision', 'r')
      ->fields('r', ['submission_ref'])
      ->condition('r.id', $caseId)
      ->isNotNull('r.submission_ref')
      ->distinct()
      ->execute()
      ->fetchCol();
    if ($this->submissionsSupported()) {
      $byElement = $this->database->select('webform_submission_data', 'd')
        ->fields('d', ['sid'])
        ->condition('d.name', self::CASE_REF_ELEMENT)
        ->condition('d.value', (string) $caseId)
        ->execute()
        ->fetchCol();
      $sids = array_merge($sids, $byElement);
    }
    $sids = array_values(array_unique(array_map('intval', $sids)));
    // Keep only submissions that still exist.
    if ($sids !== [] && $this->submissionsSupported()) {
      $sids = array_map('intval', $this->database->select('webform_submission', 's')
        ->fields('s', ['sid'])
        ->condition('s.sid', $sids, 'IN')
        ->execute()
        ->fetchCol());
    }
    sort($sids);
    return $sids;
  }

  /**
   * The case a submission belongs to, or NULL when it never became one.
   */
  public function caseIdForSubmission(int $sid): ?int {
    if (!$this->casesSupported()) {
      return NULL;
    }
    $caseId = $this->database->select('aabenforms_case_revision', 'r')
      ->fields('r', ['id'])
      ->condition('r.submission_ref', $sid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($caseId !== FALSE) {
      return (int) $caseId;
    }
    $referencing = $this->submissionsReferencingExistingCases([$sid]);
    return $referencing[$sid] ?? NULL;
  }

  /**
   * Maps submissions whose case_id element names an existing case.
   *
   * @param int[] $sids
   *   Candidate submission ids.
   *
   * @return array<int, int>
   *   Submission id => case id.
   */
  protected function submissionsReferencingExistingCases(array $sids): array {
    if ($sids === [] || !$this->submissionsSupported()) {
      return [];
    }
    $refs = [];
    foreach (array_chunk($sids, 500) as $chunk) {
      $rows = $this->database->select('webform_submission_data', 'd')
        ->fields('d', ['sid', 'value'])
        ->condition('d.name', self::CASE_REF_ELEMENT)
        ->condition('d.sid', $chunk, 'IN')
        ->execute();
      foreach ($rows as $row) {
        if (ctype_digit((string) $row->value)) {
          $refs[(int) $row->sid] = (int) $row->value;
        }
      }
    }
    if ($refs === []) {
      return [];
    }
    $existing = array_flip(array_map('intval', $this->database->select('aabenforms_case', 'c')
      ->fields('c', ['id'])
      ->condition('c.id', array_values(array_unique($refs)), 'IN')
      ->execute()
      ->fetchCol()));
    return array_filter($refs, static fn (int $caseId): bool => isset($existing[$caseId]));
  }

  /**
   * When the case first entered lukket, from its revisions.
   */
  protected function closedAt(int $caseId): ?int {
    $value = $this->database->select('aabenforms_case_revision', 'r')
      ->condition('r.id', $caseId)
      ->condition('r.status', 'lukket')
      ->orderBy('r.vid')
      ->fields('r', ['revision_created'])
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $value === FALSE || $value === NULL ? NULL : (int) $value;
  }

  /**
   * Whether the record of authority exists outside AabenForms.
   *
   * By default only an ESDH reference counts: the ESDH holds the record,
   * while SF1470 is an index of it. require_esdh_ref: false also accepts the
   * index reference, for a kommune whose DPO decides that suffices.
   *
   * @param array<string, mixed> $row
   *   A case row.
   */
  protected function isJournalised(array $row): bool {
    if (trim((string) ($row['esdh_ref'] ?? '')) !== '') {
      return TRUE;
    }
    return !$this->policy()->requireEsdhRef && trim((string) ($row['journal_ref'] ?? '')) !== '';
  }

  /**
   * The webform of a submission, or NULL when it does not exist.
   */
  protected function webformOf(?int $sid): ?string {
    if ($sid === NULL || !$this->submissionsSupported()) {
      return NULL;
    }
    $value = $this->database->select('webform_submission', 's')
      ->fields('s', ['webform_id'])
      ->condition('s.sid', $sid)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

  /**
   * Every Digital Post transaction id recorded on any revision of the case.
   *
   * @return string[]
   *   Transaction ids.
   */
  protected function caseDigitalPostTransactions(int $caseId): array {
    if (!$this->database->schema()->fieldExists('aabenforms_case_revision', 'digital_post_tx')) {
      return [];
    }
    $tx = $this->database->select('aabenforms_case_revision', 'r')
      ->fields('r', ['digital_post_tx'])
      ->condition('r.id', $caseId)
      ->isNotNull('r.digital_post_tx')
      ->condition('r.digital_post_tx', '', '<>')
      ->distinct()
      ->execute()
      ->fetchCol();
    return array_values(array_map('strval', $tx));
  }

  /**
   * Deletes trace rows for the submissions and the case.
   */
  protected function deleteTraceRows(array $sids, ?int $caseId): int {
    if (!$this->database->schema()->tableExists('aabenforms_trace') || ($sids === [] && $caseId === NULL)) {
      return 0;
    }
    $query = $this->database->delete('aabenforms_trace');
    $or = $query->orConditionGroup();
    if ($sids !== []) {
      $or->condition('sid', $sids, 'IN');
    }
    if ($caseId !== NULL) {
      $or->condition('case_id', $caseId);
    }
    return (int) $query->condition($or)->execute();
  }

  /**
   * Redacts the Digital Post log rows with the given transaction ids.
   *
   * @param string[] $transactionIds
   *   Transaction ids.
   */
  protected function redactDigitalPostRows(array $transactionIds): int {
    if ($transactionIds === [] || !$this->database->schema()->tableExists('aabenforms_digital_post_log')) {
      return 0;
    }
    return $this->redactDigitalPost('transaction_id', $transactionIds);
  }

  /**
   * Blanks the personal parts of Digital Post log rows, keeping the row.
   *
   * Kept: id, transaction_id (idempotency and receipt correlation), mode,
   * recipient_type, sender_cvr (public), status, reason_code, created and
   * the receipt columns. Removed: the letter body and MeMo XML (payload,
   * which carries the recipient CPR in clear), the transport response, the
   * subject and the recipient hash.
   *
   * @param string $column
   *   The key column, id or transaction_id.
   * @param array<int, int|string> $values
   *   The keys.
   */
  protected function redactDigitalPost(string $column, array $values): int {
    $count = 0;
    foreach (array_chunk($values, 500) as $chunk) {
      $count += (int) $this->database->update('aabenforms_digital_post_log')
        ->fields([
          'payload' => self::REDACTED_PAYLOAD,
          'response' => NULL,
          'subject' => '[redacted]',
          'recipient_identifier_hash' => '',
        ])
        ->condition($column, $chunk, 'IN')
        ->condition('payload', self::REDACTED_PAYLOAD, '<>')
        ->execute();
    }
    return $count;
  }

  /**
   * Deletes submissions through the entity API (data rows, files, hooks).
   *
   * @param int[] $sids
   *   Submission ids.
   */
  protected function deleteSubmissions(array $sids): void {
    if ($sids === []) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $submissions = $storage->loadMultiple($sids);
    if ($submissions !== []) {
      $storage->delete($submissions);
    }
  }

  /**
   * Whether the case entity type is installed.
   */
  protected function casesSupported(): bool {
    return $this->entityTypeManager->hasDefinition('aabenforms_case')
      && $this->database->schema()->tableExists('aabenforms_case_revision');
  }

  /**
   * Whether webform submissions are installed.
   */
  protected function submissionsSupported(): bool {
    return $this->entityTypeManager->hasDefinition('webform_submission')
      && $this->database->schema()->tableExists('webform_submission');
  }

  /**
   * Whether cases carry the aabenforms_esdh esdh_ref column.
   */
  protected function hasEsdhRef(): bool {
    return $this->database->schema()->fieldExists('aabenforms_case', 'esdh_ref');
  }

}
