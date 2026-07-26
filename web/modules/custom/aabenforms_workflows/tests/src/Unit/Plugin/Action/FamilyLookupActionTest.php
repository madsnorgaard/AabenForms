<?php

namespace Drupal\Tests\aabenforms_workflows\Unit\Plugin\Action;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_workflows\Plugin\Action\FamilyLookupAction;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface as EcaTokenInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the FamilyLookupAction ECA plugin.
 *
 * @coversDefaultClass \Drupal\aabenforms_workflows\Plugin\Action\FamilyLookupAction
 * @group aabenforms_workflows
 */
class FamilyLookupActionTest extends UnitTestCase {

  /**
   * The action under test.
   *
   * @var \Drupal\aabenforms_workflows\Plugin\Action\FamilyLookupAction
   */
  protected FamilyLookupAction $action;

  /**
   * Mock family lookup service.
   *
   * @var \Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $familyLookup;

  /**
   * Mock execution collector.
   *
   * @var \Drupal\aabenforms_core\Service\WorkflowExecutionCollector|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $collector;

  /**
   * Token storage for testing.
   *
   * @var array
   */
  protected array $tokenStorage = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $tokenServices = $this->createMock(EcaTokenInterface::class);
    $tokenServices->method('getTokenData')
      ->willReturnCallback(fn ($name) => $this->tokenStorage[$name] ?? NULL);
    $tokenServices->method('addTokenData')
      ->willReturnCallback(function ($name, $value) use ($tokenServices) {
        $this->tokenStorage[$name] = $value;
        return $tokenServices;
      });

    $this->action = new FamilyLookupAction(
      ['cpr_token' => 'cpr', 'result_token' => 'family_data'],
      'aabenforms_family_lookup',
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $tokenServices,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(EcaState::class),
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->collector = $this->createMock(WorkflowExecutionCollector::class);
    $this->action->setExecutionCollector($this->collector);

    $this->familyLookup = $this->createMock(FamilyRelationsLookupInterface::class);
    $cprAccess = $this->createMock(CprAccess::class);
    $cprAccess->method('reveal')->willReturnArgument(0);

    $reflection = new \ReflectionClass($this->action);
    foreach (['familyLookup' => $this->familyLookup, 'cprAccess' => $cprAccess] as $property => $value) {
      $prop = $reflection->getProperty($property);
      $prop->setAccessible(TRUE);
      $prop->setValue($this->action, $value);
    }
  }

  /**
   * Children found: result token holds the list, status token 'found'.
   *
   * @covers ::execute
   */
  public function testChildrenFound(): void {
    $this->tokenStorage['cpr'] = '0101904521';
    $children = [
      ['cpr' => '0109182345', 'full_name' => 'Emil Nielsen Andersen', 'guardians' => []],
    ];
    $this->familyLookup->expects($this->once())
      ->method('childrenOf')
      ->with('0101904521')
      ->willReturn($children);

    $this->action->execute();

    $this->assertSame($children, $this->tokenStorage['family_data']);
    $this->assertSame('found', $this->tokenStorage['family_data_status']);
  }

  /**
   * No children: empty list, status 'not_found', step still recorded.
   *
   * @covers ::execute
   */
  public function testNoChildren(): void {
    $this->tokenStorage['cpr'] = '1502856234';
    $this->familyLookup->method('childrenOf')->willReturn([]);

    $this->collector->expects($this->once())->method('addStep');

    $this->action->execute();

    $this->assertSame([], $this->tokenStorage['family_data']);
    $this->assertSame('not_found', $this->tokenStorage['family_data_status']);
  }

  /**
   * Missing CPR: skipped status, lookup never called, skipped step recorded.
   *
   * @covers ::execute
   */
  public function testMissingCprSkips(): void {
    $this->familyLookup->expects($this->never())->method('childrenOf');

    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'skipped');

    $this->action->execute();

    $this->assertNull($this->tokenStorage['family_data']);
    $this->assertSame('skipped', $this->tokenStorage['family_data_status']);
  }

  /**
   * Registry error: status 'error', result NULL, failed step recorded.
   *
   * @covers ::execute
   */
  public function testRegistryErrorRecordsFailedStep(): void {
    $this->tokenStorage['cpr'] = '0101904521';
    $this->familyLookup->method('childrenOf')->willThrowException(new \RuntimeException('down'));

    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'failed');

    $this->action->execute();

    $this->assertNull($this->tokenStorage['family_data']);
    $this->assertSame('error', $this->tokenStorage['family_data_status']);
  }

  /**
   * Hyphenated CPRs are normalized before lookup.
   *
   * @covers ::execute
   */
  public function testCprNormalization(): void {
    $this->tokenStorage['cpr'] = '010190-4521';
    $this->familyLookup->expects($this->once())
      ->method('childrenOf')
      ->with('0101904521')
      ->willReturn([]);

    $this->action->execute();
  }

  /**
   * Default configuration exposes the documented token names.
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfiguration(): void {
    $defaults = $this->action->defaultConfiguration();
    $this->assertSame('cpr', $defaults['cpr_token']);
    $this->assertSame('family_data', $defaults['result_token']);
  }

}
