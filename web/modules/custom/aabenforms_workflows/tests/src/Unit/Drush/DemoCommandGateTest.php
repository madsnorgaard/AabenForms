<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Unit\Drush;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\aabenforms_workflows\Drush\ApprovalTokenCommands;
use Drupal\aabenforms_workflows\Drush\SchoolLeaderCommands;
use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\aabenforms_workflows\Service\OrgChartServiceInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\aabenforms_core\Traits\DemoEnvironmentTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the environment gates on the workflows demo commands.
 *
 * @group aabenforms_workflows
 */
class DemoCommandGateTest extends UnitTestCase {

  use DemoEnvironmentTrait;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreDemoEnvironment();
    parent::tearDown();
  }

  /**
   * Ensure-skoleleder refuses in production before touching any entity.
   */
  public function testSkolelederRefusesInProduction(): void {
    $this->setDemoEnvironment('production');
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->never())->method('getStorage');

    $command = new SchoolLeaderCommands($entityTypeManager, $this->createMock(OrgChartServiceInterface::class), new DemoEnvironment());
    $output = $this->bufferCommandOutput($command);

    $options = ['name' => 'skoleleder', 'mail' => 'x@example.dk', 'password' => NULL];
    $this->assertSame(SchoolLeaderCommands::EXIT_FAILURE, $command->ensureSkoleleder($options));
    $this->assertStringContainsString('AABENFORMS_DEMO_SITE', $output->fetch());
  }

  /**
   * Approval-token minting is DDEV-only: the demo-site opt-in is not enough.
   *
   * @dataProvider nonLocalModes
   */
  public function testMintRefusesOutsideDdev(string $mode): void {
    $this->setDemoEnvironment($mode);
    $tokens = $this->createMock(ApprovalTokenService::class);
    $tokens->expects($this->never())->method('generateToken');

    $command = new ApprovalTokenCommands($tokens, new DemoEnvironment());
    $output = $this->bufferCommandOutput($command);

    $this->assertSame(ApprovalTokenCommands::EXIT_FAILURE, $command->mint(['sid' => '1', 'parent' => '1']));
    $this->assertStringContainsString('only under local DDEV', $output->fetch());
  }

  /**
   * Data provider: environments that are not local DDEV.
   */
  public static function nonLocalModes(): array {
    return [
      'production' => ['production'],
      'opted-in demo site' => ['demo_site'],
    ];
  }

  /**
   * Under DDEV the mint prints only the token.
   */
  public function testMintRunsUnderDdev(): void {
    $this->setDemoEnvironment('ddev');
    $tokens = $this->createMock(ApprovalTokenService::class);
    $tokens->method('generateToken')->willReturn('signed.token');

    $command = new ApprovalTokenCommands($tokens, new DemoEnvironment());
    $output = $this->bufferCommandOutput($command);

    $this->assertSame(ApprovalTokenCommands::EXIT_SUCCESS, $command->mint(['sid' => '1', 'parent' => '1']));
    $this->assertSame('signed.token', $output->fetch());
  }

}
