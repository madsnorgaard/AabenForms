<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Prefill;

/**
 * Legal basis (hjemmel) for prefilling a citizen form from registers.
 *
 * Every value the prefill endpoint hands the SPA is a register lookup about
 * the citizen, so each one must be able to name the statutory basis the
 * municipality relies on (databeskyttelsesforordningen art. 6(1)(e) read
 * together with the sector law that makes the lookup necessary). The map is
 * keyed by webform machine name; a form without an entry falls back to the
 * general administrative-law basis so the response is never silent about
 * why the data was fetched.
 *
 * This is a plain PHP constant rather than config on purpose: the basis is a
 * property of the form's legal purpose, reviewed with the DPIA, not a value
 * an admin should tune per site. Changing it is a code review.
 */
final class LegalBasis {

  /**
   * The fallback basis for forms without a specific entry.
   */
  public const DEFAULT = 'Forvaltningsloven §7, DBF art. 6(1)(e)';

  /**
   * Per-webform legal basis, keyed by webform id.
   */
  public const MAP = [
    'merudgifter' => 'Serviceloven §41, Forvaltningsloven §7, DBF art. 6(1)(e)',
    'school_transfer' => 'Folkeskoleloven §36, forældreansvarsloven §3',
    'klage' => 'Forvaltningsloven §25, Retssikkerhedsloven §66',
    'building_permit' => 'Byggeloven §16',
    'friplads' => 'Dagtilbudsloven §43, Forvaltningsloven §7, DBF art. 6(1)(e)',
    // Beskæftigelseskravet for lønmodtagere (240 timer / 6 måneder).
    'sygedagpenge' => 'Sygedagpengeloven §32, Forvaltningsloven §7, DBF art. 6(1)(e)',
  ];

  /**
   * Returns the legal basis for prefilling the given webform.
   *
   * @param string $webform_id
   *   The webform machine name.
   *
   * @return string
   *   The statutory reference, never empty.
   */
  public static function for(string $webform_id): string {
    return self::MAP[$webform_id] ?? self::DEFAULT;
  }

  /**
   * Whether the webform has an explicitly reviewed legal basis.
   *
   * @param string $webform_id
   *   The webform machine name.
   *
   * @return bool
   *   TRUE when the form has its own entry in the map.
   */
  public static function has(string $webform_id): bool {
    return isset(self::MAP[$webform_id]);
  }

}
