<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Unit\Plugin\Action;

use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_workflows\Plugin\Action\NotifyCaseHandlerAction;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface as EcaTokenInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aabenforms_workflows\Plugin\Action\NotifyCaseHandlerAction
 * @group aabenforms_workflows
 */
final class NotifyCaseHandlerActionTest extends UnitTestCase {

  /**
   * Token values by name (bracketed references resolve from here too).
   *
   * @var array<string, mixed>
   */
  private array $tokens = [];

  /**
   * Mails "sent": [key, to, params].
   *
   * @var array[]
   */
  private array $sent = [];

  /**
   * Whether the mail system reports success.
   */
  private bool $mailWorks = TRUE;

  /**
   * Builds the action with recording mail and token stubs.
   */
  private function action(): NotifyCaseHandlerAction {
    $assembler = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $assembler->method('assemble')->willReturnCallback(
      static fn (string $uri): string|GeneratedUrl => 'https://api.example/' . substr($uri, strlen('base:')),
    );
    $container = new ContainerBuilder();
    $container->set('unrouted_url_assembler', $assembler);
    \Drupal::setContainer($container);

    $tokens = $this->createMock(EcaTokenInterface::class);
    $tokens->method('replaceClear')->willReturnCallback(fn (string $text): string => (string) ($this->tokens[trim($text, '[]')] ?? ''));
    $tokens->method('getTokenData')->willReturnCallback(fn (string $name) => $this->tokens[$name] ?? NULL);
    $tokens->method('addTokenData')->willReturnCallback(function (string $name, $value) use ($tokens) {
      $this->tokens[$name] = $value;
      return $tokens;
    });

    $action = new NotifyCaseHandlerAction(
      (new \ReflectionClass(NotifyCaseHandlerAction::class))->newInstanceWithoutConstructor()->defaultConfiguration(),
      'aabenforms_notify_case_handler',
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $tokens,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(EcaState::class),
      $this->createMock(LoggerChannelInterface::class),
    );
    $action->setExecutionCollector($this->createMock(WorkflowExecutionCollector::class));

    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(function ($module, $key, $to, $langcode, $params): array {
      $this->sent[] = [$key, $to, $params];
      return ['result' => $this->mailWorks];
    });
    foreach (['mailManager' => $mail, 'emailValidator' => new EmailValidator()] as $prop => $value) {
      $p = new \ReflectionProperty(NotifyCaseHandlerAction::class, $prop);
      $p->setValue($action, $value);
    }
    return $action;
  }

  /**
   * The leader gets a link and the case number, nothing else.
   */
  public function testSendsMinimalNotice(): void {
    $this->tokens = ['target_institution_leader_email' => 'skoleleder.risskov@example.dk', 'skoleskift_case_id' => '42'];
    $this->action()->execute();
    $this->assertCount(1, $this->sent);
    [$key, $to, $params] = $this->sent[0];
    $this->assertSame('case_handler_notice', $key);
    $this->assertSame('skoleleder.risskov@example.dk', $to);
    $this->assertSame(['case_id' => '42', 'link' => 'https://api.example/sagsbehandling/skoleskift'], $params);
    $this->assertSame('sent', $this->tokens['case_handler_notice_status']);
  }

  /**
   * No valid leader e-mail: nothing is sent, the step says route manually.
   */
  public function testSkipsWithoutValidEmail(): void {
    foreach (['', 'not-an-email'] as $email) {
      $this->sent = [];
      $this->tokens = ['target_institution_leader_email' => $email, 'skoleskift_case_id' => '42'];
      $this->action()->execute();
      $this->assertSame([], $this->sent, "E-mail '$email'");
      $this->assertSame('skipped', $this->tokens['case_handler_notice_status']);
    }
  }

  /**
   * A failing mail system is reported, and a non-numeric case id is dropped.
   */
  public function testMailFailureAndCaseIdHygiene(): void {
    $this->mailWorks = FALSE;
    $this->tokens = ['target_institution_leader_email' => 'leder@example.dk', 'skoleskift_case_id' => 'Emil 0109181234'];
    $this->action()->execute();
    $this->assertSame('', $this->sent[0][2]['case_id'], 'Only a numeric case id reaches the e-mail');
    $this->assertSame('failed', $this->tokens['case_handler_notice_status']);
  }

}
