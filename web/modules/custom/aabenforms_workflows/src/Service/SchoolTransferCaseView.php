<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Read model for the school transfer (skoleskift) case across its parties.
 *
 * Serves two audiences with different rules:
 *  - the co-signing parent (receipt): found only by the CPR from that
 *    parent's own MitID session, compared server-side with the decrypted
 *    parent2_cpr of the submission. No CPR is returned, only names of the
 *    two custody holders (whom the parent already knows from the request).
 *  - the school leader (staff decision): masked CPRs only.
 */
class SchoolTransferCaseView {

  public const WEBFORM_ID = 'school_transfer';

  /**
   * Subject of the decision letter sent by skoleskift_decision_flow.
   */
  public const DECISION_LETTER_SUBJECT = 'Afgørelse: skoleskift';

  /**
   * How many recent co-signed submissions a lookup scans.
   */
  protected const SCAN_LIMIT = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected CprAccess $cprAccess,
    protected FamilyRelationsLookupInterface $familyLookup,
  ) {
  }

  /**
   * Finds the latest school transfer this person co-signed as parent 2.
   *
   * @param string $cpr
   *   The CPR from the person's own verified MitID session.
   *
   * @return \Drupal\webform\WebformSubmissionInterface|null
   *   The submission, or NULL when the person co-signed none.
   */
  public function findCoSignedBy(string $cpr): ?WebformSubmissionInterface {
    $cpr = self::digits($cpr);
    if (strlen($cpr) !== 10) {
      return NULL;
    }
    foreach ($this->recentSids(['parent2_status' => 'complete']) as $sid) {
      $submission = $this->loadSubmission($sid);
      if ($submission && self::digits($this->reveal($submission, 'parent2_cpr')) === $cpr) {
        return $submission;
      }
    }
    return NULL;
  }

  /**
   * Submissions that both parents signed and that await the decision.
   *
   * @return \Drupal\webform\WebformSubmissionInterface[]
   *   Newest first.
   */
  public function awaitingDecision(): array {
    $result = [];
    foreach ($this->recentSids(['parent1_status' => 'complete', 'parent2_status' => 'complete']) as $sid) {
      $submission = $this->loadSubmission($sid);
      if ($submission && $this->isAwaitingDecision($submission)) {
        $result[] = $submission;
      }
    }
    return $result;
  }

  /**
   * Whether the submission is co-signed and still has no decision.
   */
  public function isAwaitingDecision(WebformSubmissionInterface $submission): bool {
    if ($submission->getWebform()?->id() !== self::WEBFORM_ID) {
      return FALSE;
    }
    $data = $submission->getData();
    $decision = (string) ($data['caseworker_status'] ?? '');
    return ($data['parent1_status'] ?? '') === 'complete'
      && ($data['parent2_status'] ?? '') === 'complete'
      && !in_array($decision, ['approved', 'rejected'], TRUE);
  }

  /**
   * The case opened from the submission, if any.
   */
  public function caseFor(WebformSubmissionInterface $submission): ?ContentEntityInterface {
    if (!$this->entityTypeManager->hasDefinition('aabenforms_case')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('aabenforms_case');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('submission_ref', $submission->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $case = $ids ? $storage->load(reset($ids)) : NULL;
    return $case instanceof ContentEntityInterface ? $case : NULL;
  }

  /**
   * The co-signing parent's receipt. Contains no CPR numbers.
   *
   * @return array
   *   Keys: case_id, submission_id, child_name, current_school,
   *   target_school, stage (awaiting_decision|decided|in_progress),
   *   case_status, afgoerelse_type, klagefrist (ISO date|null),
   *   signed_by (names), decision_letters_sent_to (names).
   */
  public function receipt(WebformSubmissionInterface $submission): array {
    $case = $this->caseFor($submission);
    $names = $this->guardianNames($submission);
    $data = $submission->getData();

    $caseStatus = $case ? (string) $case->get('status')->value : NULL;
    $afgoerelse = $case ? ((string) ($case->get('afgoerelse_type')->value ?? '') ?: NULL) : NULL;
    $klagefrist = $case ? $case->get('klagefrist')->value : NULL;
    $stage = 'in_progress';
    if ($caseStatus === 'afgoerelse' || $afgoerelse !== NULL) {
      $stage = 'decided';
    }
    elseif ($this->isAwaitingDecision($submission)) {
      $stage = 'awaiting_decision';
    }

    $sentTo = [];
    if ($stage === 'decided') {
      $since = $case && $case->hasField('afgoerelse_dato') && $case->get('afgoerelse_dato')->value
        ? (int) $case->get('afgoerelse_dato')->value - 300
        : (int) $submission->getChangedTime() - 300;
      foreach (['applicant_cpr' => $names['applicant'], 'parent2_cpr' => $names['parent2']] as $key => $name) {
        if ($this->hasDecisionLetter($this->reveal($submission, $key), $since)) {
          $sentTo[] = $name;
        }
      }
    }

    return [
      'case_id' => $case ? (int) $case->id() : NULL,
      'submission_id' => (int) $submission->id(),
      'child_name' => (string) ($data['child_name'] ?? ''),
      'current_school' => $this->optionLabel($submission, 'current_school'),
      'target_school' => $this->optionLabel($submission, 'target_school'),
      'stage' => $stage,
      'case_status' => $caseStatus,
      'afgoerelse_type' => $afgoerelse,
      'klagefrist' => $klagefrist ? gmdate('Y-m-d', (int) $klagefrist) : NULL,
      'signed_by' => array_values(array_filter([
        ($data['parent1_status'] ?? '') === 'complete' ? $names['applicant'] : NULL,
        ($data['parent2_status'] ?? '') === 'complete' ? $names['parent2'] : NULL,
      ])),
      'decision_letters_sent_to' => $sentTo,
    ];
  }

  /**
   * The staff summary for the school leader. Masked CPRs only.
   *
   * @return array
   *   Keys: submission_id, case_id, child_name, child_cpr_masked,
   *   current_school, target_school, reason, signatures (list of name +
   *   cpr_masked + role).
   */
  public function staffSummary(WebformSubmissionInterface $submission): array {
    $names = $this->guardianNames($submission);
    $data = $submission->getData();
    $case = $this->caseFor($submission);
    return [
      'submission_id' => (int) $submission->id(),
      'case_id' => $case ? (int) $case->id() : NULL,
      'child_name' => (string) ($data['child_name'] ?? ''),
      'child_cpr_masked' => self::mask($this->reveal($submission, 'child_cpr')),
      'current_school' => $this->optionLabel($submission, 'current_school'),
      'target_school' => $this->optionLabel($submission, 'target_school'),
      'reason' => (string) ($data['reason'] ?? ''),
      'signatures' => [
        [
          'role' => 'applicant',
          'name' => $names['applicant'],
          'cpr_masked' => self::mask($this->reveal($submission, 'applicant_cpr')),
          'signed' => ($data['parent1_status'] ?? '') === 'complete',
        ],
        [
          'role' => 'cosigner',
          'name' => $names['parent2'],
          'cpr_masked' => self::mask($this->reveal($submission, 'parent2_cpr')),
          'signed' => ($data['parent2_status'] ?? '') === 'complete',
        ],
      ],
    ];
  }

  /**
   * Masks a CPR to DDMMYY-XXXX.
   */
  public static function mask(string $cpr): string {
    $digits = self::digits($cpr);
    return strlen($digits) === 10 ? substr($digits, 0, 6) . '-XXXX' : '';
  }

  /**
   * Names of the two custody holders, from the registry.
   *
   * @return array{applicant: string, parent2: string}
   *   Names; empty strings when the registry cannot name them.
   */
  protected function guardianNames(WebformSubmissionInterface $submission): array {
    $names = ['applicant' => '', 'parent2' => ''];
    $child = self::digits($this->reveal($submission, 'child_cpr'));
    if (strlen($child) !== 10) {
      return $names;
    }
    try {
      $guardians = $this->familyLookup->guardiansOf($child);
    }
    catch (\Throwable) {
      return $names;
    }
    $byCpr = [];
    foreach ($guardians as $guardian) {
      $byCpr[self::digits((string) ($guardian['cpr'] ?? ''))] = (string) ($guardian['full_name'] ?? '');
    }
    $names['applicant'] = $byCpr[self::digits($this->reveal($submission, 'applicant_cpr'))] ?? '';
    $names['parent2'] = $byCpr[self::digits($this->reveal($submission, 'parent2_cpr'))] ?? '';
    return $names;
  }

  /**
   * Whether a decision letter reached this CPR's Digital Post since a time.
   */
  protected function hasDecisionLetter(string $cpr, int $since): bool {
    $digits = self::digits($cpr);
    if (strlen($digits) !== 10 || !$this->database->schema()->tableExists('aabenforms_digital_post_log')) {
      return FALSE;
    }
    // Same hash as Recipient::identifierHash() in aabenforms_digital_post.
    $hash = hash('sha256', 'cpr:' . $digits);
    return (bool) $this->database->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['transaction_id'])
      ->condition('l.recipient_type', 'cpr')
      ->condition('l.recipient_identifier_hash', $hash)
      ->condition('l.subject', self::DECISION_LETTER_SUBJECT)
      ->condition('l.created', $since, '>=')
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Newest school transfer sids matching all given element values.
   *
   * @return int[]
   *   Submission ids.
   */
  protected function recentSids(array $values): array {
    $query = $this->database->select('webform_submission', 's')
      ->fields('s', ['sid'])
      ->condition('s.webform_id', self::WEBFORM_ID)
      ->orderBy('s.sid', 'DESC')
      ->range(0, self::SCAN_LIMIT);
    $i = 0;
    foreach ($values as $name => $value) {
      $alias = 'd' . $i++;
      $query->join('webform_submission_data', $alias, "$alias.sid = s.sid AND $alias.name = :n$i", [":n$i" => $name]);
      $query->condition("$alias.value", $value);
    }
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Loads a submission.
   */
  protected function loadSubmission(int $sid): ?WebformSubmissionInterface {
    $submission = $this->entityTypeManager->getStorage('webform_submission')->load($sid);
    return $submission instanceof WebformSubmissionInterface ? $submission : NULL;
  }

  /**
   * Decrypts a CPR element of the submission.
   */
  protected function reveal(WebformSubmissionInterface $submission, string $key): string {
    $raw = $submission->getElementData($key);
    return is_string($raw) && $raw !== '' ? $this->cprAccess->reveal($raw) : '';
  }

  /**
   * The option label of a select element value.
   */
  protected function optionLabel(WebformSubmissionInterface $submission, string $key): string {
    $value = (string) ($submission->getElementData($key) ?? '');
    $element = $submission->getWebform()?->getElement($key) ?? [];
    $options = is_array($element['#options'] ?? NULL) ? $element['#options'] : [];
    return isset($options[$value]) ? (string) $options[$value] : $value;
  }

  /**
   * Digits only.
   */
  protected static function digits(string $value): string {
    return (string) preg_replace('/\D+/', '', $value);
  }

}
