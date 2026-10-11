<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Exception;

/**
 * A submission claims something the verified identity or the register denies.
 *
 * Thrown at intake, before the submission is saved and before any flow runs.
 * The reason is a stable machine code for the audit log and the API response;
 * the message never contains personal data.
 */
final class SubmissionIntegrityException extends \RuntimeException {

  /**
   * The applicant CPR in the submission is not the MitID-verified person.
   */
  public const IDENTITY_MISMATCH = 'identity_mismatch';

  /**
   * The custody claim contradicts the register.
   */
  public const CUSTODY_MISMATCH = 'custody_mismatch';

  /**
   * The child could not be resolved among the applicant's custody children.
   */
  public const CHILD_UNRESOLVED = 'child_unresolved';

  /**
   * On behalf of someone without a covering representation in the session.
   */
  public const REPRESENTATION_DENIED = 'representation_denied';

  /**
   * The register could not be reached, so the claim cannot be verified.
   */
  public const REGISTER_UNAVAILABLE = 'register_unavailable';

  /**
   * Constructs the exception.
   *
   * @param string $reason
   *   One of the reason constants.
   * @param int $httpStatus
   *   The HTTP status the API answers with.
   */
  public function __construct(
    public readonly string $reason,
    public readonly int $httpStatus,
  ) {
    parent::__construct('Submission rejected: ' . $reason);
  }

}
