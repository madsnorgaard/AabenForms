<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Unit\DigitalPost;

use Drupal\aabenforms_digital_post\DigitalPost\DeliveryChannel;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\Registration\RegistrationStatus;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the delivery channel selection rule.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\DigitalPost\DeliveryChannel
 * @group aabenforms_digital_post
 */
class DeliveryChannelTest extends UnitTestCase {

  /**
   * Builds a post.
   */
  private function post(string $type = DigitalPost::TYPE_DIGITAL_POST, ?Recipient $recipient = NULL): DigitalPost {
    return new DigitalPost(
      recipient: $recipient ?? Recipient::cpr('2403482210'),
      sender: new Sender('12345678', 'Kommune'),
      subject: 'Afgørelse',
      body: '<p>Brev</p>',
      type: $type,
    );
  }

  /**
   * Builds a demo status.
   */
  private function regStatus(string $status): RegistrationStatus {
    return new RegistrationStatus($status, RegistrationStatus::MODE_DEMO);
  }

  /**
   * @covers ::select
   */
  public function testRegisteredIsDigital(): void {
    $this->assertSame(DeliveryChannel::DIGITAL, DeliveryChannel::select($this->post(), $this->regStatus(RegistrationStatus::REGISTERED)));
  }

  /**
   * Exempt overrides a "Digital Post" type: the letter must arrive.
   *
   * @covers ::select
   */
  public function testExemptIsPhysicalEvenForDigitalPostType(): void {
    $this->assertSame(DeliveryChannel::PHYSICAL, DeliveryChannel::select($this->post(), $this->regStatus(RegistrationStatus::EXEMPT)));
  }

  /**
   * @covers ::select
   */
  public function testUnknownIsBoth(): void {
    $this->assertSame(DeliveryChannel::DIGITAL_AND_PHYSICAL, DeliveryChannel::select($this->post(), $this->regStatus(RegistrationStatus::UNKNOWN)));
  }

  /**
   * @covers ::select
   */
  public function testExplicitFysiskPostIsPhysical(): void {
    $this->assertSame(DeliveryChannel::PHYSICAL, DeliveryChannel::select($this->post(DigitalPost::TYPE_FYSISK_POST), $this->regStatus(RegistrationStatus::REGISTERED)));
  }

  /**
   * @covers ::select
   */
  public function testCvrAndNemSmsStayDigital(): void {
    $this->assertSame(DeliveryChannel::DIGITAL, DeliveryChannel::select($this->post(DigitalPost::TYPE_DIGITAL_POST, Recipient::cvr('12345678')), $this->regStatus(RegistrationStatus::UNKNOWN)));
    $this->assertSame(DeliveryChannel::DIGITAL, DeliveryChannel::select($this->post(DigitalPost::TYPE_NEM_SMS), $this->regStatus(RegistrationStatus::EXEMPT)));
  }

  /**
   * @covers ::includesPhysical
   */
  public function testIncludesPhysical(): void {
    $this->assertFalse(DeliveryChannel::includesPhysical(DeliveryChannel::DIGITAL));
    $this->assertTrue(DeliveryChannel::includesPhysical(DeliveryChannel::PHYSICAL));
    $this->assertTrue(DeliveryChannel::includesPhysical(DeliveryChannel::DIGITAL_AND_PHYSICAL));
  }

}
