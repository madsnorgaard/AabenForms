<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Identity;

use Drupal\aabenforms_core\Exception\SubmissionIntegrityException;
use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Identity\Representation;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformInterface;

/**
 * Binds a submission to the verified identity and the register at intake.
 *
 * What a citizen may claim is decided here, not in the flows: the client is
 * untrusted, so every fact a flow routes or sends on is either taken from
 * the verified MitID session or from the register.
 *  - The first CPR element is the authenticated applicant. An empty or
 *    masked value is filled from the session; a different unmasked CPR is
 *    rejected (and audited), so a recipient can never be steered.
 *  - The child is resolved only among the applicant's own custody children.
 *    The other custody holder always comes from the register.
 *  - Acting on behalf of someone (on_behalf_of): only for a person the
 *    verified session holds a representation for, covering this form. The
 *    represented person becomes the applicant, the authenticated person is
 *    recorded as actor_cpr with the basis. Anything else is rejected.
 *  - custody_status (sole / joint_*) is derived from the register. A sole
 *    claim on joint custody is rejected, so the co-signature cannot be
 *    skipped. Whether the parents agree stays the applicant's statement; the
 *    co-signature verifies it.
 * Rejections throw SubmissionIntegrityException before the submission is
 * saved, so no flow runs on a false claim.
 */
class SubmissionIdentityBinder {

  /**
   * Custody values that state joint custody.
   */
  private const JOINT_VALUES = ['joint_agreed', 'joint_disagreed'];

  public function __construct(
    protected CprAccess $cprAccess,
    protected FamilyRelationsLookupInterface $familyLookup,
    protected AuditLogger $auditLogger,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Binds the submission values to the session CPR and the register.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   * @param string $sessionCpr
   *   The CPR from the verified MitID session (10 digits).
   * @param array $data
   *   The submitted values.
   * @param string $workflowId
   *   The MitID workflow id, for the audit context only.
   * @param array $representations
   *   The verified session's representations (see Representation).
   *
   * @return array
   *   The values with applicant, child, other parent and custody bound.
   *
   * @throws \Drupal\aabenforms_core\Exception\SubmissionIntegrityException
   *   When a claim contradicts the session or the register.
   */
  public function bind(WebformInterface $webform, string $sessionCpr, array $data, string $workflowId, array $representations = []): array {
    $elements = $webform->getElementsDecodedAndFlattened();
    $applicantCpr = $this->bindRepresentation($webform, $elements, $sessionCpr, $data, $workflowId, $representations);
    $data = $this->bindApplicant($webform, $elements, $applicantCpr, $data, $workflowId);
    return $this->bindFamily($webform, $elements, $applicantCpr, $data, $workflowId);
  }

  /**
   * Resolves who the submission is for; records the actor when on behalf.
   *
   * @return string
   *   The applicant (subject) CPR: the session person, or the represented
   *   person when the form allows acting on behalf and the session holds a
   *   representation covering it.
   */
  protected function bindRepresentation(WebformInterface $webform, array $elements, string $sessionCpr, array &$data, string $workflowId, array $representations): string {
    $requested = $data['on_behalf_of'] ?? '';
    // Only forms that declare who acted can be submitted on behalf of anyone.
    if (!isset($elements['on_behalf_of'], $elements['actor_cpr'])) {
      unset($data['on_behalf_of'], $data['actor_cpr'], $data['representation_basis'], $data['representation_copy']);
      return $sessionCpr;
    }
    if ($requested === '' || $requested === NULL) {
      $data['actor_cpr'] = '';
      $data['representation_basis'] = '';
      $data['representation_copy'] = '0';
      return $sessionCpr;
    }
    $representation = Representation::find($representations, $requested, (string) $webform->id());
    if ($representation === NULL) {
      $this->reject(SubmissionIntegrityException::REPRESENTATION_DENIED, 403, $sessionCpr, $webform, $workflowId);
    }
    $data['actor_cpr'] = $sessionCpr;
    $data['representation_basis'] = $representation['basis'];
    // Whether the actor may get copies of letters to the subject is the
    // representation's scope, never the client's choice.
    $data['representation_copy'] = in_array(Representation::SCOPE_POST_COPY, $representation['scopes'], TRUE) ? '1' : '0';
    $this->auditLogger->log('submit_on_behalf', $sessionCpr, (string) $webform->id(), 'success', [
      'basis' => $representation['basis'],
      'subject_hash' => hash('sha256', 'cpr:' . $representation['cpr']),
      'workflow_id' => $workflowId,
    ]);
    return $representation['cpr'];
  }

  /**
   * The first CPR element is always the verified applicant.
   */
  protected function bindApplicant(WebformInterface $webform, array $elements, string $applicantCpr, array $data, string $workflowId): array {
    // Same authority as the presave encryption hook (#172), so the field
    // bound here is always one the encryption covers.
    foreach ($elements as $key => $element) {
      if (!$this->cprAccess->isCprElement($key, $element)) {
        continue;
      }
      $submitted = self::normalize((string) ($data[$key] ?? ''));
      if ($submitted !== '' && !str_contains($submitted, 'X') && $submitted !== $applicantCpr) {
        $this->reject(SubmissionIntegrityException::IDENTITY_MISMATCH, 403, $applicantCpr, $webform, $workflowId);
      }
      $data[$key] = $applicantCpr;
      break;
    }
    return $data;
  }

  /**
   * Resolves child, other parent and custody from the register.
   */
  protected function bindFamily(WebformInterface $webform, array $elements, string $sessionCpr, array $data, string $workflowId): array {
    if (!isset($elements['child_cpr'])) {
      return $data;
    }
    // A form that routes on custody cannot run without the register.
    $custodyGated = isset($elements['custody_status']);
    try {
      $children = $this->familyLookup->childrenOf($sessionCpr);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('aabenforms_core')->error('Family lookup failed at submit: @m', ['@m' => $e->getMessage()]);
      if ($custodyGated) {
        $this->reject(SubmissionIntegrityException::REGISTER_UNAVAILABLE, 503, $sessionCpr, $webform, $workflowId);
      }
      return $data;
    }

    $child = $this->matchChild($children, (string) ($data['child_cpr'] ?? ''), (string) ($data['child_name'] ?? ''));
    if ($child === NULL) {
      if ($custodyGated) {
        $this->reject(SubmissionIntegrityException::CHILD_UNRESOLVED, 422, $sessionCpr, $webform, $workflowId);
      }
      // Left as submitted; the flow's custody gate denies.
      return $data;
    }
    $data['child_cpr'] = $child['cpr'];

    $guardians = $child['guardians'] ?? [];
    if (isset($elements['parent2_cpr'])) {
      $other = array_values(array_filter($guardians, static fn (array $g): bool =>
        ($g['cpr'] ?? '') !== '' && $g['cpr'] !== $sessionCpr));
      $data['parent2_cpr'] = $other[0]['cpr'] ?? '';
    }

    if ($custodyGated) {
      $submitted = (string) ($data['custody_status'] ?? '');
      if (count($guardians) <= 1) {
        $data['custody_status'] = 'sole';
      }
      elseif ($submitted === 'sole') {
        $this->reject(SubmissionIntegrityException::CUSTODY_MISMATCH, 422, $sessionCpr, $webform, $workflowId);
      }
      elseif (!in_array($submitted, self::JOINT_VALUES, TRUE)) {
        // Joint custody and no statement of agreement: do not assume one.
        $data['custody_status'] = 'joint_disagreed';
      }
    }
    return $data;
  }

  /**
   * Finds the submitted child among the applicant's custody children.
   *
   * The SPA only holds masked CPRs (DDMMYY-XXXX): a masked value must match
   * exactly one child by prefix and name. An unmasked value must be one of
   * the children.
   *
   * @return array|null
   *   The child record, or NULL when none or several match.
   */
  protected function matchChild(array $children, string $submittedCpr, string $submittedName): ?array {
    $submitted = self::normalize($submittedCpr);
    $name = mb_strtolower(trim($submittedName));
    if (preg_match('/^\d{10}$/', $submitted)) {
      foreach ($children as $child) {
        if (($child['cpr'] ?? '') === $submitted) {
          return $child;
        }
      }
      return NULL;
    }
    if (preg_match('/^(\d{6})X{4}$/', $submitted, $m)) {
      $candidates = array_values(array_filter($children, static fn (array $c): bool =>
        str_starts_with((string) ($c['cpr'] ?? ''), $m[1])
        && ($name === '' || mb_strtolower((string) ($c['full_name'] ?? '')) === $name)));
      return count($candidates) === 1 ? $candidates[0] : NULL;
    }
    return NULL;
  }

  /**
   * Audits the rejection (hashed CPR, no values) and throws.
   *
   * @throws \Drupal\aabenforms_core\Exception\SubmissionIntegrityException
   */
  protected function reject(string $reason, int $status, string $sessionCpr, WebformInterface $webform, string $workflowId): never {
    $this->auditLogger->log('submit_integrity_rejected', $sessionCpr, (string) $webform->id(), 'failure', [
      'reason' => $reason,
      'workflow_id' => $workflowId,
    ]);
    throw new SubmissionIntegrityException($reason, $status);
  }

  /**
   * Uppercase without spaces and hyphens.
   */
  protected static function normalize(string $value): string {
    return strtoupper(preg_replace('/[\s-]/', '', $value) ?? '');
  }

}
