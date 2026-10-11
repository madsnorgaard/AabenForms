<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_mitid\Unit\Service;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\aabenforms_mitid\Service\MitIdConfigGuard;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aabenforms_mitid\Service\MitIdConfigGuard
 * @group aabenforms_mitid
 */
final class MitIdConfigGuardTest extends UnitTestCase {

  /**
   * The environment as it was before the test.
   */
  private string|false $ddev;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->ddev = getenv('DDEV_HOSTNAME');
    putenv('DDEV_HOSTNAME');
    putenv('AABENFORMS_DEMO_SITE');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->ddev === FALSE ? 'DDEV_HOSTNAME' : 'DDEV_HOSTNAME=' . $this->ddev);
    putenv('AABENFORMS_DEMO_SITE');
    parent::tearDown();
  }

  /**
   * A guard over the given settings.
   */
  private function guard(array $settings): MitIdConfigGuard {
    $factory = $this->getConfigFactoryStub(['aabenforms_mitid.settings' => $settings]);
    return new MitIdConfigGuard($factory, new DemoEnvironment());
  }

  /**
   * The shipped mock settings are refused outside development and demo.
   */
  public function testMockSettingsRefusedInProduction(): void {
    $guard = $this->guard([
      'client_secret' => MitIdConfigGuard::PLACEHOLDER_SECRET,
      'authorization_endpoint' => 'http://localhost:8080/realms/r/protocol/openid-connect/auth',
      'token_endpoint' => 'https://idp.example/token',
    ]);
    $this->assertSame(['placeholder_client_secret', 'insecure_authorization_endpoint'], $guard->problems());
    $this->assertFalse($guard->isAllowed());

    putenv('AABENFORMS_DEMO_SITE=1');
    $this->assertTrue($guard->isAllowed(), 'The public demo site opts in explicitly');
    putenv('AABENFORMS_DEMO_SITE');

    putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    $this->assertTrue($guard->isAllowed(), 'Local development');
  }

  /**
   * Real settings pass anywhere.
   */
  public function testRealSettingsAllowed(): void {
    $guard = $this->guard([
      'client_secret' => 'a-real-secret-from-the-environment',
      'authorization_endpoint' => 'https://idp.example/auth',
      'token_endpoint' => 'https://idp.example/token',
      'userinfo_endpoint' => 'https://idp.example/userinfo',
      'issuer' => 'https://idp.example',
    ]);
    $this->assertSame([], $guard->problems());
    $this->assertTrue($guard->isAllowed());
  }

}
