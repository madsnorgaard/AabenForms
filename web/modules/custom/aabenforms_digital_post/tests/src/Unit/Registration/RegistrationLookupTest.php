<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Unit\Registration;

use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\Registration\DemoRegistrationLookup;
use Drupal\aabenforms_digital_post\Registration\LiveRegistrationLookup;
use Drupal\aabenforms_digital_post\Registration\RegistrationLookupFactory;
use Drupal\aabenforms_digital_post\Registration\RegistrationStatus;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the DEMO and live-stub registration lookups and their factory.
 *
 * @group aabenforms_digital_post
 */
class RegistrationLookupTest extends UnitTestCase {

  /**
   * Builds a config factory returning the given digital post settings.
   */
  private function configFactory(array $settings): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key) => $settings[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('aabenforms_digital_post.settings')->willReturn($config);
    return $factory;
  }

  /**
   * The demo fixture knows Birthe as exempt and Freja as registered.
   */
  public function testDemoFixturePersonas(): void {
    $lookup = new DemoRegistrationLookup($this->configFactory(['test_mode' => 'fake_db']));

    $birthe = $lookup->lookup(Recipient::cpr('2403482210'));
    $this->assertTrue($birthe->isExempt());
    $this->assertSame(RegistrationStatus::MODE_DEMO, $birthe->mode);

    $freja = $lookup->lookup(Recipient::cpr('010190-4521'));
    $this->assertTrue($freja->isRegistered());
  }

  /**
   * A CPR outside the fixture is unknown, never "registered".
   */
  public function testDemoUnknownCprIsUnknown(): void {
    $lookup = new DemoRegistrationLookup($this->configFactory(['test_mode' => 'fake_db']));
    $status = $lookup->lookup(Recipient::cpr('1111111118'));
    $this->assertTrue($status->isUnknown());
    $this->assertSame('not_in_fixture', $status->reason);
  }

  /**
   * The demo fixture never decides for a real citizen in a live mode.
   */
  public function testDemoLookupDisabledInLiveModes(): void {
    foreach (['live', 'live_test'] as $mode) {
      $lookup = new DemoRegistrationLookup($this->configFactory(['test_mode' => $mode]));
      $status = $lookup->lookup(Recipient::cpr('2403482210'));
      $this->assertTrue($status->isUnknown(), $mode);
      $this->assertSame('demo_lookup_disabled_in_live', $status->reason);
    }
  }

  /**
   * CVR recipients are always registered (no business exemption).
   */
  public function testCvrIsAlwaysRegistered(): void {
    $demo = new DemoRegistrationLookup($this->configFactory(['test_mode' => 'fake_db']));
    $this->assertTrue($demo->lookup(Recipient::cvr('12345678'))->isRegistered());
    $this->assertTrue((new LiveRegistrationLookup())->lookup(Recipient::cvr('12345678'))->isRegistered());
  }

  /**
   * The live stub answers unknown for every CPR (not integrated, #76).
   */
  public function testLiveStubAnswersUnknown(): void {
    $live = new LiveRegistrationLookup();
    $status = $live->lookup(Recipient::cpr('2403482210'));
    $this->assertTrue($status->isUnknown());
    $this->assertSame(RegistrationStatus::MODE_LIVE, $status->mode);
    $this->assertSame('live_not_integrated', $status->reason);
  }

  /**
   * The factory picks demo by default and the live stub otherwise.
   */
  public function testFactorySelection(): void {
    $demo = new DemoRegistrationLookup($this->configFactory([]));
    $live = new LiveRegistrationLookup();
    $cases = [
      [[], 'demo'],
      [['registration_lookup' => 'demo'], 'demo'],
      [['registration_lookup' => 'live'], 'live'],
      // Garbage fails safe to the stub that answers unknown.
      [['registration_lookup' => 'bogus'], 'live'],
    ];
    foreach ($cases as [$settings, $expected]) {
      $factory = new RegistrationLookupFactory($this->configFactory($settings), $demo, $live);
      $this->assertSame($expected, $factory->create()->mode());
    }
  }

  /**
   * The value object rejects an invalid status.
   */
  public function testInvalidStatusRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    new RegistrationStatus('maybe', RegistrationStatus::MODE_DEMO);
  }

}
