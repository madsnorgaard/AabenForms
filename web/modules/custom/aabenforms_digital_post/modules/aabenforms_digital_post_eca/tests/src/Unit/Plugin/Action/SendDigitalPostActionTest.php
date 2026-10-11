<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post_eca\Unit\Plugin\Action;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface;
use Drupal\aabenforms_digital_post_eca\Plugin\Action\SendDigitalPostAction;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Tests SendDigitalPostAction's three recipient-resolver strategies.
 *
 * The action is constructed via the parent eca ActionBase constructor
 * with mocked deps, and its child-specific deps (sender, configFactory,
 * executionCollector) are wired via the public setters introduced for
 * #21. No reflection.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post_eca\Plugin\Action\SendDigitalPostAction
 * @group aabenforms_digital_post
 */
class SendDigitalPostActionTest extends UnitTestCase {

  /**
   * Mocked Digital Post sender.
   *
   * @var \Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected DigitalPostSenderInterface $sender;

  /**
   * Mocked eca token service.
   *
   * @var \Drupal\eca\Token\TokenInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TokenInterface $tokenService;

  /**
   * Mocked execution collector.
   *
   * @var \Drupal\aabenforms_core\Service\WorkflowExecutionCollector|\PHPUnit\Framework\MockObject\MockObject
   */
  protected WorkflowExecutionCollector $executionCollector;

  /**
   * Mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Builds an action with all child-specific deps wired via setters.
   *
   * Tokens written via $this->tokenService->addTokenData() are captured
   * into &$writtenTokens so the test can assert on the result token.
   */
  private function buildAction(array $configuration, array &$writtenTokens = []): SendDigitalPostAction {
    $this->tokenService = $this->createMock(TokenInterface::class);
    $this->tokenService->method('addTokenData')
      ->willReturnCallback(function (string $name, $value) use (&$writtenTokens): TokenInterface {
        $writtenTokens[$name] = $value;
        return $this->tokenService;
      });
    $this->sender = $this->createMock(DigitalPostSenderInterface::class);
    $this->executionCollector = $this->createMock(WorkflowExecutionCollector::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    // Parent constructor deps; all interfaces or trivially-mockable.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $time = $this->createMock(TimeInterface::class);
    $ecaState = $this->getMockBuilder(EcaState::class)
      ->disableOriginalConstructor()
      ->getMock();
    $logger = $this->createMock(LoggerChannelInterface::class);

    $action = new SendDigitalPostAction(
      $configuration,
      'aabenforms_digital_post_send',
      ['provider' => 'aabenforms_digital_post_eca'],
      $entityTypeManager,
      $this->tokenService,
      $currentUser,
      $time,
      $ecaState,
      $logger,
    );
    $action->setSender($this->sender);
    $action->setConfigFactory($this->configFactory);
    $action->setExecutionCollector($this->executionCollector);
    // CPR access helper: reveal is a pass-through for these tests.
    $cprAccess = $this->createMock(CprAccess::class);
    $cprAccess->method('reveal')->willReturnArgument(0);
    $action->setCprAccess($cprAccess);
    return $action;
  }

  /**
   * Configuration sufficient for execute() - subclasses tweak per test.
   */
  private function defaultConfig(array $overrides = []): array {
    return $overrides + [
      'recipient_token' => '[citizen_session:cpr]',
      'recipient_type' => 'cpr',
      'sender_cvr_token' => '',
      'subject_template' => 'Afgørelse',
      'body_template' => '<p>Se bilag.</p>',
      'type' => DigitalPost::TYPE_DIGITAL_POST,
      'result_token' => 'digital_post_result',
    ];
  }

  /**
   * Stubs the ConfigFactory so Sender::fromConfig() returns a valid Sender.
   */
  private function stubSenderConfig(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['sender_cvr', '12345678'],
      ['sender_name', 'Test Kommune'],
    ]);
    $this->configFactory->method('get')
      ->with('aabenforms_digital_post.settings')
      ->willReturn($config);
  }

  /**
   * Strategy 1: webform-submission token reads via getElementData().
   *
   * The test mocks WebformSubmissionInterface (not \stdClass) so
   * method_exists($entity, 'getElementData') returns TRUE and the
   * production code's strategy-1 branch is actually exercised.
   */
  public function testTemplateReadsSubmissionValuesButNeverCpr(): void {
    $writtenTokens = [];
    $action = $this->buildAction($this->defaultConfig([
      'recipient_token' => '[webform_submission:values:applicant_cpr:raw]',
      'subject_template' => 'Sag [webform_submission:values:case_id]',
      'body_template' => '<p>Sag nr. [webform_submission:values:case_id] om [webform_submission:values:note]. CPR: [webform_submission:values:applicant_cpr:raw]</p>',
    ]), $writtenTokens);
    $this->stubSenderConfig();

    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getElementData')->willReturnMap([
      ['applicant_cpr', '0101900001'],
      ['case_id', '217'],
      ['note', '<b>tekst</b>'],
    ]);
    $action->setEvent($this->createMockEventWithEntity($submission));

    $this->sender->method('testMode')->willReturn('fake_db');
    $this->sender->expects($this->once())
      ->method('send')
      ->with($this->callback(static function (DigitalPost $post): bool {
        return $post->subject === 'Sag 217'
          // Escaped, never markup; the CPR token is left unrendered.
          && $post->body === '<p>Sag nr. 217 om &lt;b&gt;tekst&lt;/b&gt;. CPR: [webform_submission:values:applicant_cpr:raw]</p>';
      }))
      ->willReturn(Result::success('tx-t', 'sent'));

    $action->execute();
    $this->assertSame('success', $writtenTokens['digital_post_result_status']);
  }

  /**
   * Strategy 1 for the recipient (see above).
   */
  public function testRecipientStrategy1ReadsFromWebformSubmission(): void {
    $writtenTokens = [];
    $action = $this->buildAction(
      $this->defaultConfig(['recipient_token' => '[webform_submission:values:cpr:raw]']),
      $writtenTokens,
    );
    $this->stubSenderConfig();

    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getElementData')->with('cpr')->willReturn('0101900001');

    $event = $this->createMockEventWithEntity($submission);
    $action->setEvent($event);

    $this->sender->method('testMode')->willReturn('fake_db');
    $this->sender->expects($this->once())
      ->method('send')
      ->with($this->callback(static function (DigitalPost $post): bool {
        return $post->recipient->type === Recipient::TYPE_CPR
          && $post->recipient->identifier === '0101900001';
      }))
      ->willReturn(Result::success('tx-1', 'sent'));

    $action->execute();

    $this->assertSame(TRUE, $writtenTokens['digital_post_result']['success']);
    $this->assertSame('tx-1', $writtenTokens['digital_post_result']['transaction_id']);
    // The status-token companion an eca_scalar gate branches on.
    $this->assertSame('success', $writtenTokens['digital_post_result_status']);
  }

  /**
   * Strategy 2: a non-webform-pattern token falls back to tokenService.
   */
  public function testRecipientStrategy2ReadsFromTokenService(): void {
    $writtenTokens = [];
    $action = $this->buildAction($this->defaultConfig(), $writtenTokens);
    $this->stubSenderConfig();

    // tokenService->getTokenData('citizen_session:cpr') returns the
    // identifier; the [..] brackets are trimmed by the production code.
    $this->tokenService->method('getTokenData')
      ->willReturnCallback(static fn (?string $key) => $key === 'citizen_session:cpr' ? '0101900001' : NULL);

    $this->sender->method('testMode')->willReturn('fake_db');
    $this->sender->expects($this->once())
      ->method('send')
      ->willReturn(Result::success('tx-2'));

    $action->execute();

    $this->assertSame(TRUE, $writtenTokens['digital_post_result']['success']);
  }

  /**
   * Empty recipient short-circuits to a "skipped" step + RECIPIENT_EMPTY result.
   */
  public function testEmptyRecipientSkipsAndWritesReason(): void {
    $writtenTokens = [];
    $action = $this->buildAction($this->defaultConfig(), $writtenTokens);

    // tokenService returns NULL so resolveRecipient() returns ''.
    $this->tokenService->method('getTokenData')->willReturn(NULL);

    // Sender must not be called when the recipient is empty.
    $this->sender->expects($this->never())->method('send');

    $this->executionCollector->expects($this->once())
      ->method('addStep')
      ->with('aabenforms_digital_post_send', 'Digital Post skipped', $this->anything(), 'skipped');

    $action->execute();

    $this->assertSame(FALSE, $writtenTokens['digital_post_result']['success']);
    $this->assertSame('RECIPIENT_EMPTY', $writtenTokens['digital_post_result']['reason_code']);
    $this->assertSame('failed', $writtenTokens['digital_post_result_status'], 'A failed send sets the status companion to failed so the flow does not close the case');
  }

  /**
   * Invalid CPR triggers the catch \Throwable branch.
   *
   * A wrong-length CPR fails Recipient::cpr() inside the try block, so
   * the sender is never called and the VALIDATION reason is written
   * back to the result token.
   */
  public function testInvalidCprFailsValidationAndDoesNotCallSender(): void {
    $writtenTokens = [];
    $action = $this->buildAction($this->defaultConfig(), $writtenTokens);
    $this->stubSenderConfig();

    // A 5-digit CPR fails Recipient::cpr() inside the try block.
    $this->tokenService->method('getTokenData')
      ->willReturnCallback(static fn (?string $key) => $key === 'citizen_session:cpr' ? '12345' : NULL);

    $this->sender->expects($this->never())->method('send');

    // The failure path records one step via handleError() and one via
    // recordStep('Digital Post failed') - capture all calls and verify
    // a 'Digital Post failed' step was emitted.
    $steps = [];
    $this->executionCollector->method('addStep')
      ->willReturnCallback(function (...$args) use (&$steps): void {
        $steps[] = $args;
      });

    $action->execute();

    $this->assertSame(FALSE, $writtenTokens['digital_post_result']['success']);
    $this->assertSame('VALIDATION', $writtenTokens['digital_post_result']['reason_code']);
    $labels = array_column($steps, 1);
    $this->assertContains('Digital Post failed', $labels);
  }

  /**
   * Sender failure is forwarded with reason code intact.
   *
   * A failure Result from the sender flows back to the result token
   * with the original reason code preserved.
   */
  public function testSenderFailureForwardsReasonCode(): void {
    $writtenTokens = [];
    $action = $this->buildAction($this->defaultConfig(), $writtenTokens);
    $this->stubSenderConfig();

    $this->tokenService->method('getTokenData')
      ->willReturnCallback(static fn (?string $key) => $key === 'citizen_session:cpr' ? '0101900001' : NULL);

    $this->sender->method('testMode')->willReturn('live');
    $this->sender->method('send')->willReturn(
      Result::failure('tx-9', Result::REASON_RECIPIENT_NOT_REACHABLE, 'no Digital Post inbox')
    );

    $action->execute();

    $this->assertSame(FALSE, $writtenTokens['digital_post_result']['success']);
    $this->assertSame(Result::REASON_RECIPIENT_NOT_REACHABLE, $writtenTokens['digital_post_result']['reason_code']);
    $this->assertSame('tx-9', $writtenTokens['digital_post_result']['transaction_id']);
  }

  /**
   * Sender_cvr_token override skips Sender::fromConfig() entirely.
   */
  public function testSenderCvrOverrideSkipsConfig(): void {
    $writtenTokens = [];
    $action = $this->buildAction(
      $this->defaultConfig(['sender_cvr_token' => 'override_cvr']),
      $writtenTokens,
    );

    // Override + recipient both come from tokenService.
    $this->tokenService->method('getTokenData')
      ->willReturnCallback(static function (?string $key) {
        return match ($key) {
          'citizen_session:cpr' => '0101900001',
          'override_cvr' => '99887766',
          default => NULL,
        };
      });

    $this->configFactory->expects($this->never())->method('get');

    $this->sender->method('testMode')->willReturn('fake_db');
    $this->sender->expects($this->once())
      ->method('send')
      ->with($this->callback(static fn (DigitalPost $p): bool => $p->sender->cvr === '99887766'))
      ->willReturn(Result::success('tx-7'));

    $action->execute();

    $this->assertSame(TRUE, $writtenTokens['digital_post_result']['success']);
  }

  /**
   * Wraps an entity in an anonymous Event subclass exposing getEntity().
   *
   * The production code's eventEntity() helper picks the entity up via
   * either getEntity() or getContext(); this is the simplest shape.
   */
  private function createMockEventWithEntity(WebformSubmissionInterface $entity): Event {
    return new class($entity) extends Event {

      public function __construct(private readonly WebformSubmissionInterface $entity) {}

      /**
       * Returns the wrapped webform submission entity.
       */
      public function getEntity(): WebformSubmissionInterface {
        return $this->entity;
      }

    };
  }

}
