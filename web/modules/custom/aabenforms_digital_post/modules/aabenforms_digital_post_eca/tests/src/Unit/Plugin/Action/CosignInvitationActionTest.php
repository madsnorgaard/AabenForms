<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post_eca\Unit\Plugin\Action;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface;
use Drupal\aabenforms_digital_post_eca\Plugin\Action\CosignInvitationAction;
use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Tests the co-sign invitation channel selection and failure honesty.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post_eca\Plugin\Action\CosignInvitationAction
 * @group aabenforms_digital_post_eca
 */
class CosignInvitationActionTest extends UnitTestCase {

  /**
   * Mocked Digital Post sender.
   *
   * @var \Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $sender;

  /**
   * Mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mailManager;

  /**
   * Mocked execution collector.
   *
   * @var \Drupal\aabenforms_core\Service\WorkflowExecutionCollector|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $collector;

  /**
   * The action under test (URL builder overridden for unit scope).
   *
   * @var \Drupal\aabenforms_digital_post_eca\Plugin\Action\CosignInvitationAction
   */
  protected CosignInvitationAction $action;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $tokenService = $this->createMock(TokenInterface::class);
    $tokenService->method('getTokenData')->willReturn(NULL);

    // Anonymous subclass: Url::fromRoute needs a bootstrapped container, so
    // the URL builder is overridden with a deterministic stub.
    $this->action = new class(
      [
        'parent_number' => '2',
        'cpr_field' => 'parent2_cpr',
        'email_field' => 'parent2_email',
        'child_name_field' => 'child_name',
        'subject_template' => 'Anmodning om medunderskrift',
      ],
      'aabenforms_send_cosign_invitation',
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $tokenService,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(EcaState::class),
      $this->createMock(LoggerChannelInterface::class),
    ) extends CosignInvitationAction {

      /**
       * The submission injected by the test.
       *
       * @var \Drupal\webform\WebformSubmissionInterface|null
       */
      public ?WebformSubmissionInterface $testSubmission = NULL;

      /**
       * {@inheritdoc}
       */
      protected function buildApprovalUrl(int $slot, int $submissionId, string $token): string {
        return sprintf('https://example.dk/parent-approval/%d/%d/%s', $slot, $submissionId, $token);
      }

      /**
       * {@inheritdoc}
       */
      protected function getSubmission($entity = NULL): ?WebformSubmissionInterface {
        return $this->testSubmission;
      }

    };

    $this->collector = $this->createMock(WorkflowExecutionCollector::class);
    $this->action->setExecutionCollector($this->collector);

    $this->sender = $this->createMock(DigitalPostSenderInterface::class);
    $this->action->setSender($this->sender);

    $tokenSvc = $this->createMock(ApprovalTokenService::class);
    $tokenSvc->method('generateToken')->willReturn('signed-token');
    $this->action->setApprovalTokenService($tokenSvc);

    $cprAccess = $this->createMock(CprAccess::class);
    $cprAccess->method('reveal')->willReturnArgument(0);
    $this->action->setCprAccess($cprAccess);

    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $this->action->setMailManager($this->mailManager);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn ($key) => match ($key) {
        'sender_cvr' => '55133018',
        'sender_name' => 'Demo Kommune',
        default => NULL,
      }
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);
    $this->action->setConfigFactory($configFactory);
  }

  /**
   * Builds a submission mock with the given element data.
   *
   * @param array $data
   *   Element data keyed by field name.
   *
   * @return \Drupal\webform\WebformSubmissionInterface
   *   The mock.
   */
  protected function submission(array $data): WebformSubmissionInterface {
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getElementData')
      ->willReturnCallback(static fn (string $field) => $data[$field] ?? NULL);
    $submission->method('id')->willReturn(42);
    $submission->method('uuid')->willReturn('uuid-42');
    return $submission;
  }

  /**
   * A CPR-bearing co-signer gets the invitation via Digital Post.
   *
   * @covers ::execute
   */
  public function testCprRecipientGetsDigitalPost(): void {
    $this->action->testSubmission = $this->submission([
      'parent2_cpr' => '0803755210',
      'parent2_email' => 'lars@example.dk',
      'child_name' => 'Emil',
    ]);

    $this->sender->expects($this->once())
      ->method('send')
      ->with($this->callback(function (DigitalPost $post): bool {
        return $post->recipient->identifier === '0803755210'
          && str_contains($post->body, 'medunderskrift')
          && str_contains($post->body, 'https://example.dk/parent-approval/2/42/signed-token')
          && str_starts_with($post->meta['transaction_id'], 'cosign_');
      }))
      ->willReturn(Result::success('tx-1'));

    $this->mailManager->expects($this->never())->method('mail');

    $this->action->execute();
  }

  /**
   * A pending (live accepted) send counts as delivered, no email fallback.
   *
   * @covers ::execute
   */
  public function testPendingSendCountsAsSent(): void {
    $this->action->testSubmission = $this->submission([
      'parent2_cpr' => '0803755210',
      'parent2_email' => 'lars@example.dk',
    ]);
    $this->sender->method('send')->willReturn(Result::pending('tx-2'));
    $this->mailManager->expects($this->never())->method('mail');

    $this->action->execute();
  }

  /**
   * Without a CPR the invitation falls back to email.
   *
   * @covers ::execute
   */
  public function testNoCprFallsBackToEmail(): void {
    $this->action->testSubmission = $this->submission([
      'parent2_email' => 'lars@example.dk',
      'child_name' => 'Emil',
    ]);

    $this->sender->expects($this->never())->method('send');
    $this->mailManager->expects($this->once())
      ->method('mail')
      ->with('aabenforms_workflows', 'parent_approval', 'lars@example.dk')
      ->willReturn(['result' => TRUE]);

    $this->action->execute();
  }

  /**
   * A failed Digital Post send falls back to email.
   *
   * @covers ::execute
   */
  public function testFailedSendFallsBackToEmail(): void {
    $this->action->testSubmission = $this->submission([
      'parent2_cpr' => '0803755210',
      'parent2_email' => 'lars@example.dk',
    ]);
    $this->sender->method('send')
      ->willReturn(Result::failure('tx-3', Result::REASON_RECIPIENT_UNKNOWN, 'unknown'));
    $this->mailManager->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $this->action->execute();
  }

  /**
   * No CPR and no valid email records a failed step, never a sent one.
   *
   * @covers ::execute
   */
  public function testNoChannelRecordsFailedStep(): void {
    $this->action->testSubmission = $this->submission([]);

    $this->sender->expects($this->never())->method('send');
    $this->mailManager->expects($this->never())->method('mail');
    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'failed');

    $this->action->execute();
  }

  /**
   * A missing submission records a skipped step.
   *
   * @covers ::execute
   */
  public function testMissingSubmissionSkips(): void {
    $this->action->testSubmission = NULL;

    $this->collector->expects($this->once())
      ->method('addStep')
      ->with($this->anything(), $this->anything(), $this->anything(), 'skipped');

    $this->action->execute();
  }

}
