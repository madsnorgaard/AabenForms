<?php

namespace Drupal\aabenforms_mitid;

/**
 * Canonical set of demo citizens for testing gated flows.
 *
 * Freja, Mikkel, Sofie, Lars and Birthe mirror the users carrying a `cpr`
 * attribute in the mock IdP realm
 * (`.ddev/mocks/keycloak/realms/danish-gov-test.json`), address claims
 * included. Birthe is the digital-inclusion persona (elderly, Digital Post
 * exempt, helped by adult son Mikkel). They all exist so a developer or
 * kommune admin can drive a MitID-gated flow through
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
   * The CPRs are synthetic test numbers, not real people. The address keys
   * (street, postal_code, city, municipality_code) are the same claims the
   * mock realm issues, so a seeded session and a real mock login prefill the
   * same address. Lars has no address on purpose: Lars is the separated
   * co-guardian in DemoFamilyRepository and exercises the "no address claim,
   * fall back to manual entry" branch.
   *
   * `digital_post_exempt` marks a citizen who is fritaget for Digital Post
   * (bekendtgoerelse om fritagelse af fysiske personer fra tilslutning til
   * Offentlig Digital Post). The flag rides along into the seeded session so
   * a flow can route Birthe's decision letters to physical post. It is a
   * demo attribute (the mock realm issues it as a claim, see
   * MitIdCprExtractor): a real MitID login carries no such claim, the
   * authority looks it up in the Digital Post kontaktregister (SF1601
   * fritagelse).
   */
  private const PERSONAS = [
    'freja' => [
      'cpr' => '0101904521',
      'name' => 'Freja Nielsen',
      'given_name' => 'Freja',
      'family_name' => 'Nielsen',
      'email' => 'freja.nielsen@example.dk',
      'birthdate' => '1990-01-01',
      'street' => 'Nørrebrogade 142, 3. tv.',
      'postal_code' => '2200',
      'city' => 'København N',
      'municipality_code' => '0101',
      'assurance_level' => 'substantial',
    ],
    'mikkel' => [
      'cpr' => '1502856234',
      'name' => 'Mikkel Jensen',
      'given_name' => 'Mikkel',
      'family_name' => 'Jensen',
      'email' => 'mikkel.jensen@example.dk',
      'birthdate' => '1985-02-15',
      'street' => 'Vestergade 27, 2. th.',
      'postal_code' => '8000',
      'city' => 'Aarhus C',
      'municipality_code' => '0751',
      'assurance_level' => 'substantial',
      // Digital fuldmagt from Birthe (the parent), covering appeals (klage)
      // and copies of the letters to Birthe. Mirrors the realm's `fuldmagt`
      // attribute.
      'representations' => [
        [
          'cpr' => '2403482210',
          'name' => 'Birthe Jensen',
          'basis' => 'fuldmagt',
          'scopes' => ['klage', 'digital_post_copy'],
        ],
      ],
    ],
    'sofie' => [
      'cpr' => '2506924015',
      'name' => 'Sofie Hansen',
      'given_name' => 'Sofie',
      'family_name' => 'Hansen',
      'email' => 'sofie.hansen@example.dk',
      'birthdate' => '1992-06-25',
      'street' => 'Hjallesevej 88',
      'postal_code' => '5230',
      'city' => 'Odense M',
      'municipality_code' => '0461',
      'assurance_level' => 'high',
    ],
    'lars' => [
      'cpr' => '0803755210',
      'name' => 'Lars Andersen',
      'given_name' => 'Lars',
      'family_name' => 'Andersen',
      'email' => 'lars.andersen@example.dk',
      'birthdate' => '1975-03-08',
      'assurance_level' => 'substantial',
    ],
    // Digital inclusion persona: 78 years old, exempt from Digital Post,
    // parent of Mikkel (see DemoFamilyRepository::adultChildrenOf()).
    // Decision letters go by physical post regardless of the email.
    'birthe' => [
      'cpr' => '2403482210',
      'name' => 'Birthe Jensen',
      'given_name' => 'Birthe',
      'family_name' => 'Jensen',
      'email' => 'birthe.jensen@example.dk',
      'birthdate' => '1948-03-24',
      'street' => 'Skovvejen 12, st. tv.',
      'postal_code' => '8000',
      'city' => 'Aarhus C',
      'municipality_code' => '0751',
      'assurance_level' => 'substantial',
      'digital_post_exempt' => TRUE,
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
