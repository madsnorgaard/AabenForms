<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Family;

/**
 * Looks up family relations and custody (foraeldremyndighed) from CPR data.
 *
 * This interface deliberately isolates the transport: the current
 * implementation speaks Serviceplatformen SF6006 (Familie+ CPR opslag), but
 * CPR services on the shared municipal infrastructure are being phased out
 * towards Datafordeleren (deadline 31/12/2027). Consumers must depend on this
 * interface only, so the migration is a service swap, not a refactor.
 *
 * Custody semantics: a child record is only returned for an adult who is a
 * registered custody holder of that child (guardian type codes in
 * GuardianType). This mirrors the reference behaviour of the OS2 ecosystem's
 * SF1520 extended lookup (os2web_datalookup hasGuardian()).
 *
 * All CPR parameters and return values are plaintext CPR digit strings; the
 * caller is responsible for decrypting stored CPRs (CprAccess) before calling
 * and for never persisting the returned CPRs unencrypted.
 */
interface FamilyRelationsLookupInterface {

  /**
   * Returns the children the given adult holds custody of.
   *
   * @param string $parentCpr
   *   The adult's CPR number (10 digits, no hyphen).
   *
   * @return array[]
   *   A list of child records, each with keys:
   *   - cpr (string): the child's CPR number.
   *   - first_name (string), last_name (string), full_name (string).
   *   - birth_date (string): ISO 8601 date (YYYY-MM-DD).
   *   - protection (bool): TRUE when the child has name/address protection.
   *   - guardians (array[]): all custody holders of this child, each with
   *     keys cpr (string), type (int, GuardianType code), full_name (string),
   *     same_address (bool, whether the guardian shares the child's address).
   *   Children for whom the adult is NOT a registered custody holder are
   *   never included.
   *
   * @throws \Drupal\aabenforms_core\Exception\ServiceplatformenException
   *   When the underlying registry lookup fails.
   */
  public function childrenOf(string $parentCpr): array;

  /**
   * Returns the custody holders of the given child.
   *
   * @param string $childCpr
   *   The child's CPR number (10 digits, no hyphen).
   *
   * @return array[]
   *   A list of guardian records (see childrenOf() guardians key), empty when
   *   the CPR is unknown or has no registered custody holders.
   *
   * @throws \Drupal\aabenforms_core\Exception\ServiceplatformenException
   *   When the underlying registry lookup fails.
   */
  public function guardiansOf(string $childCpr): array;

  /**
   * Whether the adult is a registered custody holder of the child.
   *
   * Fail-closed: implementations must return FALSE when either CPR is
   * unknown, malformed, or the registry cannot confirm custody.
   *
   * @param string $adultCpr
   *   The adult's CPR number.
   * @param string $childCpr
   *   The child's CPR number.
   *
   * @return bool
   *   TRUE only when the registry confirms custody.
   */
  public function hasCustody(string $adultCpr, string $childCpr): bool;

  /**
   * Returns the child's birth date, or NULL when unknown.
   *
   * Used by recipient resolution (Digital Post under-15 rule) so the age
   * boundary is computed from registry data, not parsed out of the CPR
   * number (whose century encoding is ambiguous).
   *
   * @param string $childCpr
   *   The child's CPR number.
   *
   * @return \DateTimeImmutable|null
   *   The birth date, or NULL when the CPR is unknown.
   */
  public function birthDateOf(string $childCpr): ?\DateTimeImmutable;

}
