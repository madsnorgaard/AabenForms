<?php

namespace Drupal\aabenforms_mitid;

/**
 * Canonical set of demo citizens for testing gated flows.
 *
 * These four personas mirror the users carrying a `cpr` attribute in the
 * mock IdP realm (`.ddev/mocks/keycloak/realms/danish-gov-test.json`). They
 * exist so a developer or kommune admin can drive a MitID-gated flow through
 * its happy path without a live MitID/NemLog-in authentication - see the
 * `aabenforms:seed-mitid-session` Drush command and the modeler's
 * "Test as demo citizen" action.
 *
 * This is test/demo scaffolding only. Seeding a session here is gated by the
 * `administer eca` permission on the "Test as demo citizen" route (and by the
 * CLI for the Drush command), never exposed to anonymous citizens, and the
 * resulting session carries the same shape a real MitID callback would produce
 * so downstream actions behave identically.
 */
final class DemoPersonas {

  /**
   * The demo personas, keyed by a short stable slug.
   *
   * The CPRs are synthetic test numbers from the mock realm, not real people.
   */
  private const PERSONAS = [
    'freja' => [
      'cpr' => '0101904521',
      'name' => 'Freja Nielsen',
      'given_name' => 'Freja',
      'family_name' => 'Nielsen',
      'email' => 'freja.nielsen@example.dk',
      'assurance_level' => 'substantial',
    ],
    'mikkel' => [
      'cpr' => '1502856234',
      'name' => 'Mikkel Jensen',
      'given_name' => 'Mikkel',
      'family_name' => 'Jensen',
      'email' => 'mikkel.jensen@example.dk',
      'assurance_level' => 'substantial',
    ],
    'sofie' => [
      'cpr' => '2506924015',
      'name' => 'Sofie Hansen',
      'given_name' => 'Sofie',
      'family_name' => 'Hansen',
      'email' => 'sofie.hansen@example.dk',
      'assurance_level' => 'high',
    ],
    'lars' => [
      'cpr' => '0803755210',
      'name' => 'Lars Andersen',
      'given_name' => 'Lars',
      'family_name' => 'Andersen',
      'email' => 'lars.andersen@example.dk',
      'assurance_level' => 'substantial',
    ],
  ];

  /**
   * Returns all demo personas keyed by slug.
   *
   * @return array[]
   *   Persona definitions keyed by slug.
   */
  public static function all(): array {
    return self::PERSONAS;
  }

  /**
   * Returns the valid persona slugs.
   *
   * @return string[]
   *   The persona keys (e.g. 'freja', 'mikkel').
   */
  public static function keys(): array {
    return array_keys(self::PERSONAS);
  }

  /**
   * Returns whether a slug names a known demo persona.
   *
   * @param string $key
   *   The persona slug.
   *
   * @return bool
   *   TRUE if the persona exists.
   */
  public static function exists(string $key): bool {
    return isset(self::PERSONAS[$key]);
  }

  /**
   * Returns one persona's MitID-session payload, or NULL if unknown.
   *
   * The returned array matches the shape a real MitID callback stores, so the
   * CPR-lookup, audit and Digital Post actions downstream see no difference.
   *
   * @param string $key
   *   The persona slug.
   *
   * @return array|null
   *   The persona's session data, or NULL when the slug is unknown.
   */
  public static function get(string $key): ?array {
    return self::PERSONAS[$key] ?? NULL;
  }

}
