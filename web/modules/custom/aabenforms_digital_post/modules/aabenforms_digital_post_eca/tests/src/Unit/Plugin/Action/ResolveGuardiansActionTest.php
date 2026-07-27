<?php

namespace Drupal\Tests\aabenforms_digital_post_eca\Unit\Plugin\Action;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_digital_post\DigitalPost\RecipientResolution;
use Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver;
use Drupal\aabenforms_digital_post_eca\Plugin\Action\ResolveGuardiansAction;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface as EcaTokenInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the ResolveGuardiansAction ECA plugin.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post_eca\Plugin\Action\ResolveGuardiansAction
 * @group aabenforms_digital_post_eca
 */
class ResolveGuardiansActionTest extends UnitTestCase {

  /**
   * The action under test.
   *
   * @var \Drupal\aabenforms_digital_post_eca\Plugin\Action\ResolveGuardiansAction
   */
  protected ResolveGuardiansAction $action;

  /**
   * Mock resolver.
   *
   * @var \Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $resolver;

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

    $this->action = new ResolveGuardiansAction(
      ['child_cpr_token' => 'child_cpr', 'result_token' => 'post_recipients'],
      'aabenforms_resolve_guardians',
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

    $this->resolver = $this->createMock(GuardianRecipientResolver::class);
    $this->action->setResolver($this->resolver);

    $cprAccess = $this->createMock(CprAccess::class);
    $cprAccess->method('reveal')->willReturnArgument(0);
    $this->action->setCprAccess($cprAccess);
  }

  /**
   * Two guardians fill both send slots and status is 'guardians'.
   *
   * @covers ::execute
   */
  public function testTwoGuardiansFillBothSlots(): void {
    $this->tokenStorage['child_cpr'] = '0109182345';
    $this->resolver->method('resolveForChild')->willReturn(new RecipientResolution(
      RecipientResolution::RULE_GUARDIANS,
      'Under 15.',
      [
        ['cpr' => '0101904521', 'full_name' => 'Freja Nielsen', 'role' => 'guardian'],
        ['cpr' => '0803755210', 'full_name' => 'Lars Andersen', 'role' => 'guardian'],
      ],
    ));

    $this->action->execute();

    $this->assertSame('guardians', $this->tokenStorage['post_recipients_status']);
    $this->assertSame('2', $this->tokenStorage['post_recipients_count']);
    $this->assertSame('0101904521', $this->tokenStorage['post_recipients_1']);
    $this->assertSame('0803755210', $this->tokenStorage['post_recipients_2']);
  }

  /**
   * A sole guardian leaves the second slot empty so send #2 skips.
   *
   * @covers ::execute
   */
  public function testSoleGuardianLeavesSecondSlotEmpty(): void {
    $this->tokenStorage['child_cpr'] = '1203192345';
    $this->resolver->method('resolveForChild')->willReturn(new RecipientResolution(
      RecipientResolution::RULE_GUARDIANS,
      'Under 15.',
      [
        ['cpr' => '2506924015', 'full_name' => 'Sofie Hansen', 'role' => 'guardian'],
      ],
    ));

    $this->action->execute();

    $this->assertSame('2506924015', $this->tokenStorage['post_recipients_1']);
    $this->assertSame('', $this->tokenStorage['post_recipients_2']);
    $this->assertSame('1', $this->tokenStorage['post_recipients_count']);
  }

  /**
   * A 15+ pupil occupies slot 1 with status 'pupil'.
   *
   * @covers ::execute
   */
  public function testPupilRule(): void {
    $this->tokenStorage['child_cpr'] = '2005102345';
    $this->resolver->method('resolveForChild')->willReturn(new RecipientResolution(
      RecipientResolution::RULE_PUPIL,
      '15 or older.',
      [
        ['cpr' => '2005102345', 'full_name' => '', 'role' => 'pupil'],
      ],
    ));

    $this->action->execute();

    $this->assertSame('pupil', $this->tokenStorage['post_recipients_status']);
    $this->assertSame('2005102345', $this->tokenStorage['post_recipients_1']);
    $this->assertSame('', $this->tokenStorage['post_recipients_2']);
  }

  /**
   * RULE_NONE records a failed step and empties every slot.
   *
   * @covers ::execute
   */
  public function testNoneRuleRecordsFailedStep(): void {
    $this->tokenStorage['child_cpr'] = '9999999999';
    $this->resolver->method('resolveForChild')->willReturn(new RecipientResolution(
      RecipientResolution::RULE_NONE,
      'Birth date unknown.',
      [],
    ));

    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'failed');

    $this->action->execute();

    $this->assertSame('none', $this->tokenStorage['post_recipients_status']);
    $this->assertSame('0', $this->tokenStorage['post_recipients_count']);
    $this->assertSame('', $this->tokenStorage['post_recipients_1']);
    $this->assertSame('', $this->tokenStorage['post_recipients_2']);
  }

  /**
   * The full resolution rides the base result token for auditing.
   *
   * @covers ::execute
   */
  public function testFullResolutionOnBaseToken(): void {
    $this->tokenStorage['child_cpr'] = '0109182345';
    $recipients = [
      ['cpr' => '0101904521', 'full_name' => 'Freja Nielsen', 'role' => 'guardian'],
    ];
    $this->resolver->method('resolveForChild')->willReturn(new RecipientResolution(
      RecipientResolution::RULE_GUARDIANS,
      'Under 15.',
      $recipients,
    ));

    $this->action->execute();

    $this->assertSame('guardians', $this->tokenStorage['post_recipients']['rule']);
    $this->assertSame($recipients, $this->tokenStorage['post_recipients']['recipients']);
  }

}
