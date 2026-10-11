<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_institution\Unit\Drush;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\aabenforms_institution\Drush\InstitutionCommands;
use Drupal\aabenforms_institution\Service\InstitutionRegistry;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\aabenforms_core\Traits\DemoEnvironmentTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that seeding the bundled demo fixture is gated.
 *
 * @coversDefaultClass \Drupal\aabenforms_institution\Drush\InstitutionCommands
 * @group aabenforms_institution
 */
class InstitutionCommandsTest extends UnitTestCase {

  use DemoEnvironmentTrait;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreDemoEnvironment();
    parent::tearDown();
  }

  /**
   * Without --file (the demo fixture) production refuses.
   *
   * @covers ::seed
   */
  public function testDemoFixtureRefusedInProduction(): void {
    $this->setDemoEnvironment('production');
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');
    $command = new InstitutionCommands($entityTypeManager, $this->createMock(InstitutionRegistry::class), new DemoEnvironment());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('AABENFORMS_DEMO_SITE=1');
    $command->seed(['file' => NULL]);
  }

  /**
   * A real extract (--file) is not a demo command and passes the gate.
   *
   * @covers ::seed
   */
  public function testRealExtractPassesGate(): void {
    $this->setDemoEnvironment('production');
    $command = new InstitutionCommands($this->createMock(EntityTypeManagerInterface::class), $this->createMock(InstitutionRegistry::class), new DemoEnvironment());

    // Past the gate the command fails on the unreadable file, not on the
    // environment.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('CSV file not readable');
    $command->seed(['file' => '/nonexistent/institutions.csv']);
  }

}
