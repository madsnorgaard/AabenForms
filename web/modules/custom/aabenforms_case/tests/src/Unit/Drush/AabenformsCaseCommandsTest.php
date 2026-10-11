<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_case\Unit\Drush;

use Composer\InstalledVersions;
use Drupal\aabenforms_case\Drush\AabenformsCaseCommands;
use Drupal\aabenforms_case\Service\KlageDemoSeeder;
use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\Tests\aabenforms_core\Traits\DemoEnvironmentTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that the klage demo seeder refuses outside a demo environment.
 *
 * @coversDefaultClass \Drupal\aabenforms_case\Drush\AabenformsCaseCommands
 * @group aabenforms_case
 */
class AabenformsCaseCommandsTest extends UnitTestCase {

  use DemoEnvironmentTrait;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreDemoEnvironment();
    parent::tearDown();
  }

  /**
   * Production refuses with a non-zero exit and never touches the seeder.
   *
   * @covers ::seedKlageCase
   */
  public function testRefusesInProduction(): void {
    $this->setDemoEnvironment('production');
    $seeder = $this->createMock(KlageDemoSeeder::class);
    $seeder->expects($this->never())->method('seed');

    $command = new AabenformsCaseCommands($seeder, new DemoEnvironment());
    $output = $this->bufferCommandOutput($command);

    $this->assertSame(AabenformsCaseCommands::EXIT_FAILURE, $command->seedKlageCase(['days-ago' => 10, 'reset' => FALSE]));
    $this->assertStringContainsString('AABENFORMS_DEMO_SITE', $output->fetch());
  }

  /**
   * The opted-in demo site may seed.
   *
   * @covers ::seedKlageCase
   */
  public function testRunsOnDemoSite(): void {
    // The success path formats its output with Drush's dt().
    require_once InstalledVersions::getInstallPath('drush/drush') . '/includes/output.inc';
    $this->setDemoEnvironment('demo_site');
    $seeder = $this->createMock(KlageDemoSeeder::class);
    $seeder->expects($this->once())->method('seed')->willReturn([
      'status' => KlageDemoSeeder::STATUS_EXISTS,
      'case_id' => 1,
      'appealable' => TRUE,
      'klagefrist' => NULL,
    ]);

    $command = new AabenformsCaseCommands($seeder, new DemoEnvironment());
    $this->bufferCommandOutput($command);

    $this->assertSame(AabenformsCaseCommands::EXIT_SUCCESS, $command->seedKlageCase(['days-ago' => 10, 'reset' => FALSE]));
  }

}
