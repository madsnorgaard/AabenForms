<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies that the MitID-asserted CPR matches the consenting parent.
 *
 * The parent-approval flow sends a signed token to a parent's email; the
 * parent then logs in with MitID. Without this gate, any holder of the
 * approval URL can authenticate with any MitID account and approve the
 * submission. The gate compares the MitID-asserted CPR against the
 * parent_<N>_cpr field captured on the original submission and rejects
 * the approval when they differ.
 *
 * Three outcomes are surfaced as constants so the controller can render
 * citizen-meaningful UX for each:
 * - RESULT_MATCH: CPRs are equal after digit-only normalisation.
 * - RESULT_MISMATCH: both CPRs present, not equal - security failure.
 * - RESULT_MISSING_MITID_CPR: MitID session lacks a CPR claim entirely.
 *
 * "Token expired/malformed/tampered" is NOT this service's concern; it is
 * already handled by ApprovalTokenService upstream.
 */
class ParentCprVerifier {

  /**
   * The CPRs match (normalised, constant-time compared).
   */
  public const RESULT_MATCH = 'match';

  /**
   * Both CPRs are known but they do not match - security failure.
   */
  public const RESULT_MISMATCH = 'mismatch';

  /**
   * The MitID session does not carry a CPR claim - upstream IdP failure.
   */
  public const RESULT_MISSING_MITID_CPR = 'missing_mitid_cpr';

  /**
   * The submission is missing the parent_<N>_cpr field for this parent.
   *
   * Surfaces as a configuration error - the form schema does not carry the
   * expected CPR so we cannot enforce the gate. Treated as a failure.
   */
  public const RESULT_MISSING_EXPECTED_CPR = 'missing_expected_cpr';

  /**
   * The CPRs match, but the approver holds no registered custody.
   *
   * Only returned for forms listed in the custody_gated_forms setting whose
   * submission carries a child_cpr field: the approver proved they are the
   * person named on the form, but the CPR registry does not list them as a
   * custody holder of the child the request concerns. Fail-closed security
   * outcome (FOB 2025-9 territory: school decisions require the actual
   * custody holders).
   */
  public const RESULT_NO_CUSTODY = 'no_custody';

  /**
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $sessionManager;

  /**
   * The audit logger.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger
   */
  protected AuditLogger $auditLogger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * Constructs a ParentCprVerifier.
   *
   * @param \Drupal\aabenforms_mitid\Service\MitIdSessionManager $session_manager
   *   The MitID session manager.
   * @param \Drupal\aabenforms_core\Service\AuditLogger $audit_logger
   *   The audit logger - records mismatch / missing-claim events.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\aabenforms_core\Service\CprAccess $cpr_access
   *   The CPR access helper, used to decrypt the stored parent CPR.
   * @param \Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface $familyLookup
   *   The family/custody registry lookup (custody gate).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (custody_gated_forms setting).
   */
  public function __construct(
    MitIdSessionManager $session_manager,
    AuditLogger $audit_logger,
    LoggerChannelFactoryInterface $logger_factory,
    CprAccess $cpr_access,
    protected FamilyRelationsLookupInterface $familyLookup,
    protected ConfigFactoryInterface $configFactory,
  ) {
    $this->sessionManager = $session_manager;
    $this->auditLogger = $audit_logger;
    $this->logger = $logger_factory->get('aabenforms_workflows');
    $this->cprAccess = $cpr_access;
  }

  /**
   * Verifies the MitID-asserted CPR against the submission's parent CPR.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission carrying parent_<N>_cpr fields.
   * @param int $parent_number
   *   The parent number (1 or 2).
   * @param string $workflow_id
   *   The MitID workflow ID; used to read the asserted CPR from the session.
   *
   * @return string
   *   One of the RESULT_* constants on this class.
   */
  public function verify(WebformSubmissionInterface $submission, int $parent_number, string $workflow_id): string {
    // The stored parent CPR is encrypted at rest; decrypt it before
    // comparing against the (plaintext) MitID-asserted CPR.
    $expected_raw = $this->cprAccess->reveal((string) ($submission->getElementData("parent{$parent_number}_cpr") ?? ''));
    $asserted_raw = (string) ($this->sessionManager->getCprFromSession($workflow_id) ?? '');

    $expected = $this->normaliseCpr($expected_raw);
    $asserted = $this->normaliseCpr($asserted_raw);

    $submission_id = (int) $submission->id();
    $submission_uuid = (string) ($submission->uuid() ?? '');

    if ($asserted === '') {
      $this->logger->warning(
        'Parent approval blocked: MitID session lacks CPR claim (submission @sid, parent @parent, workflow @wid)',
        [
          '@sid' => $submission_id,
          '@parent' => $parent_number,
          '@wid' => $workflow_id,
        ]
      );
      // Use logWorkflowAccess() - there is no CPR to hash here; the event
      // is an IdP failure attached to the workflow, not a CPR lookup.
      $this->auditLogger->logWorkflowAccess(
        $workflow_id,
        'parent_approval_cpr_missing',
        'failure',
        [
          'submission_uuid' => $submission_uuid,
          'parent_number' => $parent_number,
        ]
      );
      return self::RESULT_MISSING_MITID_CPR;
    }

    if ($expected === '') {
      $this->logger->error(
        'Parent approval blocked: submission has no parent@parent_cpr field (submission @sid)',
        [
          '@parent' => $parent_number,
          '@sid' => $submission_id,
        ]
      );
      $this->auditLogger->logCprLookup(
        $asserted,
        'parent_approval_cpr_missing_expected',
        'failure',
        [
          'submission_uuid' => $submission_uuid,
          'parent_number' => $parent_number,
          'workflow_id' => $workflow_id,
        ]
      );
      return self::RESULT_MISSING_EXPECTED_CPR;
    }

    if (!hash_equals($expected, $asserted)) {
      $this->logger->warning(
        'Parent approval blocked: MitID CPR does not match parent@parent on submission @sid',
        [
          '@parent' => $parent_number,
          '@sid' => $submission_id,
        ]
      );
      // Hash both CPRs so the audit row never stores the raw values; the
      // hashes still let an investigator confirm "the same wrong CPR is
      // being tried repeatedly" without exposing either one.
      $this->auditLogger->logCprLookup(
        $asserted,
        'parent_approval_cpr_mismatch',
        'failure',
        [
          'submission_uuid' => $submission_uuid,
          'parent_number' => $parent_number,
          'workflow_id' => $workflow_id,
          'expected_hash' => hash('sha256', $expected),
          'asserted_hash' => hash('sha256', $asserted),
        ]
      );
      return self::RESULT_MISMATCH;
    }

    // Custody gate (opt-in per form): the CPR match proves "the approver is
    // the person named on the form"; for custody-gated forms we additionally
    // require "the approver holds registered custody of the child". Only
    // enforced when the form is listed in custody_gated_forms AND the
    // submission carries a child_cpr - so existing flows are untouched.
    $custodyResult = $this->verifyCustody($submission, $asserted, $parent_number, $workflow_id);
    if ($custodyResult !== NULL) {
      return $custodyResult;
    }

    $this->logger->info(
      'Parent approval CPR verified for submission @sid, parent @parent',
      [
        '@sid' => $submission_id,
        '@parent' => $parent_number,
      ]
    );
    $this->auditLogger->logCprLookup(
      $asserted,
      'parent_approval_cpr_match',
      'success',
      [
        'submission_uuid' => $submission_uuid,
        'parent_number' => $parent_number,
        'workflow_id' => $workflow_id,
      ]
    );
    return self::RESULT_MATCH;
  }

  /**
   * Runs the registry custody check for custody-gated forms.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission being approved.
   * @param string $assertedCpr
   *   The normalised MitID-asserted CPR.
   * @param int $parent_number
   *   The parent number (audit context).
   * @param string $workflow_id
   *   The MitID workflow id (audit context).
   *
   * @return string|null
   *   RESULT_NO_CUSTODY when the gate applies and fails, NULL when the gate
   *   does not apply or passes (verification continues to RESULT_MATCH).
   */
  protected function verifyCustody(WebformSubmissionInterface $submission, string $assertedCpr, int $parent_number, string $workflow_id): ?string {
    $gatedForms = $this->configFactory->get('aabenforms_workflows.settings')->get('custody_gated_forms') ?? [];
    if ($gatedForms === []) {
      return NULL;
    }
    $webformId = (string) $submission->getWebform()->id();
    if (!in_array($webformId, $gatedForms, TRUE)) {
      return NULL;
    }

    $childCpr = $this->normaliseCpr($this->cprAccess->reveal((string) ($submission->getElementData('child_cpr') ?? '')));
    if ($childCpr === '') {
      // Gated form without a child CPR: nothing to check against. The form
      // schema decides whether child_cpr is required; the gate only enforces
      // custody when a child is actually identified.
      return NULL;
    }

    if ($this->familyLookup->hasCustody($assertedCpr, $childCpr)) {
      $this->auditLogger->logCprLookup(
        $assertedCpr,
        'parent_approval_custody_confirmed',
        'success',
        [
          'submission_uuid' => (string) ($submission->uuid() ?? ''),
          'parent_number' => $parent_number,
          'workflow_id' => $workflow_id,
        ]
      );
      return NULL;
    }

    $this->logger->warning(
      'Parent approval blocked: no registered custody for submission @sid, parent @parent',
      [
        '@sid' => (int) $submission->id(),
        '@parent' => $parent_number,
      ]
    );
    $this->auditLogger->logCprLookup(
      $assertedCpr,
      'parent_approval_no_custody',
      'failure',
      [
        'submission_uuid' => (string) ($submission->uuid() ?? ''),
        'parent_number' => $parent_number,
        'workflow_id' => $workflow_id,
        'child_cpr_hash' => hash('sha256', $childCpr),
      ]
    );
    return self::RESULT_NO_CUSTODY;
  }

  /**
   * Normalises a CPR string to digits only.
   *
   * Strips hyphens, whitespace and any other separators so a hyphenated
   * "010170-1234" compares equal to a digits-only "0101701234". Leading
   * zeros are preserved (CPRs starting with '0101...' are valid and must
   * not be coerced to integer).
   *
   * @param string $cpr
   *   The raw CPR string.
   *
   * @return string
   *   Digit-only CPR. Empty string if no digits were present.
   */
  public function normaliseCpr(string $cpr): string {
    return (string) preg_replace('/[^0-9]/', '', $cpr);
  }

}
