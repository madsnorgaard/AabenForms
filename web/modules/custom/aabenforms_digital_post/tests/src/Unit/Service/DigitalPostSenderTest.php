<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Unit\Service;

use Drupal\aabenforms_digital_post\Audit\AuditEmitterInterface;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\Registration\DigitalPostRegistrationLookupInterface;
use Drupal\aabenforms_digital_post\Registration\RegistrationStatus;
use Drupal\aabenforms_digital_post\Service\DigitalPostSender;
use Drupal\aabenforms_digital_post\Service\Sf1601ClientInterface;
use Drupal\aabenforms_digital_post\Service\TransactionIdGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests channel routing inside DigitalPostSender.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\Service\DigitalPostSender
 * @group aabenforms_digital_post
 */
class DigitalPostSenderTest extends UnitTestCase {

  /**
   * Transaction ids each transport was called with.
   *
   * @var array<string, string[]>
   */
  private array $calls = ['digital' => [], 'physical' => []];

  /**
   * Audit event types emitted.
   *
   * @var string[]
   */
  private array $events = [];

  /**
   * Builds a transport that records calls and returns the given result.
   */
  private function transport(string $name, ?string $failReason = NULL): Sf1601ClientInterface {
    $client = $this->createMock(Sf1601ClientInterface::class);
    $client->method('modeLabel')->willReturn($name === 'digital' ? 'fake_db' : 'physical_demo');
    $client->method('send')->willReturnCallback(function (DigitalPost $post, string $tx) use ($name, $failReason): Result {
      $this->calls[$name][] = $tx;
      return $failReason === NULL ? Result::success($tx, $name . ' ok') : Result::failure($tx, $failReason, $name . ' failed');
    });
    return $client;
  }

  /**
   * Builds the sender around a lookup answer (or a throwing lookup).
   */
  private function sender(?string $status, ?string $physicalFail = NULL, ?string $digitalFail = NULL): DigitalPostSender {
    $lookup = $this->createMock(DigitalPostRegistrationLookupInterface::class);
    $lookup->method('mode')->willReturn('demo');
    if ($status === NULL) {
      $lookup->method('lookup')->willThrowException(new \RuntimeException('register down'));
    }
    else {
      $lookup->method('lookup')->willReturn(new RegistrationStatus($status, 'demo'));
    }
    $audit = $this->createMock(AuditEmitterInterface::class);
    $audit->method('emit')->willReturnCallback(function (string $eventType): void {
      $this->events[] = $eventType;
    });
    return new DigitalPostSender(
      $this->transport('digital', $digitalFail),
      new TransactionIdGenerator(),
      $audit,
      $this->createMock(ConfigFactoryInterface::class),
      new NullLogger(),
      $lookup,
      $this->transport('physical', $physicalFail),
    );
  }

  /**
   * A post to a CPR with a fixed transaction id.
   */
  private function post(): DigitalPost {
    return new DigitalPost(
      recipient: Recipient::cpr('2403482210'),
      sender: new Sender('12345678', 'Kommune'),
      subject: 'Afgørelse',
      body: '<p>Brev</p>',
      meta: ['transaction_id' => 'tx-1'],
    );
  }

  /**
   * Registered: digital transport only.
   */
  public function testRegisteredUsesDigitalOnly(): void {
    $result = $this->sender(RegistrationStatus::REGISTERED)->send($this->post());
    $this->assertSame(['tx-1'], $this->calls['digital']);
    $this->assertSame([], $this->calls['physical']);
    $this->assertSame('digital', $result->deliveryChannel);
    $this->assertFalse($result->manualFollowUp);
    $this->assertTrue($result->isSuccess());
  }

  /**
   * Exempt: physical transport only.
   */
  public function testExemptUsesPhysicalOnly(): void {
    $result = $this->sender(RegistrationStatus::EXEMPT)->send($this->post());
    $this->assertSame([], $this->calls['digital']);
    $this->assertSame(['tx-1'], $this->calls['physical']);
    $this->assertSame('physical', $result->deliveryChannel);
    $this->assertSame('exempt', $result->registrationStatus);
    $this->assertTrue($result->isSuccess());
  }

  /**
   * Unknown: both, separate physical tx, manual follow-up audited.
   */
  public function testUnknownSendsBothAndFlagsFollowUp(): void {
    $result = $this->sender(RegistrationStatus::UNKNOWN)->send($this->post());
    $this->assertSame(['tx-1'], $this->calls['digital']);
    $this->assertSame(['tx-1-print'], $this->calls['physical']);
    $this->assertSame('digital_and_physical', $result->deliveryChannel);
    $this->assertSame('tx-1', $result->transactionId);
    $this->assertTrue($result->manualFollowUp);
    $this->assertTrue($result->isSuccess());
    $this->assertContains('digital_post_manual_followup', $this->events);
  }

  /**
   * A failing lookup is treated as unknown, never as registered.
   */
  public function testLookupErrorIsTreatedAsUnknown(): void {
    $result = $this->sender(NULL)->send($this->post());
    $this->assertSame('digital_and_physical', $result->deliveryChannel);
    $this->assertSame('unknown', $result->registrationStatus);
  }

  /**
   * Unknown + a failed physical leg fails the whole send (fail closed).
   */
  public function testUnknownWithFailedPhysicalLegFails(): void {
    $result = $this->sender(RegistrationStatus::UNKNOWN, Result::REASON_PRINT_UNAVAILABLE)->send($this->post());
    $this->assertFalse($result->isSuccess());
    $this->assertSame(Result::REASON_PRINT_UNAVAILABLE, $result->reasonCode);
    $this->assertTrue($result->manualFollowUp);
  }

  /**
   * Unknown + a failed digital leg still succeeds: the physical letter goes.
   */
  public function testUnknownWithFailedDigitalLegStillDelivers(): void {
    $result = $this->sender(RegistrationStatus::UNKNOWN, NULL, Result::REASON_TRANSPORT)->send($this->post());
    $this->assertTrue($result->isSuccess());
    $this->assertSame('digital_and_physical', $result->deliveryChannel);
  }

  /**
   * Exempt + a refused physical leg fails and asks for manual follow-up.
   */
  public function testExemptWithRefusedPrintFailsWithFollowUp(): void {
    $result = $this->sender(RegistrationStatus::EXEMPT, Result::REASON_PRINT_UNAVAILABLE)->send($this->post());
    $this->assertFalse($result->isSuccess());
    $this->assertTrue($result->manualFollowUp);
    $this->assertSame([], $this->calls['digital']);
  }

}
