<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Unit\DigitalPost;

use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Result DTO factories + auditContext() PII guard.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\DigitalPost\Result
 * @group aabenforms_digital_post
 */
class ResultTest extends UnitTestCase {

  /**
   * Success() builds a SUCCESS result with a NULL reason code.
   */
  public function testSuccessFactory(): void {
    $r = Result::success('tx-1', 'OK', '<envelope>...</envelope>');
    $this->assertTrue($r->isSuccess());
    $this->assertSame(Result::SUCCESS, $r->status);
    $this->assertSame('tx-1', $r->transactionId);
    $this->assertNull($r->reasonCode);
    $this->assertSame('OK', $r->message);
    $this->assertSame('<envelope>...</envelope>', $r->rawResponse);
  }

  /**
   * Success() defaults message + rawResponse.
   */
  public function testSuccessFactoryDefaults(): void {
    $r = Result::success('tx-2');
    $this->assertSame('', $r->message);
    $this->assertNull($r->rawResponse);
  }

  /**
   * Failure() builds a FAILURE result with the given reason code.
   */
  public function testFailureFactory(): void {
    $r = Result::failure('tx-3', Result::REASON_QUOTA, 'over quota', '<fault/>');
    $this->assertFalse($r->isSuccess());
    $this->assertSame(Result::FAILURE, $r->status);
    $this->assertSame('tx-3', $r->transactionId);
    $this->assertSame(Result::REASON_QUOTA, $r->reasonCode);
    $this->assertSame('over quota', $r->message);
    $this->assertSame('<fault/>', $r->rawResponse);
  }

  /**
   * Pending() is accepted-but-not-delivered: pending, and NOT a success.
   */
  public function testPendingFactory(): void {
    $r = Result::pending('tx-p', 'accepted (HTTP 200)', '<receipt/>');
    $this->assertTrue($r->isPending());
    $this->assertFalse($r->isSuccess());
    $this->assertSame(Result::PENDING, $r->status);
    $this->assertSame('tx-p', $r->transactionId);
    $this->assertNull($r->reasonCode);
    $this->assertSame('<receipt/>', $r->rawResponse);
  }

  /**
   * AuditContext() exposes the four log-safe fields and nothing else.
   */
  public function testAuditContextShape(): void {
    $r = Result::failure('tx-4', Result::REASON_TRANSPORT, 'network fail', 'PII goes here');
    $ctx = $r->auditContext();
    // Assert the key set, not the order. Insertion order is a PHP
    // implementation detail and not part of the public contract.
    $expected_keys = ['status', 'transaction_id', 'reason_code', 'message'];
    $actual_keys = array_keys($ctx);
    sort($expected_keys);
    sort($actual_keys);
    $this->assertCount(4, $ctx);
    $this->assertSame($expected_keys, $actual_keys);
    $this->assertSame(Result::FAILURE, $ctx['status']);
    $this->assertSame('tx-4', $ctx['transaction_id']);
    $this->assertSame(Result::REASON_TRANSPORT, $ctx['reason_code']);
    $this->assertSame('network fail', $ctx['message']);
  }

  /**
   * AuditContext() never leaks rawResponse.
   *
   * The rawResponse field can carry PII from the MeMo envelope and must
   * stay opt-in for callers; it never appears in audit logs.
   */
  public function testAuditContextExcludesRawResponse(): void {
    $secret = 'CPR=0000000099 inside the MeMo envelope';
    $r = Result::failure('tx-5', Result::REASON_VALIDATION, 'bad', $secret);
    $ctx = $r->auditContext();
    $this->assertArrayNotHasKey('rawResponse', $ctx);
    $this->assertArrayNotHasKey('raw_response', $ctx);
    foreach ($ctx as $value) {
      $this->assertStringNotContainsString($secret, (string) $value);
    }
  }

}
