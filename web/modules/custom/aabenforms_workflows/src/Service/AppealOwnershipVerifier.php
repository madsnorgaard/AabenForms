<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

use Drupal\aabenforms_core\Identity\Representation;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies that the MitID-authenticated appellant is a party to the case.
 *
 * A klage (appeal) must only be lodgeable by someone who is party to the case.
 * The authoritative applicant CPR is the one on the case's ORIGINAL submission
 * (encrypted at rest) - never the self-reported CPR on the klage form. This
 * compares the verified MitID session CPR against that, constant-time, and
 * fails closed on any doubt. Mirrors ParentCprVerifier.
 */
class AppealOwnershipVerifier {

  /**
   * The representation scope an appeal needs (the klage webform id).
   */
  public const SCOPE = 'klage';

  /**
   * The appellant's MitID CPR matches the case applicant.
   */
  public const RESULT_MATCH = 'match';

  /**
   * The appellant acts for the case applicant on a verified representation.
   */
  public const RESULT_MATCH_ON_BEHALF = 'match_on_behalf';

  /**
   * The appellant's MitID CPR does not match the case applicant.
   */
  public const RESULT_MISMATCH = 'mismatch';

  /**
   * The MitID session carries no CPR (upstream IdP failure / no session).
   */
  public const RESULT_MISSING_MITID_CPR = 'missing_mitid_cpr';

  /**
   * The case (or its submission's applicant CPR) could not be resolved.
   */
  public const RESULT_MISSING_EXPECTED_CPR = 'missing_expected_cpr';

  public function __construct(
    protected MitIdSessionManager $sessionManager,
    protected AuditLogger $auditLogger,
    LoggerChannelFactoryInterface $logger_factory,
    protected CprAccess $cprAccess,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->logger = $logger_factory->get('aabenforms_workflows');
  }

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Verifies appeal ownership.
   *
   * @param string $case_id
   *   The case being appealed.
   * @param string $workflow_id
   *   The MitID workflow id; used to read the asserted CPR from the session.
   *
   * @return string
   *   One of the RESULT_* constants.
   */
  public function verify(string $case_id, string $workflow_id): string {
    $asserted = $this->normaliseCpr((string) ($this->sessionManager->getCprFromSession($workflow_id) ?? ''));
    if ($asserted === '') {
      $this->logger->warning('Appeal blocked: MitID session lacks a CPR claim (case @cid, workflow @wid).', [
        '@cid' => $case_id,
        '@wid' => $workflow_id,
      ]);
      $this->auditLogger->logWorkflowAccess($workflow_id, 'appeal_cpr_missing', 'failure', ['case_id' => $case_id]);
      return self::RESULT_MISSING_MITID_CPR;
    }

    $expected = $this->normaliseCpr($this->caseApplicantCpr($case_id));
    if ($expected === '') {
      $this->logger->error('Appeal blocked: case @cid has no resolvable applicant CPR.', ['@cid' => $case_id]);
      $this->auditLogger->logCprLookup($asserted, 'appeal_expected_cpr_missing', 'failure', [
        'case_id' => $case_id,
        'workflow_id' => $workflow_id,
      ]);
      return self::RESULT_MISSING_EXPECTED_CPR;
    }

    if (!hash_equals($expected, $asserted)) {
      // Acting on behalf: re-read from the verified session, never from the
      // form, whether this person holds a representation covering appeals
      // for the case applicant.
      $session = $this->sessionManager->getSession($workflow_id) ?? [];
      $representation = Representation::covering((array) ($session['representations'] ?? []), $expected, self::SCOPE);
      if ($representation !== NULL) {
        $this->auditLogger->logCprLookup($asserted, 'appeal_ownership_verified_on_behalf', 'success', [
          'case_id' => $case_id,
          'workflow_id' => $workflow_id,
          'basis' => $representation['basis'],
        ]);
        return self::RESULT_MATCH_ON_BEHALF;
      }
      $this->logger->warning('Appeal blocked: MitID CPR does not match the case applicant (case @cid).', ['@cid' => $case_id]);
      $this->auditLogger->logCprLookup($asserted, 'appeal_ownership_mismatch', 'failure', [
        'case_id' => $case_id,
        'workflow_id' => $workflow_id,
      ]);
      return self::RESULT_MISMATCH;
    }

    $this->auditLogger->logCprLookup($asserted, 'appeal_ownership_verified', 'success', [
      'case_id' => $case_id,
      'workflow_id' => $workflow_id,
    ]);
    return self::RESULT_MATCH;
  }

  /**
   * Reads and decrypts the applicant CPR from the case's original submission.
   */
  protected function caseApplicantCpr(string $case_id): string {
    $case = $this->entityTypeManager->getStorage('aabenforms_case')->load($case_id);
    if ($case === NULL || !method_exists($case, 'getSubmissionId')) {
      return '';
    }
    $submissionId = $case->getSubmissionId();
    if ($submissionId === NULL) {
      return '';
    }
    $submission = $this->entityTypeManager->getStorage('webform_submission')->load($submissionId);
    if ($submission === NULL || !method_exists($submission, 'getElementData')) {
      return '';
    }
    // The application forms store the citizen CPR as applicant_cpr (or cpr),
    // encrypted at rest.
    $raw = (string) ($submission->getElementData('applicant_cpr') ?? $submission->getElementData('cpr') ?? '');
    return $this->cprAccess->reveal($raw);
  }

  /**
   * Strips non-digits so encrypted vs session CPRs compare cleanly.
   */
  protected function normaliseCpr(string $cpr): string {
    return preg_replace('/[^0-9]/', '', $cpr) ?? '';
  }

}
