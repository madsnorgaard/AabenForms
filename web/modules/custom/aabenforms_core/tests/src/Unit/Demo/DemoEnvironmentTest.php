<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Demo;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\Tests\UnitTestCase;

/**
 * Tests where demo commands are allowed to run.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Demo\DemoEnvironment
 * @group aabenforms_core
 */
class DemoEnvironmentTest extends UnitTestCase {

  /**
   * The environment as it was before the test.
   */
  protected array $savedEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['DDEV_HOSTNAME', DemoEnvironment::OPT_IN_VARIABLE] as $name) {
      $this->savedEnv[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->savedEnv as $name => $value) {
      putenv($value === FALSE ? $name : "$name=$value");
    }
    parent::tearDown();
  }

  /**
   * Production (no DDEV, no opt-in) refuses both tiers.
   *
   * @covers ::isDemoAllowed
   * @covers ::isLocalDevelopment
   */
  public function testProductionRefuses(): void {
    $environment = new DemoEnvironment();
    $this->assertFalse($environment->isDemoAllowed());
    $this->assertFalse($environment->isLocalDevelopment());
  }

  /**
   * An empty DDEV_HOSTNAME or an opt-in other than "1" does not count.
   *
   * @covers ::isDemoAllowed
   */
  public function testNearMissesRefuse(): void {
    putenv('DDEV_HOSTNAME=');
    putenv(DemoEnvironment::OPT_IN_VARIABLE . '=true');
    $this->assertFalse((new DemoEnvironment())->isDemoAllowed());
  }

  /**
   * DDEV allows both tiers.
   *
   * @covers ::isDemoAllowed
   * @covers ::isLocalDevelopment
   */
  public function testDdevAllowsAll(): void {
    putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    $environment = new DemoEnvironment();
    $this->assertTrue($environment->isDemoAllowed());
    $this->assertTrue($environment->isLocalDevelopment());
  }

  /**
   * The public demo site opts into demo data but never into seeded identities.
   *
   * @covers ::isDemoAllowed
   * @covers ::isLocalDevelopment
   */
  public function testDemoSiteOptIn(): void {
    putenv(DemoEnvironment::OPT_IN_VARIABLE . '=1');
    $environment = new DemoEnvironment();
    $this->assertTrue($environment->isDemoAllowed());
    $this->assertFalse($environment->isLocalDevelopment());
  }

  /**
   * Refusal messages name the opt-in variable.
   *
   * @covers ::refusalMessage
   * @covers ::localOnlyRefusalMessage
   */
  public function testMessagesNameTheVariable(): void {
    $environment = new DemoEnvironment();
    $this->assertStringContainsString('AABENFORMS_DEMO_SITE=1', $environment->refusalMessage('x'));
    $this->assertStringContainsString('AABENFORMS_DEMO_SITE=1 does not enable it', $environment->localOnlyRefusalMessage('x'));
  }

}
