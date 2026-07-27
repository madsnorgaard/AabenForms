<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Family;

/**
 * CPR guardian (custody holder) relation type codes.
 *
 * The codes follow the CPR registry semantics as exposed through the
 * Serviceplatformen family lookups and mirrored by the OS2 ecosystem
 * (os2web_datalookup): mother = 3, father = 4, other guardian 1 = 5,
 * other guardian 2 = 6.
 */
final class GuardianType {

  public const MOTHER = 3;

  public const FATHER = 4;

  public const OTHER_GUARDIAN_1 = 5;

  public const OTHER_GUARDIAN_2 = 6;

  /**
   * All codes that denote a custody-holding relation.
   *
   * @return int[]
   *   The valid guardian type codes.
   */
  public static function all(): array {
    return [
      self::MOTHER,
      self::FATHER,
      self::OTHER_GUARDIAN_1,
      self::OTHER_GUARDIAN_2,
    ];
  }

  /**
   * Whether a relation type code denotes custody.
   *
   * @param int $type
   *   The relation type code.
   *
   * @return bool
   *   TRUE when the code is a custody-holding relation.
   */
  public static function isCustodial(int $type): bool {
    return in_array($type, self::all(), TRUE);
  }

}
