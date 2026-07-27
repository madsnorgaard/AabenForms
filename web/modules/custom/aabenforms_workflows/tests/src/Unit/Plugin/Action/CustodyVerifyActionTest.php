<?php

namespace Drupal\Tests\aabenforms_workflows\Unit\Plugin\Action;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_workflows\Plugin\Action\CustodyVerifyAction;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface as EcaTokenInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the CustodyVerifyAction ECA plugin.
 *
 * @coversDefaultClass \Drupal\aabenforms_workflows\Plugin\Action\CustodyVerifyAction
 * @group aabenforms_workflows
 */
class CustodyVerifyActionTest extends UnitTestCase {

  /**
   * The action under test.
   *
   * @var \Drupal\aabenforms_workflows\Plugin\Action\CustodyVerifyAction
   */
  protected CustodyVerifyAction $action;

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

    $this->action = new CustodyVerifyAction(
      [
        'adult_cpr_token' => 'cpr',
        'child_cpr_token' => 'child_cpr',
        'result_token' => 'custody_verified',
      ],
      'aabenforms_custody_verify',
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
   * Registered custody holder verifies as 'true'.
   *
   * @covers ::execute
   */
  public function testCustodyConfirmed(): void {
    $this->tokenStorage['cpr'] = '0101904521';
    $this->tokenStorage['child_cpr'] = '0109182345';
    $this->familyLookup->expects($this->once())
      ->method('hasCustody')
      ->with('0101904521', '0109182345')
      ->willReturn(TRUE);

    $this->action->execute();

    $this->assertSame('true', $this->tokenStorage['custody_verified']);
  }

  /**
   * A non-custody holder verifies as 'false' with a failed step.
   *
   * @covers ::execute
   */
  public function testCustodyDenied(): void {
    $this->tokenStorage['cpr'] = '2506924015';
    $this->tokenStorage['child_cpr'] = '0109182345';
    $this->familyLookup->method('hasCustody')->willReturn(FALSE);

    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'failed');

    $this->action->execute();

    $this->assertSame('false', $this->tokenStorage['custody_verified']);
  }

  /**
   * Missing input fails closed without calling the registry.
   *
   * @covers ::execute
   */
  public function testMissingInputFailsClosed(): void {
    $this->tokenStorage['cpr'] = '0101904521';
    // No child_cpr token set.
    $this->familyLookup->expects($this->never())->method('hasCustody');

    $this->action->execute();

    $this->assertSame('false', $this->tokenStorage['custody_verified']);
  }

  /**
   * A registry exception fails closed with a failed step.
   *
   * @covers ::execute
   */
  public function testRegistryErrorFailsClosed(): void {
    $this->tokenStorage['cpr'] = '0101904521';
    $this->tokenStorage['child_cpr'] = '0109182345';
    $this->familyLookup->method('hasCustody')->willThrowException(new \RuntimeException('down'));

    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'failed');

    $this->action->execute();

    $this->assertSame('false', $this->tokenStorage['custody_verified']);
  }

  /**
   * CPRs are normalized (hyphens stripped) before the registry call.
   *
   * @covers ::execute
   */
  public function testCprNormalization(): void {
    $this->tokenStorage['cpr'] = '010190-4521';
    $this->tokenStorage['child_cpr'] = '010918-2345';
    $this->familyLookup->expects($this->once())
      ->method('hasCustody')
      ->with('0101904521', '0109182345')
      ->willReturn(TRUE);

    $this->action->execute();

    $this->assertSame('true', $this->tokenStorage['custody_verified']);
  }

}
