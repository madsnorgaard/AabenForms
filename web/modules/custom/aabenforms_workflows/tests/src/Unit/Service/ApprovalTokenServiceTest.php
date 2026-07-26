<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Unit\Service;

use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\PrivateKey;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the ApprovalTokenService.
 *
 * Locks in the validateToken hardening: explicit base64 guard, count()
 * guard on the explode destructure, and is_numeric + range check on the
 * decoded timestamp. Each guard closes a specific path that the
 * pre-hardening code accepted - see issue #17 for the bypass details.
 *
 * @coversDefaultClass \Drupal\aabenforms_workflows\Service\ApprovalTokenService
 * @group aabenforms_workflows
 */
class ApprovalTokenServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected ApprovalTokenService $service;

  /**
   * Fixed HMAC key the PrivateKey mock returns - lets us craft tampered tokens.
   */
  protected string $key = 'unit-test-key-not-secret';

  /**
   * The mock logger - exposed so individual tests can assert on log calls.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $private_key = $this->createMock(PrivateKey::class);
    $private_key->method('get')->willReturn($this->key);

    $this->logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    $this->service = new ApprovalTokenService($private_key, $logger_factory);
  }

  /**
   * A freshly generated token validates against the same submission/parent.
   */
  public function testRoundTripValidates(): void {
    $token = $this->service->generateToken(42, 1);
    $this->assertTrue($this->service->validateToken(42, 1, $token));
  }

  /**
   * Tampering with the wrong submission_id fails the HMAC check.
   */
  public function testTamperedSubmissionIdRejected(): void {
    $token = $this->service->generateToken(42, 1);
    $this->assertFalse($this->service->validateToken(99, 1, $token));
  }

  /**
   * A token older than TOKEN_EXPIRATION fails the expiry check.
   */
  public function testExpiredTokenRejected(): void {
    // TOKEN_EXPIRATION + 1 second ago - just past the window.
    $token = $this->service->generateToken(42, 1, time() - ApprovalTokenService::TOKEN_EXPIRATION - 1);
    $this->assertFalse($this->service->validateToken(42, 1, $token));
  }

  /**
   * Garbage that isn't valid base64 gets rejected at the decode step.
   */
  public function testNonBase64TokenRejected(): void {
    $this->assertFalse($this->service->validateToken(42, 1, '!!!not-base64!!!'));
  }

  /**
   * Empty input is rejected.
   *
   * `base64_decode('') === ''` which the new `=== ''` guard catches;
   * the old `if (!$decoded)` happened to do the same here, but the
   * contract is now explicit.
   */
  public function testEmptyTokenRejected(): void {
    $this->assertFalse($this->service->validateToken(42, 1, ''));
  }

  /**
   * Base64 that decodes to a string with no ':' separator is rejected.
   *
   * This is the path that previously caused a fatal error or fell
   * through with an empty $timestamp. The count(parts) !== 2 guard
   * short-circuits it.
   */
  public function testTokenWithoutSeparatorRejected(): void {
    $token = base64_encode('no-colon-here');
    $this->assertFalse($this->service->validateToken(42, 1, $token));
  }

  /**
   * A non-numeric timestamp fails is_numeric() before reaching the cast.
   *
   * Pre-hardening, (int) 'abc' resolved to 0 and the token tested as
   * "valid in 1970". Now rejected explicitly.
   */
  public function testNonNumericTimestampRejected(): void {
    $token = base64_encode('somehash:not-a-number');
    $this->assertFalse($this->service->validateToken(42, 1, $token));
  }

  /**
   * A timestamp of 0 is rejected by the positivity check.
   */
  public function testZeroTimestampRejected(): void {
    $token = base64_encode('somehash:0');
    $this->assertFalse($this->service->validateToken(42, 1, $token));
  }

  /**
   * A negative timestamp is rejected by the positivity check.
   */
  public function testNegativeTimestampRejected(): void {
    $token = base64_encode('somehash:-12345');
    $this->assertFalse($this->service->validateToken(42, 1, $token));
  }

  /**
   * A far-future timestamp is rejected by the range check.
   *
   * One hour of clock-drift slack is allowed; anything beyond that fails.
   */
  public function testFarFutureTimestampRejected(): void {
    $future = time() + ApprovalTokenService::MAX_CLOCK_SKEW + 1;
    $token = base64_encode('somehash:' . $future);
    $this->assertFalse($this->service->validateToken(42, 1, $token));
  }

  /**
   * GetTokenTimestamp returns NULL for malformed input rather than 0.
   */
  public function testGetTokenTimestampReturnsNullForMalformed(): void {
    $this->assertNull($this->service->getTokenTimestamp(''));
    $this->assertNull($this->service->getTokenTimestamp('!!!not-base64!!!'));
    $this->assertNull($this->service->getTokenTimestamp(base64_encode('no-colon')));
    $this->assertNull($this->service->getTokenTimestamp(base64_encode('hash:not-numeric')));
    $this->assertNull($this->service->getTokenTimestamp(base64_encode('hash:0')));
  }

  /**
   * GetTokenTimestamp extracts the timestamp from a well-formed token.
   */
  public function testGetTokenTimestampExtractsValidValue(): void {
    $now = time();
    $token = $this->service->generateToken(42, 1, $now);
    $this->assertSame($now, $this->service->getTokenTimestamp($token));
  }

  /**
   * IsTokenExpired only fires for well-formed tokens past the cutoff.
   *
   * Malformed tokens have no resolvable timestamp - they are NOT
   * "expired", they are malformed. isTokenMalformed() reports that
   * separately so the controller can render distinct UX for each.
   */
  public function testIsTokenExpiredSemantics(): void {
    // Genuinely-past well-formed token → TRUE.
    $past = $this->service->generateToken(42, 1, time() - ApprovalTokenService::TOKEN_EXPIRATION - 1);
    $this->assertTrue($this->service->isTokenExpired($past));

    // Fresh well-formed token → FALSE.
    $fresh = $this->service->generateToken(42, 1);
    $this->assertFalse($this->service->isTokenExpired($fresh));

    // Malformed tokens → FALSE (they're not expired, they're malformed).
    $this->assertFalse($this->service->isTokenExpired(''));
    $this->assertFalse($this->service->isTokenExpired('!!!not-base64!!!'));
    $this->assertFalse($this->service->isTokenExpired(base64_encode('no-colon')));
    $this->assertFalse($this->service->isTokenExpired(base64_encode('hash:not-numeric')));
  }

  /**
   * GenerateToken emits an info log naming the submission and parent.
   */
  public function testGenerateTokenLogsInfo(): void {
    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('Generated approval token'),
        $this->callback(function ($context) {
          return ($context['@sid'] ?? NULL) === 42 && ($context['@parent'] ?? NULL) === 1;
        })
      );

    $this->service->generateToken(42, 1);
  }

  /**
   * Successful validation emits the success info log.
   *
   * Sets the expectation before any service calls so PHPUnit catches both
   * the generate-time and validate-time info logs.
   */
  public function testValidateTokenSuccessLogsInfo(): void {
    $info_calls = [];
    $this->logger->method('info')->willReturnCallback(
      function ($message, $context) use (&$info_calls) {
        $info_calls[] = $message;
      }
    );

    $token = $this->service->generateToken(42, 1);
    $this->assertTrue($this->service->validateToken(42, 1, $token));

    $this->assertCount(2, $info_calls);
    $this->assertStringContainsString('Generated approval token', $info_calls[0]);
    $this->assertStringContainsString('validated successfully', $info_calls[1]);
  }

  /**
   * HMAC mismatch (well-formed token, wrong key/payload) emits a warning.
   */
  public function testValidateTokenHmacMismatchLogsWarning(): void {
    // Token is well-formed (parses, in-date) but the hash is bogus.
    $tampered = base64_encode('deadbeefdeadbeef:' . time());

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Token validation failed'),
        $this->anything()
      );

    $this->assertFalse($this->service->validateToken(42, 1, $tampered));
  }

  /**
   * IsTokenMalformed catches every structural failure mode.
   */
  public function testIsTokenMalformedSemantics(): void {
    // Malformed inputs.
    $this->assertTrue($this->service->isTokenMalformed(''));
    $this->assertTrue($this->service->isTokenMalformed('!!!not-base64!!!'));
    $this->assertTrue($this->service->isTokenMalformed(base64_encode('no-colon')));
    $this->assertTrue($this->service->isTokenMalformed(base64_encode('hash:not-numeric')));
    $this->assertTrue($this->service->isTokenMalformed(base64_encode('hash:0')));
    $this->assertTrue($this->service->isTokenMalformed(base64_encode('hash:-12345')));

    // Well-formed tokens (any timestamp) are NOT malformed - even if
    // they will fail HMAC validation downstream.
    $fresh = $this->service->generateToken(42, 1);
    $this->assertFalse($this->service->isTokenMalformed($fresh));

    $tampered = base64_encode('deadbeef:' . time());
    $this->assertFalse($this->service->isTokenMalformed($tampered));
  }

}
