<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Drush;

use Drupal\aabenforms_core\Retention\ErasureService;
use Drupal\aabenforms_core\Retention\RetentionManager;
use Drupal\aabenforms_core\Retention\RetentionVerdict;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for retention, erasure requests and legal holds (#91).
 *
 * Output carries ids and counts only, never personal data.
 */
final class RetentionCommands extends DrushCommands {

  public function __construct(
    private readonly RetentionManager $retentionManager,
    private readonly ErasureService $erasure,
  ) {
    parent::__construct();
  }

  /**
   * Purge intake copies that are due under aabenforms_core.retention.
   *
   * Only closed (lukket) and journalised cases outside a legal hold, and
   * submissions that never became a case, are ever purged.
   *
   * @param array $options
   *   Command options.
   *
   * @command aabenforms:retention:run
   * @aliases af:retention
   * @option dry-run Print what would be purged (ids and counts) and change nothing.
   * @option limit Maximum cases plus unlinked submissions to purge (default: batch_size; all on a dry run).
   * @usage drush aabenforms:retention:run --dry-run
   *   Show what is due.
   * @usage drush aabenforms:retention:run
   *   Purge one batch of due data.
   */
  public function run(array $options = ['dry-run' => FALSE, 'limit' => NULL]): int {
    $dryRun = (bool) $options['dry-run'];
    $limit = $options['limit'] === NULL ? NULL : max(1, (int) $options['limit']);
    if (!$dryRun && !$this->retentionManager->policy()->enabled) {
      $this->io()->error(dt('Retention is disabled (aabenforms_core.retention:enabled). Nothing was purged.'));
      return self::EXIT_FAILURE;
    }

    $report = $this->retentionManager->run($dryRun, $limit, 'drush');
    if ($report['locked']) {
      $this->io()->error(dt('Another retention run is in progress. Try again later.'));
      return self::EXIT_FAILURE;
    }

    $this->io()->title($dryRun ? dt('Retention dry run: nothing was changed') : dt('Retention run'));
    $verb = $dryRun ? dt('would be purged') : dt('purged');
    $this->io()->definitionList(
      [dt('Cases @verb', ['@verb' => $verb]) => (string) count($report['cases'])],
      [dt('Case submissions @verb', ['@verb' => $verb]) => (string) count($report['submissions'])],
      [dt('Unlinked submissions @verb', ['@verb' => $verb]) => (string) count($report['unlinked_submissions'])],
      [dt('Case revisions') => (string) $report['revisions']],
      [dt('Trace rows') => (string) $report['trace_rows']],
      [dt('Digital Post log rows redacted (case letters)') => (string) $report['digital_post_redacted']],
      [dt('Digital Post log rows redacted (age)') => (string) $report['digital_post_aged']],
      [dt('Kept: open cases') => (string) ($report['skipped'][RetentionVerdict::OPEN] ?? 0)],
      [dt('Kept: closed but unjournalised') => (string) ($report['skipped'][RetentionVerdict::UNJOURNALISED] ?? 0)],
      [dt('Kept: legal hold') => (string) ($report['skipped'][RetentionVerdict::LEGAL_HOLD] ?? 0)],
      [dt('Kept: unlinked, decision pending') => (string) ($report['skipped'][RetentionVerdict::PENDING_DECISION] ?? 0)],
      [dt('Kept: not yet due') => (string) ($report['skipped']['not_due'] ?? 0)],
    );
    foreach ([
      'cases' => dt('Case ids'),
      'submissions' => dt('Case submission ids'),
      'unlinked_submissions' => dt('Unlinked submission ids'),
    ] as $key => $label) {
      if ($report[$key] !== []) {
        $this->io()->writeln($label . ': ' . implode(', ', $report[$key]));
      }
    }
    if (!$report['enabled']) {
      $this->io()->note(dt('Scheduled retention is disabled; cron purges nothing.'));
    }
    if ($report['more']) {
      $this->io()->note(dt('More data is due beyond this batch. Run the command again.'));
    }
    if ($report['failed'] !== []) {
      foreach ($report['failed'] as $target => $reason) {
        $this->io()->warning(dt('@target was not purged (@reason).', ['@target' => $target, '@reason' => $reason]));
      }
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Handle a GDPR art. 17 erasure request for a case or a submission.
   *
   * Refused for open, unjournalised or held cases (art. 17(3)(b) and
   * arkivloven). A closed and journalised case, or a submission that never
   * became a case, is purged now. Every request is audited.
   *
   * @param string $target
   *   The target, "case:<id>" or "submission:<sid>".
   * @param array $options
   *   Command options.
   *
   * @command aabenforms:erasure:request
   * @aliases af:erasure
   * @option reason The DPO's reason or reference (required). Do not write personal data here.
   * @usage drush aabenforms:erasure:request case:42 --reason="DPO-2026-17"
   *   Erase case 42 if the law allows it.
   * @usage drush aabenforms:erasure:request submission:310 --reason="DPO-2026-18"
   *   Erase submission 310 (or the case it belongs to).
   */
  public function erasureRequest(string $target, array $options = ['reason' => '']): int {
    $result = $this->erasure->requestErasure($target, (string) ($options['reason'] ?? ''));
    switch ($result['outcome']) {
      case ErasureService::PURGED:
        $this->io()->success(dt('Erased. Case: @case. Submissions: @sids. Revisions: @rev. Trace rows: @trace. Digital Post rows redacted: @dp.', [
          '@case' => $result['case_id'] ?? '-',
          '@sids' => $this->ids($result['submission_ids']),
          '@rev' => $result['revisions'],
          '@trace' => $result['trace_rows'],
          '@dp' => $result['digital_post_rows'],
        ]));
        return self::EXIT_SUCCESS;

      case ErasureService::REFUSED:
        $this->io()->error($this->refusalMessage((string) $result['refusal'], $result['case_id']));
        return self::EXIT_FAILURE;

      case ErasureService::NOT_FOUND:
        $this->io()->error(dt('No such case or submission. The request was audited.'));
        return self::EXIT_FAILURE;

      default:
        $this->io()->error(dt('Give the target as case:<id> or submission:<sid>, and a non-empty --reason.'));
        return self::EXIT_FAILURE;
    }
  }

  /**
   * Place or release a legal hold on a case or a submission.
   *
   * A held case or submission is never purged, by retention or erasure.
   *
   * @param string $target
   *   A case id, "case:<id>" or "submission:<sid>".
   * @param array $options
   *   Command options.
   *
   * @command aabenforms:legal-hold
   * @aliases af:legal-hold
   * @option on Place the hold.
   * @option off Release the hold.
   * @option reason Why (required). Do not write personal data here.
   * @usage drush aabenforms:legal-hold 42 --on --reason="Klagesag ved Ankestyrelsen"
   *   Hold case 42.
   * @usage drush aabenforms:legal-hold 42 --off --reason="Afgjort"
   *   Release the hold on case 42.
   */
  public function legalHold(string $target, array $options = ['on' => FALSE, 'off' => FALSE, 'reason' => '']): int {
    $on = (bool) $options['on'];
    $off = (bool) $options['off'];
    if ($on === $off) {
      $this->io()->error(dt('Give exactly one of --on or --off.'));
      return self::EXIT_FAILURE;
    }
    $outcome = $this->erasure->setLegalHold($target, $on, (string) ($options['reason'] ?? ''));
    $messages = [
      'placed' => dt('Legal hold placed on @target.', ['@target' => $target]),
      'released' => dt('Legal hold released on @target.', ['@target' => $target]),
      'not_held' => dt('@target was not under legal hold.', ['@target' => $target]),
      'not_found' => dt('No such case or submission.'),
      'invalid' => dt('Give the target as a case id, case:<id> or submission:<sid>, and a non-empty --reason.'),
    ];
    if (in_array($outcome, ['placed', 'released'], TRUE)) {
      $this->io()->success($messages[$outcome]);
      return self::EXIT_SUCCESS;
    }
    $this->io()->error($messages[$outcome] ?? $outcome);
    return self::EXIT_FAILURE;
  }

  /**
   * Explains a refusal, citing the legal basis.
   */
  private function refusalMessage(string $refusal, ?int $caseId): string {
    $basis = dt('GDPR art. 17(3)(b) exempts data the authority must keep to meet a legal obligation, and arkivloven with the journaliseringspligt requires the case record to be kept.');
    $case = $caseId === NULL ? '-' : (string) $caseId;
    return match ($refusal) {
      RetentionVerdict::OPEN => dt(
        'Refused: case @case is still open. @basis Erasure can be reconsidered once the case is closed and journalised.',
        ['@case' => $case, '@basis' => $basis],
      ),
      RetentionVerdict::UNJOURNALISED => dt(
        'Refused: case @case is closed but not journalised (no journal or ESDH reference), so this copy may be the only record. @basis Journalise the case first.',
        ['@case' => $case, '@basis' => $basis],
      ),
      RetentionVerdict::PENDING_DECISION => dt(
        'Refused: the submission still awaits a decision by the authority (a caseworker, consent or parent status is pending). @basis',
        ['@basis' => $basis],
      ),
      RetentionVerdict::LEGAL_HOLD => dt('Refused: the data is under legal hold. @basis Release the hold with aabenforms:legal-hold --off first if it no longer applies.', ['@basis' => $basis]),
      default => dt('Refused (@reason). @basis', ['@reason' => $refusal, '@basis' => $basis]),
    };
  }

  /**
   * Formats an id list.
   *
   * @param int[] $ids
   *   Ids.
   */
  private function ids(array $ids): string {
    return $ids === [] ? '0' : count($ids) . ' (' . implode(', ', $ids) . ')';
  }

}
