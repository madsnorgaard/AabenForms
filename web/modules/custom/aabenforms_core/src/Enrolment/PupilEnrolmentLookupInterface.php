<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Enrolment;

/**
 * Looks up which school a pupil is enrolled at (STIL elevdata).
 *
 * The public sector already knows which school a child attends: STIL
 * (Styrelsen for IT og Læring) holds pupil enrolment data. Forms must not ask
 * the citizen for it. No STIL integration is established yet, so the only
 * implementation is DemoPupilEnrolment; consumers depend on this interface so
 * a real STIL client is a service swap.
 *
 * The child CPR is plaintext (10 digits, no hyphen). Implementations must
 * never log or return it unmasked; callers own auditing of every lookup.
 */
interface PupilEnrolmentLookupInterface {

  /**
   * Returns the child's current school, or NULL when not enrolled/unknown.
   *
   * @param string $childCpr
   *   The child's CPR number.
   *
   * @return array|null
   *   Keys: institution_number (string, 6 digits), name (string), and
   *   optionally grade (string, e.g. "2. klasse").
   */
  public function currentInstitution(string $childCpr): ?array;

  /**
   * Whether answers come from demo data rather than STIL.
   *
   * Consumers MUST label outputs as demo when this is TRUE.
   *
   * @return bool
   *   TRUE when demo data is in use.
   */
  public function isDemoMode(): bool;

}
