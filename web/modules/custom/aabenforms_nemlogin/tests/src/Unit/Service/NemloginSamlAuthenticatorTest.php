<?php

namespace Drupal\Tests\aabenforms_nemlogin\Unit\Service;

use Drupal\aabenforms_core\Identity\SessionManagerInterface;
use Drupal\aabenforms_core\Identity\VerifiedIdentity;
use Drupal\aabenforms_nemlogin\Service\NemloginSamlAuthenticator;
use Drupal\aabenforms_nemlogin\Service\NemloginSettingsBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the NemLog-in OIOSAML 3 attribute-to-identity mapping and gating.
 *
 * @coversDefaultClass \Drupal\aabenforms_nemlogin\Service\NemloginSamlAuthenticator
 * @group aabenforms_nemlogin
 * @group identity
 */
class NemloginSamlAuthenticatorTest extends UnitTestCase {

  /**
   * The mocked session store.
   *
   * @var \Drupal\aabenforms_core\Identity\SessionManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $sessionManager;

  /**
   * The authenticator under test.
   *
   * @var \Drupal\aabenforms_nemlogin\Service\NemloginSamlAuthenticator
   */
  protected NemloginSamlAuthenticator $authenticator;

  /**
   * Builds the authenticator with a configurable required assurance level.
   *
   * @param string $requiredLevel
   *   The configured minimum NSIS level.
   */
  protected function makeAuthenticator(string $requiredLevel = 'substantial'): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['idp_entity_id', 'https://saml.nemlog-in.dk'],
      ['required_assurance_level', $requiredLevel],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('aabenforms_nemlogin.settings')->willReturn($config);

    $channel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($channel);

    $this->sessionManager = $this->createMock(SessionManagerInterface::class);
    $settingsBuilder = $this->createMock(NemloginSettingsBuilder::class);

    $this->authenticator = new NemloginSamlAuthenticator(
      $this->sessionManager,
      $settingsBuilder,
      $configFactory,
      $loggerFactory,
    );
  }

  /**
   * A full OIOSAML 3 citizen assertion maps to a complete VerifiedIdentity.
   *
   * @covers ::buildIdentity
   * @covers ::resolveAssuranceLevel
   */
  public function testBuildIdentityFromFullAssertion(): void {
    $this->makeAuthenticator();
    $identity = $this->authenticator->buildIdentity(
      $this->citizenAttributes(),
      'https://data.gov.dk/model/core/eid/person/uuid/7d9c-abc',
    );

    $this->assertSame('0101801234', $identity->cpr);
    $this->assertSame('substantial', $identity->assuranceLevel);
    $this->assertSame('nemlogin_saml', $identity->provider);
    $this->assertSame('https://data.gov.dk/model/core/eid/person/uuid/7d9c-abc', $identity->subject);
    $this->assertSame('Anders And', $identity->name);
    $this->assertSame('Anders', $identity->givenName);
    $this->assertSame('And', $identity->familyName);
    $this->assertSame('anders@example.dk', $identity->email);
    $this->assertSame('https://saml.nemlog-in.dk', $identity->issuer);
  }

  /**
   * The NSIS LoA value URI is reduced to its bare level word.
   *
   * @covers ::resolveAssuranceLevel
   * @dataProvider loaProvider
   */
  public function testResolveAssuranceLevel(array $attributes, string $expected): void {
    $this->makeAuthenticator();
    $this->assertSame($expected, $this->authenticator->resolveAssuranceLevel($attributes));
  }

  /**
   * Data provider for assurance resolution across both schemes.
   *
   * @return array
   *   Rows of [attribute map, expected level].
   */
  public static function loaProvider(): array {
    $loa = NemloginSamlAuthenticator::ATTR_NSIS_LOA;
    $legacy = NemloginSamlAuthenticator::ATTR_LEGACY_ASSURANCE;
    return [
      'nsis high uri' => [[$loa => ['https://data.gov.dk/concept/core/nsis/loa/High']], 'high'],
      'nsis substantial uri' => [[$loa => ['https://data.gov.dk/concept/core/nsis/loa/Substantial']], 'substantial'],
      'nsis low uri' => [[$loa => ['https://data.gov.dk/concept/core/nsis/loa/Low']], 'low'],
      'legacy 4 is high' => [[$legacy => ['4']], 'high'],
      'legacy 3 is substantial' => [[$legacy => ['3']], 'substantial'],
      'legacy 2 is low' => [[$legacy => ['2']], 'low'],
      'nothing is unknown (closed)' => [[], 'unknown'],
      'garbage is unknown (closed)' => [[$loa => ['nonsense']], 'unknown'],
    ];
  }

  /**
   * A Substantial login satisfies a Substantial requirement and is stored.
   *
   * @covers ::completeLogin
   */
  public function testCompleteLoginStoresWhenAssuranceMet(): void {
    $this->makeAuthenticator('substantial');
    $this->sessionManager->expects($this->once())
      ->method('storeSession')
      ->with('wf_abc', $this->callback(fn ($data) => ($data['cpr'] ?? NULL) === '0101801234'))
      ->willReturn(TRUE);

    $identity = $this->authenticator->buildIdentity($this->citizenAttributes(), 'sub');
    $result = $this->authenticator->completeLogin($identity, 'wf_abc');
    $this->assertSame('0101801234', $result->cpr);
  }

  /**
   * A Substantial login fails closed against a High requirement.
   *
   * @covers ::completeLogin
   */
  public function testCompleteLoginRejectsBelowRequired(): void {
    $this->makeAuthenticator('high');
    $this->sessionManager->expects($this->never())->method('storeSession');

    $identity = $this->authenticator->buildIdentity($this->citizenAttributes(), 'sub');
    $this->expectException(\RuntimeException::class);
    $this->authenticator->completeLogin($identity, 'wf_abc');
  }

  /**
   * A name-protected citizen (N/A names, alias pseudonym) yields no name.
   *
   * @covers ::buildIdentity
   */
  public function testNameProtectedCitizen(): void {
    $this->makeAuthenticator();
    $attributes = [
      NemloginSamlAuthenticator::ATTR_CPR => ['0101801234'],
      NemloginSamlAuthenticator::ATTR_NSIS_LOA => ['https://data.gov.dk/concept/core/nsis/loa/Substantial'],
      NemloginSamlAuthenticator::ATTR_FULL_NAME => ['N/A'],
      NemloginSamlAuthenticator::ATTR_FIRST_NAME => ['N/A'],
      NemloginSamlAuthenticator::ATTR_LAST_NAME => ['N/A'],
      NemloginSamlAuthenticator::ATTR_ALIAS => ['Pseudonym'],
    ];
    $identity = $this->authenticator->buildIdentity($attributes, 'sub');
    $this->assertSame('0101801234', $identity->cpr);
    $this->assertNull($identity->name);
    $this->assertNull($identity->givenName);
  }

  /**
   * A representative NemLog-in citizen getAttributes() payload.
   *
   * @return array
   *   The attribute map (attribute Name => list of string values).
   */
  protected function citizenAttributes(): array {
    return [
      NemloginSamlAuthenticator::ATTR_CPR => ['0101801234'],
      NemloginSamlAuthenticator::ATTR_NSIS_LOA => ['https://data.gov.dk/concept/core/nsis/loa/Substantial'],
      NemloginSamlAuthenticator::ATTR_FULL_NAME => ['Anders And'],
      NemloginSamlAuthenticator::ATTR_FIRST_NAME => ['Anders'],
      NemloginSamlAuthenticator::ATTR_LAST_NAME => ['And'],
      NemloginSamlAuthenticator::ATTR_EMAIL => ['anders@example.dk'],
      NemloginSamlAuthenticator::ATTR_DOB => ['1980-01-01'],
    ];
  }

}
