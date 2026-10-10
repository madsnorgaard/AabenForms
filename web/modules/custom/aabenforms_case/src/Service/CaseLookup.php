<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Service;

use Drupal\aabenforms_case\AabenformsCaseInterface;
use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Finds a citizen's cases by CPR without ever storing or querying the CPR.
 *
 * The case entity references its submission, and the submission's CPR is
 * AES-encrypted at rest (AFENC1/AFENC2 prefix, see CprAccess), so "all cases
 * for this CPR" cannot be answered in SQL against the submission. Instead
 * each case carries subject_cpr_hash, a SHA-256 of the bare 10-digit CPR,
 * populated when the case is opened (OpenCaseAction) and backfilled once by
 * aabenforms_case_update_11002(). The hash is the same function the audit
 * log applies to identifiers, so one hashed CPR can be correlated across
 * both tables during an audit, and it is a lookup key only: the raw CPR is
 * never written to the case and never leaves the submission unencrypted
 * except at a point of use that reveals it on purpose.
 *
 * See AabenformsCase::subjectCprHashFieldDefinition() for the privacy
 * rationale and its honest limits.
 */
class CaseLookup {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CprAccess $cprAccess,
  ) {}

  /**
   * Hashes a CPR the way the case index and the audit log do.
   *
   * @param string $cpr
   *   A CPR in any formatting (digits, with hyphen, encrypted is NOT
   *   accepted here - reveal it first).
   *
   * @return string
   *   The lowercase hex SHA-256 of the bare digits, or '' when the input
   *   does not contain a 10-digit CPR.
   */
  public static function hashCpr(string $cpr): string {
    $digits = preg_replace('/\D/', '', $cpr) ?? '';
    if (strlen($digits) !== 10) {
      return '';
    }
    return hash('sha256', $digits);
  }

  /**
   * Returns the cases whose subject is the given CPR, newest first.
   *
   * @param string $cpr
   *   The plaintext CPR (10 digits, formatting ignored).
   *
   * @return array[]
   *   Case summaries, see summarize(). Empty when the CPR is malformed or
   *   has no cases.
   */
  public function findByCpr(string $cpr): array {
    $hash = self::hashCpr($cpr);
    return $hash === '' ? [] : $this->findByCprHash($hash);
  }

  /**
   * Returns the cases carrying the given subject hash, newest first.
   *
   * @param string $hash
   *   A SHA-256 hex digest as produced by hashCpr().
   *
   * @return array[]
   *   Case summaries, see summarize().
   */
  public function findByCprHash(string $hash): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('aabenforms_case');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('subject_cpr_hash', $hash)
      ->sort('id', 'DESC')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $summaries = [];
    foreach ($storage->loadMultiple($ids) as $case) {
      if ($case instanceof AabenformsCaseInterface) {
        $summaries[] = $this->summarize($case);
      }
    }
    return $summaries;
  }

  /**
   * Builds the citizen-safe summary of a case.
   *
   * Nothing here identifies a third party: title, lifecycle state, decision
   * and deadlines only. The subject hash is deliberately NOT included.
   *
   * @param \Drupal\aabenforms_case\AabenformsCaseInterface $case
   *   The case.
   *
   * @return array
   *   Keys: case_id (int), title, case_type, status, status_label,
   *   afgoerelse_type (string|null), afgoerelse_date (ISO date|null),
   *   klagefrist (ISO date|null), appealable (bool).
   */
  public function summarize(AabenformsCaseInterface $case): array {
    $status = $case->getStatus();
    $afgoerelseType = (string) ($case->get('afgoerelse_type')->value ?? '');
    $klagefrist = $case->get('klagefrist')->value;
    $klagefrist = $klagefrist === NULL ? NULL : (int) $klagefrist;
    $decided = $case->getAfgoerelseDato() ?? $this->decisionDateFromRevisions($case);

    return [
      'case_id' => (int) $case->id(),
      'title' => (string) $case->label(),
      'case_type' => $case->getCaseType(),
      'status' => $status,
      'status_label' => AabenformsCase::statusOptions()[$status] ?? $status,
      'afgoerelse_type' => $afgoerelseType === '' ? NULL : $afgoerelseType,
      'afgoerelse_date' => $decided === NULL ? NULL : $this->isoDate($decided),
      'klagefrist' => $klagefrist === NULL ? NULL : $this->isoDate($klagefrist),
      // Appealable exactly when the lifecycle allows afgoerelse -> paaklaget;
      // the klagefrist itself is enforced by the flow, not hidden here, so a
      // citizen still sees (and can be told about) an expired deadline.
      'appealable' => in_array('paaklaget', AabenformsCase::allowedTransitions()[$status] ?? [], TRUE),
    ];
  }

  /**
   * Computes the subject hash for the submission a case is opened from.
   *
   * The first CPR element on the webform is the applicant (the same rule the
   * MitID prefill at intake and the presave encryption use, #172); any later
   * CPR fields belong to a child or a co-guardian and must never become the
   * case subject.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   *
   * @return string|null
   *   The hash, or NULL when the submission has no usable applicant CPR.
   */
  public function subjectHashFromSubmission(WebformSubmissionInterface $submission): ?string {
    $webform = $submission->getWebform();
    if ($webform === NULL) {
      return NULL;
    }
    foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
      if (!$this->cprAccess->isCprElement($key, $element)) {
        continue;
      }
      $raw = $submission->getElementData($key);
      $cpr = is_string($raw) && $raw !== '' ? $this->cprAccess->reveal($raw) : '';
      $hash = self::hashCpr($cpr);
      return $hash === '' ? NULL : $hash;
    }
    return NULL;
  }

  /**
   * Derives the decision date from the first revision in "afgoerelse".
   *
   * Cases decided before afgoerelse_dato existed (update 11002 backfills
   * them) still have the moment in their revision history.
   */
  protected function decisionDateFromRevisions(AabenformsCaseInterface $case): ?int {
    $storage = $this->entityTypeManager->getStorage('aabenforms_case');
    $vids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $case->id())
      ->condition('status', 'afgoerelse')
      ->sort('vid', 'ASC')
      ->range(0, 1)
      ->execute();
    if ($vids === []) {
      return NULL;
    }
    $revision = $storage->loadRevision((int) array_key_first($vids));
    if ($revision instanceof AabenformsCaseInterface) {
      $created = $revision->getRevisionCreationTime();
      return $created === NULL ? NULL : (int) $created;
    }
    return NULL;
  }

  /**
   * Formats a timestamp as a Copenhagen-local ISO date (YYYY-MM-DD).
   */
  protected function isoDate(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format('Y-m-d');
  }

}
