<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\aabenforms_core\Retention\ErasureService;
use Drupal\aabenforms_core\Retention\RetentionManager;
use Drupal\aabenforms_core\Retention\RetentionVerdict;
use Drupal\Core\Database\Connection;
use Drupal\Core\Serialization\Yaml;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the retention policy and the right-to-erasure rules (issue #91).
 *
 * Built on real webform submissions and real revisionable cases: the purge
 * must take every revision, every linked submission and its data rows, the
 * trace and the letter bodies, and must never touch an open, unjournalised
 * or held case.
 */
#[Group('aabenforms_core')]
class RetentionManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'modeler_api',
    'eca',
    'webform',
    'aabenforms_core',
    'aabenforms_case',
    'aabenforms_digital_post',
    'aabenforms_digital_post_beskedfordeler',
  ];

  /**
   * A valid test CPR; must never appear in an audit row.
   */
  protected const CPR = '2403482210';

  /**
   * The service under test.
   */
  protected RetentionManager $manager;

  /**
   * The erasure service under test.
   */
  protected ErasureService $erasure;

  /**
   * The current request time.
   */
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installEntitySchema('aabenforms_case');
    $this->installSchema('webform', ['webform']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log', 'aabenforms_trace']);
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
    $this->config('aabenforms_case.settings')
      ->set('frister', ['default' => ['unit' => 'hverdage', 'amount' => 28]])
      ->save();
    $this->config('aabenforms_core.retention')->setData([
      'enabled' => TRUE,
      'default_days_after_close' => 90,
      'webforms' => [],
      'unlinked_submission_days' => 30,
      'digital_post_log_days' => 180,
      'batch_size' => 50,
      // These fixtures journalise via SF1470 only; the strict default is
      // covered by testOnlyEsdhRecordCountsByDefault().
      'require_esdh_ref' => FALSE,
    ])->save();

    // The presave hook encrypts the CPR at rest and fails without a profile.
    Key::create([
      'id' => 'aabenforms_cpr_test',
      'label' => 'CPR test key',
      'key_type' => 'encryption',
      'key_type_settings' => ['key_size' => '256'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => base64_encode(random_bytes(32)), 'base64_encoded' => TRUE],
      'key_input' => 'none',
    ])->save();
    EncryptionProfile::create([
      'id' => 'aabenforms_aes256',
      'label' => 'AES 256',
      'encryption_method' => 'real_aes',
      'encryption_key' => 'aabenforms_cpr_test',
    ])->save();

    Webform::create([
      'id' => 'intake',
      'title' => 'Ansøgning',
      'elements' => Yaml::encode([
        'applicant_cpr' => ['#type' => 'textfield', '#title' => 'CPR'],
        'name' => ['#type' => 'textfield', '#title' => 'Navn'],
      ]),
    ])->save();
    Webform::create([
      'id' => 'klage',
      'title' => 'Klage',
      'elements' => Yaml::encode([
        'case_id' => ['#type' => 'textfield', '#title' => 'Sag'],
        'klage_begrundelse' => ['#type' => 'textarea', '#title' => 'Begrundelse'],
      ]),
    ])->save();
    Webform::create([
      'id' => 'approval',
      'title' => 'Godkendelse',
      'elements' => Yaml::encode([
        'applicant_cpr' => ['#type' => 'textfield', '#title' => 'CPR'],
        'caseworker_status' => ['#type' => 'hidden', '#default_value' => 'pending'],
      ]),
    ])->save();

    $this->manager = $this->container->get('aabenforms_core.retention_manager');
    $this->erasure = $this->container->get('aabenforms_core.erasure');
    $this->now = (int) $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * A due, closed and journalised case goes with everything tied to it.
   */
  public function testDueClosedJournalisedCaseIsPurgedCompletely(): void {
    $sid = $this->submission('intake');
    $caseId = $this->closedCase($sid, 'J-2026-1', 100, 'tx-case-letter');
    $klageSid = $this->submission('klage', ['case_id' => (string) $caseId, 'klage_begrundelse' => 'Uenig']);
    $this->trace($sid, $caseId);
    $this->trace($klageSid, NULL);
    $this->letter('tx-case-letter', $this->now - 86400);

    $this->assertGreaterThan(1, $this->revisionCount($caseId), 'The case has several revisions');
    $report = $this->manager->run(FALSE, NULL, 'test');

    $this->assertSame([$caseId], $report['cases']);
    $this->assertSame([$sid, $klageSid], $report['submissions']);
    $this->assertSame(0, $this->revisionCount($caseId), 'Every revision is gone');
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('aabenforms_case')->loadUnchanged($caseId));
    $this->assertNull(WebformSubmission::load($sid));
    $this->assertNull(WebformSubmission::load($klageSid));
    $this->assertSame(0, $this->countRows('webform_submission_data', 'sid', [$sid, $klageSid]), 'Submission data rows are gone');
    $this->assertSame(0, $this->countRows('aabenforms_trace', 'sid', [$sid, $klageSid]));
    $this->assertSame(2, $report['trace_rows']);

    $letter = $this->database()->select('aabenforms_digital_post_log', 'l')->fields('l')
      ->condition('transaction_id', 'tx-case-letter')->execute()->fetchAssoc();
    $this->assertNotFalse($letter, 'The log row is kept for idempotency');
    $this->assertSame(RetentionManager::REDACTED_PAYLOAD, $letter['payload']);
    $this->assertSame('', $letter['recipient_identifier_hash']);
    $this->assertSame(1, $report['digital_post_redacted']);

    $audit = $this->auditRows('retention_purged');
    $this->assertCount(1, $audit);
    $this->assertSame(hash('sha256', 'case:' . $caseId), $audit[0]['identifier_hash']);
    $context = json_decode($audit[0]['context'], TRUE);
    $this->assertSame($caseId, $context['case_id']);
    $this->assertSame([$sid, $klageSid], $context['submission_ids']);
    $this->assertNoCprInAudit();
  }

  /**
   * Open, unjournalised, held and not-yet-due cases are never purged.
   */
  public function testProtectedCasesAreNeverPurged(): void {
    $openSid = $this->submission('intake');
    $openId = $this->openCase($openSid, 'J-OPEN', 400);
    $unjournalisedSid = $this->submission('intake');
    $unjournalisedId = $this->closedCase($unjournalisedSid, '', 400);
    $heldSid = $this->submission('intake');
    $heldId = $this->closedCase($heldSid, 'J-HELD', 400);
    $recentSid = $this->submission('intake');
    $recentId = $this->closedCase($recentSid, 'J-RECENT', 10);
    $this->assertSame('placed', $this->erasure->setLegalHold((string) $heldId, TRUE, 'Klagesag ved Ankestyrelsen'));

    $report = $this->manager->run(FALSE, NULL, 'test');

    $this->assertSame([], $report['cases']);
    $this->assertSame([], $report['unlinked_submissions'], 'Linked submissions are never treated as unlinked');
    $this->assertSame(1, $report['skipped'][RetentionVerdict::OPEN]);
    $this->assertSame(1, $report['skipped'][RetentionVerdict::UNJOURNALISED]);
    $this->assertSame(1, $report['skipped'][RetentionVerdict::LEGAL_HOLD]);
    $this->assertSame(1, $report['skipped']['not_due']);
    foreach ([$openId, $unjournalisedId, $heldId, $recentId] as $caseId) {
      $this->assertGreaterThan(0, $this->revisionCount($caseId));
    }
    foreach ([$openSid, $unjournalisedSid, $heldSid, $recentSid] as $sid) {
      $this->assertNotNull(WebformSubmission::load($sid));
    }
    $this->assertSame([], $this->auditRows('retention_purged'));

    // Releasing the hold lets the next run purge it.
    $this->assertSame('released', $this->erasure->setLegalHold('case:' . $heldId, FALSE, 'Afgjort'));
    $report = $this->manager->run(FALSE, NULL, 'test');
    $this->assertSame([$heldId], $report['cases']);
    $this->assertCount(2, $this->auditRows('legal_hold'));
  }

  /**
   * A dry run reports what is due and changes nothing.
   */
  public function testDryRunChangesNothing(): void {
    $sid = $this->submission('intake');
    $caseId = $this->closedCase($sid, 'J-1', 100);
    $unlinked = $this->submission('intake', [], 40);
    $this->letter('tx-old', $this->now - (200 * 86400));

    $report = $this->manager->run(TRUE, NULL, 'test');

    $this->assertTrue($report['dry_run']);
    $this->assertSame([$caseId], $report['cases']);
    $this->assertSame([$sid], $report['submissions']);
    $this->assertSame([$unlinked], $report['unlinked_submissions']);
    $this->assertSame(1, $report['digital_post_aged']);
    $this->assertGreaterThan(0, $this->revisionCount($caseId));
    $this->assertNotNull(WebformSubmission::load($sid));
    $this->assertNotNull(WebformSubmission::load($unlinked));
    $payload = $this->database()->select('aabenforms_digital_post_log', 'l')->fields('l', ['payload'])
      ->condition('transaction_id', 'tx-old')->execute()->fetchField();
    $this->assertNotSame(RetentionManager::REDACTED_PAYLOAD, $payload);
    $this->assertSame([], $this->auditRows('retention_purged'));
  }

  /**
   * By default only the ESDH record counts: an SF1470 index alone is kept.
   */
  public function testOnlyEsdhRecordCountsByDefault(): void {
    $this->config('aabenforms_core.retention')->set('require_esdh_ref', TRUE)->save();
    $sid = $this->submission('intake');
    $caseId = $this->closedCase($sid, 'J-1', 100);

    $report = $this->manager->run(TRUE, NULL, 'test');
    $this->assertNotContains($caseId, $report['cases'], 'A closed case known only to the SF1470 index is not purged');
    $this->assertSame(1, $report['skipped']['unjournalised'] ?? NULL, json_encode($report['skipped'] ?? []));
  }

  /**
   * Old submissions that never became a case are purged; others are kept.
   */
  public function testUnlinkedSubmissions(): void {
    $old = $this->submission('intake', [], 40);
    $fresh = $this->submission('intake', [], 5);
    $pending = $this->submission('approval', [], 40);
    $openCaseId = $this->openCase($this->submission('intake'), '', 1);
    $klageOnOpenCase = $this->submission('klage', ['case_id' => (string) $openCaseId], 40);
    $this->trace($old, NULL);

    $report = $this->manager->run(FALSE, NULL, 'test');

    $this->assertSame([$old], $report['unlinked_submissions']);
    $this->assertNull(WebformSubmission::load($old));
    $this->assertSame(0, $this->countRows('aabenforms_trace', 'sid', [$old]));
    $this->assertNotNull(WebformSubmission::load($fresh), 'Not yet due');
    $this->assertNotNull(WebformSubmission::load($pending), 'A pending caseworker decision is kept');
    $this->assertNotNull(WebformSubmission::load($klageOnOpenCase), 'A klage on an open case waits for the case');
    $this->assertSame(1, $report['skipped'][RetentionVerdict::PENDING_DECISION]);
    $this->assertSame(hash('sha256', 'submission:' . $old), $this->auditRows('retention_purged')[0]['identifier_hash']);
    $this->assertNoCprInAudit();
  }

  /**
   * A cron batch stops at batch_size and reports that more is due.
   */
  public function testBatchLimit(): void {
    $this->config('aabenforms_core.retention')->set('batch_size', 1)->save();
    $first = $this->submission('intake', [], 50);
    $second = $this->submission('intake', [], 45);

    $report = $this->manager->run(FALSE, NULL, 'cron');

    $this->assertSame([$first], $report['unlinked_submissions']);
    $this->assertTrue($report['more']);
    $this->assertNotNull(WebformSubmission::load($second));
  }

  /**
   * A per-webform override takes precedence over the default period.
   */
  public function testWebformOverride(): void {
    $this->config('aabenforms_core.retention')
      ->set('webforms', [['id' => 'intake', 'days_after_close' => 365, 'unlinked_submission_days' => NULL]])
      ->save();
    $caseId = $this->closedCase($this->submission('intake'), 'J-1', 100);

    $this->assertSame([], $this->manager->run(FALSE, NULL, 'test')['cases']);
    $this->assertGreaterThan(0, $this->revisionCount($caseId));
  }

  /**
   * Digital Post log rows past their period lose the letter, not the row.
   */
  public function testAgedDigitalPostLogIsRedacted(): void {
    $this->letter('tx-old', $this->now - (200 * 86400));
    $this->letter('tx-new', $this->now - (10 * 86400));

    $report = $this->manager->run(FALSE, NULL, 'test');

    $this->assertSame(1, $report['digital_post_aged']);
    $rows = $this->database()->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['transaction_id', 'payload', 'subject', 'status'])
      ->execute()->fetchAllAssoc('transaction_id', \PDO::FETCH_ASSOC);
    $this->assertSame(RetentionManager::REDACTED_PAYLOAD, $rows['tx-old']['payload']);
    $this->assertSame('success', $rows['tx-old']['status'], 'The delivery status survives');
    $this->assertStringContainsString(self::CPR, $rows['tx-new']['payload']);
    $this->assertNoCprInAudit();
  }

  /**
   * Erasure is refused, and audited, while the law requires keeping the data.
   */
  public function testErasureRefusesProtectedCases(): void {
    $openSid = $this->submission('intake');
    $openId = $this->openCase($openSid, 'J-1', 1);
    $unjournalisedId = $this->closedCase($this->submission('intake'), '', 1);
    $heldId = $this->closedCase($this->submission('intake'), 'J-2', 1);
    $this->erasure->setLegalHold('case:' . $heldId, TRUE, 'Retssag');
    $pending = $this->submission('approval');

    $this->assertSame([ErasureService::REFUSED, RetentionVerdict::OPEN], $this->outcome('case:' . $openId));
    $this->assertSame([ErasureService::REFUSED, RetentionVerdict::OPEN], $this->outcome('submission:' . $openSid), 'A linked submission follows its case');
    $this->assertSame([ErasureService::REFUSED, RetentionVerdict::UNJOURNALISED], $this->outcome('case:' . $unjournalisedId));
    $this->assertSame([ErasureService::REFUSED, RetentionVerdict::LEGAL_HOLD], $this->outcome('case:' . $heldId));
    $this->assertSame([ErasureService::REFUSED, RetentionVerdict::PENDING_DECISION], $this->outcome('submission:' . $pending));
    $this->assertSame([ErasureService::NOT_FOUND, NULL], $this->outcome('case:99999'));
    $this->assertSame([ErasureService::INVALID, NULL], $this->outcome((string) $openId), 'A bare id is ambiguous');
    $this->assertSame(ErasureService::INVALID, $this->erasure->requestErasure('case:' . $openId, '  ')['outcome'], 'A reason is required');

    foreach ([$openId, $unjournalisedId, $heldId] as $caseId) {
      $this->assertGreaterThan(0, $this->revisionCount($caseId));
    }
    $this->assertNotNull(WebformSubmission::load($pending));
    $refused = array_filter($this->auditRows('erasure_requested'), static fn (array $row): bool => $row['status'] === ErasureService::REFUSED);
    $this->assertCount(5, $refused);
    $this->assertSame([], $this->auditRows('retention_purged'));
    $this->assertNoCprInAudit();
  }

  /**
   * Erasure purges a closed, journalised case at once, and unlinked data.
   */
  public function testErasurePurgesAllowedTargets(): void {
    $sid = $this->submission('intake');
    // Closed yesterday: not due under the policy, but erasure does not wait.
    $caseId = $this->closedCase($sid, 'J-1', 1);
    $unlinked = $this->submission('intake');

    $result = $this->erasure->requestErasure('submission:' . $sid, 'DPO-2026-17 for ' . self::CPR);
    $this->assertSame(ErasureService::PURGED, $result['outcome']);
    $this->assertSame($caseId, $result['case_id']);
    $this->assertSame(0, $this->revisionCount($caseId));
    $this->assertNull(WebformSubmission::load($sid));

    $this->assertSame(ErasureService::PURGED, $this->erasure->requestErasure('submission:' . $unlinked, 'DPO-2026-18')['outcome']);
    $this->assertNull(WebformSubmission::load($unlinked));

    $purged = array_values(array_filter($this->auditRows('erasure_requested'), static fn (array $row): bool => $row['status'] === ErasureService::PURGED));
    $this->assertCount(2, $purged);
    $this->assertStringNotContainsString(self::CPR, $purged[0]['context'], 'The CPR typed into the reason is masked');
    $erasurePurges = array_filter($this->auditRows('retention_purged'), static fn (array $row): bool => $row['purpose'] === RetentionManager::PURPOSE_ERASURE);
    $this->assertCount(2, $erasurePurges);
    $this->assertNoCprInAudit();
  }

  /**
   * Creates a submission, optionally last changed $daysAgo days ago.
   *
   * @param string $webformId
   *   The webform.
   * @param array<string, string> $data
   *   Element values; intake gets a CPR and a name by default.
   * @param int $daysAgo
   *   How old the submission is.
   */
  protected function submission(string $webformId, array $data = [], int $daysAgo = 0): int {
    if ($webformId !== 'klage') {
      $data += ['applicant_cpr' => self::CPR];
    }
    if ($webformId === 'intake') {
      $data += ['name' => 'Birthe Jensen'];
    }
    if ($webformId === 'approval') {
      // What the decision-field guard stores on an API create.
      $data += ['caseworker_status' => 'pending'];
    }
    $submission = WebformSubmission::create(['webform_id' => $webformId, 'data' => $data]);
    $submission->save();
    $sid = (int) $submission->id();
    if ($daysAgo > 0) {
      $time = $this->now - ($daysAgo * 86400);
      $this->database()->update('webform_submission')
        ->fields(['created' => $time, 'changed' => $time])
        ->condition('sid', $sid)
        ->execute();
    }
    return $sid;
  }

  /**
   * Opens a case through several revisions.
   */
  protected function openCase(int $sid, string $journalRef, int $daysAgo): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $case = $storage->create([
      'title' => 'Sag om Birthe Jensen',
      'case_type' => 'merudgifter',
      'submission_ref' => $sid,
      'journal_ref' => $journalRef === '' ? NULL : $journalRef,
    ]);
    $case->save();
    $case->setStatus('oplyst');
    $case->setRevisionLogMessage('Oplyst.');
    $case->save();
    $this->database()->update('aabenforms_case_revision')
      ->fields(['revision_created' => $this->now - ($daysAgo * 86400)])
      ->condition('id', $case->id())
      ->execute();
    return (int) $case->id();
  }

  /**
   * Opens and closes a case, backdating the closure.
   */
  protected function closedCase(int $sid, string $journalRef, int $closedDaysAgo, string $digitalPostTx = ''): int {
    $caseId = $this->openCase($sid, $journalRef, $closedDaysAgo + 5);
    $case = $this->container->get('entity_type.manager')->getStorage('aabenforms_case')->loadUnchanged($caseId);
    if ($digitalPostTx !== '') {
      $case->set('digital_post_tx', $digitalPostTx);
    }
    $case->setStatus('lukket');
    $case->setRevisionLogMessage('Lukket.');
    $case->save();
    $this->database()->update('aabenforms_case_revision')
      ->fields(['revision_created' => $this->now - ($closedDaysAgo * 86400)])
      ->condition('id', $caseId)
      ->condition('status', 'lukket')
      ->execute();
    return $caseId;
  }

  /**
   * Writes a trace row.
   */
  protected function trace(int $sid, ?int $caseId): void {
    $this->database()->insert('aabenforms_trace')->fields([
      'sid' => $sid,
      'webform_id' => 'intake',
      'case_id' => $caseId,
      'status' => 'completed',
      'step_count' => 1,
      'steps' => '[]',
      'created' => $this->now,
    ])->execute();
  }

  /**
   * Writes a Digital Post log row whose MeMo XML carries the CPR, as live does.
   */
  protected function letter(string $transactionId, int $created): void {
    $this->database()->insert('aabenforms_digital_post_log')->fields([
      'transaction_id' => $transactionId,
      'mode' => 'fake_db',
      'recipient_type' => 'cpr',
      'recipient_identifier_hash' => hash('sha256', 'cpr:' . self::CPR),
      'sender_cvr' => '29189846',
      'subject' => 'Afgørelse til Birthe Jensen',
      'status' => 'success',
      'payload' => json_encode(['body' => 'Kære Birthe', 'memo_xml' => '<RecipientID>' . self::CPR . '</RecipientID>']),
      'response' => 'fake_db:synthetic-receipt',
      'created' => $created,
    ])->execute();
  }

  /**
   * Returns [outcome, refusal] of an erasure request.
   */
  protected function outcome(string $target): array {
    $result = $this->erasure->requestErasure($target, 'DPO-2026-1');
    return [$result['outcome'], $result['refusal']];
  }

  /**
   * Audit rows for an action, oldest first.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function auditRows(string $action): array {
    return $this->database()->select('aabenforms_audit_log', 'a')->fields('a')
      ->condition('action', $action)->orderBy('id')
      ->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Asserts no audit row carries the CPR, formatted or not, or the name.
   */
  protected function assertNoCprInAudit(): void {
    foreach ($this->database()->select('aabenforms_audit_log', 'a')->fields('a')->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $flat = implode('|', array_map('strval', $row));
      $this->assertStringNotContainsString(self::CPR, $flat);
      $this->assertStringNotContainsString('240348-2210', $flat);
      $this->assertStringNotContainsString('Birthe', $flat);
    }
  }

  /**
   * Counts the case's revisions.
   */
  protected function revisionCount(int $caseId): int {
    return (int) $this->database()->select('aabenforms_case_revision', 'r')
      ->condition('id', $caseId)->countQuery()->execute()->fetchField();
  }

  /**
   * Counts rows in a table whose column is in the values.
   */
  protected function countRows(string $table, string $column, array $values): int {
    return (int) $this->database()->select($table, 't')
      ->condition($column, $values, 'IN')->countQuery()->execute()->fetchField();
  }

  /**
   * The database connection.
   */
  protected function database(): Connection {
    return $this->container->get('database');
  }

}
