<?php

namespace Drupal\Tests\aabenforms_core\Unit\Service;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\EncryptionService;
use Drupal\aabenforms_core\Service\TenantResolver;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the CprAccess helper.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Service\CprAccess
 * @group aabenforms_core
 * @group encryption
 */
class CprAccessTest extends UnitTestCase {

  /**
   * Mock encryption service.
   *
   * @var \Drupal\aabenforms_core\Service\EncryptionService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $encryption;

  /**
   * The helper under test.
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->encryption = $this->getMockBuilder(EncryptionService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['encrypt', 'decrypt'])
      ->getMock();

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    // Single-tenant context: no active tenant, so the legacy AFENC1 path runs.
    $tenantResolver = $this->getMockBuilder(TenantResolver::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getCurrentTenantId'])
      ->getMock();
    $tenantResolver->method('getCurrentTenantId')->willReturn(NULL);

    $this->cprAccess = new CprAccess($this->encryption, $tenantResolver, $loggerFactory);
  }

  /**
   * Protect produces a prefixed ciphertext that is not the plaintext.
   *
   * @covers ::protect
   * @covers ::isProtected
   */
  public function testProtect(): void {
    $this->encryption->method('encrypt')->willReturn('CIPHERBYTES');
    $protected = $this->cprAccess->protect('0101901234');

    $this->assertNotSame('0101901234', $protected);
    $this->assertTrue($this->cprAccess->isProtected($protected));
    $this->assertStringStartsWith('AFENC1:', $protected);
  }

  /**
   * Protect is idempotent and a no-op on empty input.
   *
   * @covers ::protect
   */
  public function testProtectIdempotentAndEmpty(): void {
    $this->encryption->expects($this->once())->method('encrypt')->willReturn('CIPHERBYTES');
    $protected = $this->cprAccess->protect('0101901234');
    // Second call must not encrypt again.
    $this->assertSame($protected, $this->cprAccess->protect($protected));
    $this->assertSame('', $this->cprAccess->protect(''));
  }

  /**
   * Reveal decrypts a protected value and passes plaintext through.
   *
   * @covers ::reveal
   */
  public function testReveal(): void {
    $this->encryption->method('encrypt')->willReturn('CIPHERBYTES');
    $this->encryption->method('decrypt')->with('CIPHERBYTES')->willReturn('0101901234');

    $protected = $this->cprAccess->protect('0101901234');
    $this->assertSame('0101901234', $this->cprAccess->reveal($protected));
    // A non-protected value (e.g. session-sourced CPR) passes through.
    $this->assertSame('0101901234', $this->cprAccess->reveal('0101901234'));
  }

  /**
   * A misconfigured key fails hard: protect throws rather than store plaintext.
   *
   * @covers ::protect
   */
  public function testProtectFailsHardWhenEncryptionUnavailable(): void {
    $this->encryption->method('encrypt')->willThrowException(new \RuntimeException('no key'));
    $this->expectException(\RuntimeException::class);
    $this->cprAccess->protect('0101901234');
  }

}
