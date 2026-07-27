<?php

namespace Drupal\Tests\aabenforms_core\Unit\Family;

use Drupal\aabenforms_core\Exception\ServiceplatformenException;
use Drupal\aabenforms_core\Family\DemoFamilyRepository;
use Drupal\aabenforms_core\Family\Sf6006FamilyLookup;
use Drupal\aabenforms_core\Service\ServiceplatformenClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the SF6006 family lookup service.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Family\Sf6006FamilyLookup
 * @group aabenforms_core
 */
class Sf6006FamilyLookupTest extends UnitTestCase {

  /**
   * Mock Serviceplatformen client.
   *
   * @var \Drupal\aabenforms_core\Service\ServiceplatformenClient|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $client;

  /**
   * Certificate config served to the service; empty means demo mode.
   *
   * @var array
   */
  protected array $certificates = [];

  /**
   * The service under test.
   */
  protected Sf6006FamilyLookup $lookup;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->client = $this->createMock(ServiceplatformenClient::class);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      fn ($key) => $key === 'serviceplatformen.certificates' ? $this->certificates : NULL
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $this->lookup = new Sf6006FamilyLookup(
      $this->client,
      $configFactory,
      new DemoFamilyRepository(),
      $loggerFactory,
    );
  }

  /**
   * Without certificates the service answers from demo data, never SOAP.
   *
   * @covers ::childrenOf
   * @covers ::guardiansOf
   */
  public function testDemoModeAnswersFromDemoDataWithoutSoapCall(): void {
    $this->certificates = [];
    $this->client->expects($this->never())->method('request');

    $children = $this->lookup->childrenOf('0101904521');
    $this->assertCount(2, $children);

    $guardians = $this->lookup->guardiansOf('0109182345');
    $this->assertCount(2, $guardians);
  }

  /**
   * With certificates the service calls SF6006 and filters custody.
   *
   * @covers ::childrenOf
   */
  public function testLiveModeFiltersChildrenToCustodyHolders(): void {
    $this->certificates = ['cert_path' => '/cert.pem', 'key_path' => '/key.pem'];

    $this->client->expects($this->once())
      ->method('request')
      ->with('SF6006', 'FamilyLookup', ['cpr' => '0101904521'])
      ->willReturn([
        'children' => [
          [
            'cpr' => '0109182345',
            'full_name' => 'Emil Nielsen Andersen',
            'guardians' => [
              ['cpr' => '0101904521', 'type' => 3],
              ['cpr' => '0803755210', 'type' => 4],
            ],
          ],
          [
            // A child record where the requester is NOT a custody holder
            // must be filtered out.
            'cpr' => '1111111111',
            'full_name' => 'Other Child',
            'guardians' => [
              ['cpr' => '2222222222', 'type' => 3],
            ],
          ],
        ],
      ]);

    $children = $this->lookup->childrenOf('0101904521');
    $this->assertCount(1, $children);
    $this->assertSame('0109182345', $children[0]['cpr']);
  }

  /**
   * Custody verification is fail-closed on registry errors.
   *
   * @covers ::hasCustody
   */
  public function testHasCustodyFailsClosedOnRegistryError(): void {
    $this->certificates = ['cert_path' => '/cert.pem', 'key_path' => '/key.pem'];

    $this->client->method('request')
      ->willThrowException(new ServiceplatformenException('down', 'SF6006', 'FamilyLookup', retryable: FALSE, code: 500));

    $this->assertFalse($this->lookup->hasCustody('0101904521', '0109182345'));
  }

  /**
   * Custody verification confirms only registered custody holders.
   *
   * @covers ::hasCustody
   */
  public function testHasCustodyAgainstDemoData(): void {
    $this->certificates = [];

    // Freja and Lars both hold custody of Emil; Sofie and Mikkel do not.
    $this->assertTrue($this->lookup->hasCustody('0101904521', '0109182345'));
    $this->assertTrue($this->lookup->hasCustody('0803755210', '0109182345'));
    $this->assertFalse($this->lookup->hasCustody('2506924015', '0109182345'));
    $this->assertFalse($this->lookup->hasCustody('1502856234', '0109182345'));
  }

  /**
   * Malformed CPR input fails closed and returns empty results.
   *
   * @covers ::childrenOf
   * @covers ::hasCustody
   * @covers ::birthDateOf
   */
  public function testMalformedInputFailsClosed(): void {
    $this->certificates = [];
    $this->assertSame([], $this->lookup->childrenOf('12345'));
    $this->assertFalse($this->lookup->hasCustody('', '0109182345'));
    $this->assertFalse($this->lookup->hasCustody('0101904521', 'not-a-cpr'));
    $this->assertNull($this->lookup->birthDateOf(''));
  }

  /**
   * CPR input is normalized (hyphens stripped) before use.
   *
   * @covers ::childrenOf
   */
  public function testCprNormalization(): void {
    $this->certificates = [];
    $children = $this->lookup->childrenOf('010190-4521');
    $this->assertCount(2, $children);
  }

}
