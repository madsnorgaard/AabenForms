<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Service;

/**
 * Household income lookup on the demo rail (stand-in for eIndkomst).
 *
 * The one place the demo derivation lives, shared by the
 * aabenforms_case_income_lookup ECA action (friplads / merudgifter flows) and
 * the prefill endpoint, so both report the same figure for the same citizen.
 * Production replaces this service with a Serviceplatformen eIndkomst
 * client (SKAT's indkomstregister, accessed under the kommune's data
 * agreement); until then isDemoMode() is always TRUE and every consumer
 * must label the figure as demo data, never as a register answer.
 */
class DemoIncomeLookup {

  /**
   * The contract the production implementation would speak.
   */
  public const CONTRACT = 'eIndkomst';

  /**
   * Annual household income in the low (eligible) band, in DKK.
   */
  public const LOW_BAND = 150000;

  /**
   * Annual household income in the high (manual review) band, in DKK.
   */
  public const HIGH_BAND = 400000;

  /**
   * Returns the annual household income for a CPR, in DKK.
   *
   * Deterministic per CPR so demos and tests are repeatable: a last digit
   * below 5 lands in the low band, otherwise the high band.
   *
   * @param string $cpr
   *   The plaintext CPR (formatting ignored).
   *
   * @return int|null
   *   The income in DKK, or NULL when no CPR digits were given.
   */
  public function lookup(string $cpr): ?int {
    $digits = preg_replace('/\D/', '', $cpr) ?? '';
    if ($digits === '') {
      return NULL;
    }
    $lastDigit = (int) substr($digits, -1);
    return $lastDigit < 5 ? self::LOW_BAND : self::HIGH_BAND;
  }

  /**
   * Whether answers come from the demo derivation, not the register.
   *
   * @return bool
   *   Always TRUE for this implementation.
   */
  public function isDemoMode(): bool {
    return TRUE;
  }

}
