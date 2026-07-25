<?php

namespace Drupal\Tests\aabenforms_core\Unit\Identity;

use Drupal\aabenforms_core\Identity\VerifiedIdentity;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the VerifiedIdentity value object.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Identity\VerifiedIdentity
 * @group aabenforms_core
 * @group identity
 */
class VerifiedIdentityTest extends UnitTestCase {

  /**
   * The session-array round trip preserves every populated field.
   *
   * @covers ::toSessionArray
   * @covers ::fromSessionArray
   */
  public function testSessionRoundTrip(): void {
    $identity = new VerifiedIdentity(
      cpr: '0101801234',
      assuranceLevel: 'substantial',
      provider: 'nemlogin_saml',
      subject: 'https://data.gov.dk/model/core/eid/person/uuid/abc',
      name: 'Anders And',
      givenName: 'Anders',
      familyName: 'And',
      birthdate: '1980-01-01',
      email: 'anders@example.dk',
      issuer: 'https://saml.nemlog-in.dk',
      authTime: 1706356800,
    );

    $session = $identity->toSessionArray();
    $this->assertSame('0101801234', $session['cpr']);
    $this->assertSame('substantial', $session['assurance_level']);
    $this->assertSame('nemlogin_saml', $session['identity_provider']);
    // The subject is stored under the legacy key downstream readers use.
    $this->assertSame('https://data.gov.dk/model/core/eid/person/uuid/abc', $session['mitid_uuid']);
    $this->assertSame('Anders And', $session['name']);
    $this->assertSame(1706356800, $session['authenticated_at']);

    $restored = VerifiedIdentity::fromSessionArray($session);
    $this->assertSame($identity->cpr, $restored->cpr);
    $this->assertSame($identity->assuranceLevel, $restored->assuranceLevel);
    $this->assertSame($identity->provider, $restored->provider);
    $this->assertSame($identity->subject, $restored->subject);
    $this->assertSame($identity->email, $restored->email);
    $this->assertSame($identity->authTime, $restored->authTime);
  }

  /**
   * Absent person fields are omitted, never stamped as empty strings.
   *
   * A real MitID/NemLog-in login issues no name/address claims; the session
   * must not carry empty values that would overwrite data resolved elsewhere.
   *
   * @covers ::toSessionArray
   */
  public function testMinimalIdentityOmitsEmptyFields(): void {
    $identity = new VerifiedIdentity(
      cpr: '0101801234',
      assuranceLevel: 'high',
      provider: 'mitid_oidc',
    );
    $session = $identity->toSessionArray();

    $this->assertArrayHasKey('cpr', $session);
    $this->assertArrayNotHasKey('name', $session);
    $this->assertArrayNotHasKey('mitid_uuid', $session);
    $this->assertArrayNotHasKey('email', $session);
    $this->assertArrayNotHasKey('authenticated_at', $session);
  }

  /**
   * Assurance ranking is ordered and fails closed on unknown levels.
   *
   * @covers ::assuranceRank
   * @covers ::meetsAssurance
   * @dataProvider assuranceProvider
   */
  public function testAssurance(string $level, string $required, bool $expected): void {
    $identity = new VerifiedIdentity(
      cpr: '0101801234',
      assuranceLevel: $level,
      provider: 'nemlogin_saml',
    );
    $this->assertSame($expected, $identity->meetsAssurance($required));
  }

  /**
   * Data provider for assurance comparisons.
   *
   * @return array
   *   Rows of [asserted level, required level, expected meets-requirement].
   */
  public static function assuranceProvider(): array {
    return [
      'substantial meets substantial' => ['substantial', 'substantial', TRUE],
      'high meets substantial' => ['high', 'substantial', TRUE],
      'low fails substantial' => ['low', 'substantial', FALSE],
      'unknown fails substantial (closed)' => ['garbage', 'substantial', FALSE],
      'substantial fails high' => ['substantial', 'high', FALSE],
      'low meets no requirement' => ['low', '', TRUE],
      'case-insensitive' => ['Substantial', 'SUBSTANTIAL', TRUE],
    ];
  }

}
