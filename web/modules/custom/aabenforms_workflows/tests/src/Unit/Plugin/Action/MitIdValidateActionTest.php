<?php

namespace Drupal\Tests\aabenforms_workflows\Unit\Plugin\Action;

use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\aabenforms_workflows\Plugin\Action\MitIdValidateAction;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\eca\EcaState;
use Drupal\eca\Token\TokenInterface as EcaTokenInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Tests the MitIdValidateAction ECA plugin.
 *
 * @coversDefaultClass \Drupal\aabenforms_workflows\Plugin\Action\MitIdValidateAction
 * @group aabenforms_workflows
 */
class MitIdValidateActionTest extends UnitTestCase {

  /**
   * The MitIdValidateAction plugin.
   *
   * @var \Drupal\aabenforms_workflows\Plugin\Action\MitIdValidateAction
   */
  protected MitIdValidateAction $action;

  /**
   * Mock MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $sessionManager;

  /**
   * Mock logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mock ECA token services.
   *
   * @var \Drupal\eca\Token\TokenInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $tokenServices;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mock current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mock time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * Mock ECA state service.
   *
   * @var \Drupal\eca\EcaState|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $ecaState;

  /**
   * Token storage for testing.
   *
   * @var array
   */
  protected array $tokenStorage = [];

  /**
   * Current timestamp for testing.
   *
   * @var int
   */
  protected int $currentTime = 1706198400;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock all ECA ActionBase dependencies.
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->ecaState = $this->createMock(EcaState::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    // Mock ECA token services.
    $this->tokenServices = $this->createMock(EcaTokenInterface::class);
    $this->tokenServices->method('getTokenData')
      ->willReturnCallback(
              function ($name) {
                  return $this->tokenStorage[$name] ?? NULL;
              }
          );
    $this->tokenServices->method('addTokenData')
      ->willReturnCallback(
              function ($name, $value) {
                  $this->tokenStorage[$name] = $value;
                  return $this->tokenServices;
              }
          );

    // Mock session manager.
    $this->sessionManager = $this->createMock(MitIdSessionManager::class);

    // Create action instance with all ECA dependencies.
    $configuration = [
      'workflow_id_token' => 'workflow_id',
      'result_token' => 'mitid_valid',
      'session_data_token' => 'mitid_session',
    ];

    $this->action = new MitIdValidateAction(
          $configuration,
          'aabenforms_mitid_validate',
          [],
          $this->entityTypeManager,
          $this->tokenServices,
          $this->currentUser,
          $this->time,
          $this->ecaState,
          $this->logger
      );
    $this->action->setExecutionCollector($this->createMock(WorkflowExecutionCollector::class));

    // Inject session manager using reflection.
    $reflectionClass = new \ReflectionClass($this->action);
    $sessionManagerProperty = $reflectionClass->getProperty('sessionManager');
    $sessionManagerProperty->setAccessible(TRUE);
    $sessionManagerProperty->setValue($this->action, $this->sessionManager);

    // Inject a config factory with MitID demo mode OFF (fail-closed default).
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(FALSE);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $configFactoryProperty = $reflectionClass->getProperty('configFactory');
    $configFactoryProperty->setAccessible(TRUE);
    $configFactoryProperty->setValue($this->action, $configFactory);

    // Default request stack: a request whose session is not started, so the
    // browser-session bridge contributes nothing unless a test opts in via
    // setBrowserWorkflowId(). This keeps the existing fail-closed tests valid.
    $this->setBrowserWorkflowId(NULL, FALSE);
  }

  /**
   * Injects a request stack with the given workflow-id sources populated.
   *
   * Mirrors the three request-side sources resolveWorkflowId() consults: the
   * submission payload (stashed on the request as an attribute by the webform
   * controller), a `workflow_id` query param, and the login-bound browser
   * session `mitid_workflow_id`.
   *
   * @param array $sources
   *   Any of: 'attribute', 'query', 'session' (string values), and
   *   'session_started' (bool, default TRUE).
   */
  protected function setRequestContext(array $sources = []): void {
    $session = $this->createMock(SessionInterface::class);
    $session->method('isStarted')->willReturn($sources['session_started'] ?? TRUE);
    $session->method('get')->willReturnCallback(
      static fn ($key) => $key === 'mitid_workflow_id' ? ($sources['session'] ?? NULL) : NULL
    );

    $request = new Request();
    $request->setSession($session);
    if (isset($sources['attribute'])) {
      $request->attributes->set('aabenforms_workflow_id', $sources['attribute']);
    }
    if (isset($sources['query'])) {
      $request->query->set('workflow_id', $sources['query']);
    }

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);

    $property = (new \ReflectionClass($this->action))->getProperty('requestStack');
    $property->setAccessible(TRUE);
    $property->setValue($this->action, $requestStack);
  }

  /**
   * Convenience: only the browser session carries the workflow id.
   *
   * @param string|null $workflowId
   *   The value under `mitid_workflow_id`, or NULL for none.
   * @param bool $started
   *   Whether the session reports itself as started.
   */
  protected function setBrowserWorkflowId(?string $workflowId, bool $started = TRUE): void {
    $this->setRequestContext([
      'session' => $workflowId,
      'session_started' => $started,
    ]);
  }

  /**
   * Tests validation with a valid MitID session.
   *
   * @covers ::execute
   */
  public function testValidSession(): void {
    $workflowId = 'workflow-123';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'assurance_level' => 'substantial',
      'created_at' => time() - 300,
      'expires_at' => time() + 600,
    ];

    // Set workflow ID token.
    $this->tokenStorage['workflow_id'] = $workflowId;

    // Mock session manager to return valid session.
    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willReturn($sessionData);

    // Expect success log.
    $this->logger->expects($this->once())
      ->method('info');

    // Execute action.
    $this->action->execute();

    // Verify result tokens.
    $this->assertTrue($this->tokenStorage['mitid_valid'], 'mitid_valid token should be TRUE');
    $this->assertEquals($sessionData, $this->tokenStorage['mitid_session'], 'mitid_session should contain session data');
  }

  /**
   * Tests validation with an expired MitID session.
   *
   * @covers ::execute
   */
  public function testExpiredSession(): void {
    $workflowId = 'workflow-expired';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'created_at' => time() - 1000,
      'expires_at' => time() - 100,
    ];

    // Set workflow ID token.
    $this->tokenStorage['workflow_id'] = $workflowId;

    // Mock session manager to return expired session.
    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willReturn($sessionData);

    // Expect warning log for expired session.
    $this->logger->expects($this->once())
      ->method('warning');

    // Execute action.
    $this->action->execute();

    // Verify result is FALSE.
    $this->assertFalse($this->tokenStorage['mitid_valid']);
  }

  /**
   * Tests validation with missing MitID session.
   *
   * With demo mode OFF (the default), a missing session fails CLOSED: warning
   * log, result token FALSE, and a failed step - never a false "verified".
   *
   * @covers ::execute
   */
  public function testMissingSession(): void {
    $workflowId = 'workflow-missing';

    // Set workflow ID token.
    $this->tokenStorage['workflow_id'] = $workflowId;

    // Mock session manager to return NULL (no session).
    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willReturn(NULL);

    // Fail-closed: warning log, never a false-success info.
    $this->logger->expects($this->once())
      ->method('warning');

    // Execute action.
    $this->action->execute();

    // Verify result is FALSE (fail closed).
    $this->assertFalse($this->tokenStorage['mitid_valid']);
  }

  /**
   * Tests CPR extraction from session data.
   *
   * @covers ::execute
   */
  public function testCprExtraction(): void {
    $workflowId = 'workflow-cpr';
    $expectedCpr = '0101001234';
    $sessionData = [
      'cpr' => $expectedCpr,
      'name' => 'Test Testesen',
      'given_name' => 'Test',
      'family_name' => 'Testesen',
      'birthdate' => '2000-01-01',
      'email' => 'test@example.dk',
      'assurance_level' => 'substantial',
      'mitid_uuid' => 'uuid-123',
      'created_at' => time() - 300,
      'expires_at' => time() + 600,
    ];

    // Set workflow ID token.
    $this->tokenStorage['workflow_id'] = $workflowId;

    // Mock session manager.
    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willReturn($sessionData);

    // Execute action.
    $this->action->execute();

    // Verify session data token contains CPR.
    $this->assertArrayHasKey('mitid_session', $this->tokenStorage, 'mitid_session token should be set');
    $storedSession = $this->tokenStorage['mitid_session'];
    $this->assertIsArray($storedSession, 'Session data should be an array');
    $this->assertArrayHasKey('cpr', $storedSession, 'Session should contain CPR');
    $this->assertEquals($expectedCpr, $storedSession['cpr'], 'CPR should match');

    // Verify all person data is preserved.
    $this->assertEquals('Test Testesen', $storedSession['name']);
    $this->assertEquals('substantial', $storedSession['assurance_level']);
    $this->assertEquals('uuid-123', $storedSession['mitid_uuid']);
  }

  /**
   * Tests error handling and event dispatching.
   *
   * @covers ::execute
   */
  public function testErrorHandling(): void {
    $workflowId = 'workflow-error';

    // Set workflow ID token.
    $this->tokenStorage['workflow_id'] = $workflowId;

    // Mock session manager to throw exception.
    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willThrowException(new \Exception('Database connection error'));

    // handleError() routes to log('error', ...). The injected channel sees
    // the call as logger->error(message, context). Context contains
    // 'message', 'context' (the context_message passed by execute()),
    // 'exception', and 'action' (added by the base log() helper).
    $this->logger->expects($this->once())
      ->method('error')
      ->with(
              $this->stringContains('Action failed'),
              $this->callback(
                  function ($context) {
                      return isset($context['message']) &&
                       $context['message'] === 'Database connection error' &&
                       isset($context['context']) &&
                       $context['context'] === 'MitID Identity Validation';
                  }
              )
          );

    // Execute action.
    $this->action->execute();

    // Verify result is FALSE on exception.
    $this->assertFalse($this->tokenStorage['mitid_valid']);
  }

  /**
   * Tests validation without workflow ID.
   *
   * With demo mode OFF (the default), a missing workflow id fails CLOSED:
   * warning log, result token FALSE, and the session manager is never
   * consulted.
   *
   * @covers ::execute
   */
  public function testMissingWorkflowId(): void {
    // Don't set workflow_id token (simulate missing context).
    // Fail-closed: warning, no false-success info.
    $this->logger->expects($this->once())
      ->method('warning');

    // Session manager should NOT be called.
    $this->sessionManager->expects($this->never())
      ->method('getSession');

    // Execute action.
    $this->action->execute();

    // Verify result is FALSE (fail closed).
    $this->assertFalse($this->tokenStorage['mitid_valid']);
  }

  /**
   * Tests the browser-session bridge resolves the workflow id.
   *
   * When the configured token is empty (the usual case for a real submission,
   * where nothing populates `workflow_id_<flow>`), the action falls back to
   * the `mitid_workflow_id` the login bound to the browser session, so the
   * gate finds the citizen's session and validates.
   *
   * @covers ::execute
   * @covers ::resolveWorkflowId
   */
  public function testWorkflowIdFromBrowserSession(): void {
    $workflowId = 'wf_browserbound';
    $sessionData = [
      'cpr' => '0101904521',
      'name' => 'Freja Nielsen',
      'assurance_level' => 'substantial',
      'created_at' => time() - 60,
      'expires_at' => time() + 600,
    ];

    // Token is NOT set; the only source is the browser session.
    $this->setBrowserWorkflowId($workflowId);

    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willReturn($sessionData);

    $this->action->execute();

    $this->assertTrue($this->tokenStorage['mitid_valid'], 'Bridge should let a valid session validate.');
    $this->assertEquals($sessionData, $this->tokenStorage['mitid_session']);
  }

  /**
   * Tests the cross-origin path: workflow id from the submission payload.
   *
   * The webform controller stashes the SPA-supplied workflow_id on the request
   * as an attribute; the gate reads it when no cookie/token is present.
   *
   * @covers ::resolveWorkflowId
   */
  public function testWorkflowIdFromRequestPayload(): void {
    $workflowId = 'wf_from_payload';
    $sessionData = [
      'cpr' => '2506924015',
      'name' => 'Sofie Hansen',
      'created_at' => time() - 60,
      'expires_at' => time() + 600,
    ];

    // No token, no browser session - only the request attribute.
    $this->setRequestContext(['attribute' => $workflowId, 'session_started' => FALSE]);

    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with($workflowId)
      ->willReturn($sessionData);

    $this->action->execute();

    $this->assertTrue($this->tokenStorage['mitid_valid'], 'Payload-supplied id should validate.');
  }

  /**
   * Tests the payload id is preferred over the browser session.
   *
   * @covers ::resolveWorkflowId
   */
  public function testPayloadPreferredOverBrowserSession(): void {
    $this->setRequestContext([
      'attribute' => 'wf_payload',
      'session' => 'wf_browser',
    ]);

    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with('wf_payload')
      ->willReturn(NULL);

    $this->logger->expects($this->once())->method('warning');

    $this->action->execute();

    $this->assertFalse($this->tokenStorage['mitid_valid']);
  }

  /**
   * Tests the configured token wins over the browser session.
   *
   * @covers ::resolveWorkflowId
   */
  public function testTokenTakesPrecedenceOverBrowserSession(): void {
    $this->tokenStorage['workflow_id'] = 'wf_from_token';
    $this->setBrowserWorkflowId('wf_from_browser');

    $this->sessionManager->expects($this->once())
      ->method('getSession')
      ->with('wf_from_token')
      ->willReturn(NULL);

    // No session for the token id -> fail closed, browser id never consulted.
    $this->logger->expects($this->once())->method('warning');

    $this->action->execute();

    $this->assertFalse($this->tokenStorage['mitid_valid']);
  }

  /**
   * Tests configuration form default values.
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfiguration(): void {
    $defaults = $this->action->defaultConfiguration();

    $this->assertArrayHasKey('workflow_id_token', $defaults);
    $this->assertEquals('workflow_id', $defaults['workflow_id_token']);

    $this->assertArrayHasKey('result_token', $defaults);
    $this->assertEquals('mitid_valid', $defaults['result_token']);

    $this->assertArrayHasKey('session_data_token', $defaults);
    $this->assertEquals('mitid_session', $defaults['session_data_token']);

    $this->assertArrayHasKey('required_assurance_level', $defaults);
    $this->assertSame('', $defaults['required_assurance_level'], 'No per-flow assurance requirement by default.');
  }

  /**
   * A session below the flow's required assurance level is denied at the gate.
   *
   * @covers ::execute
   */
  public function testAssuranceBelowRequiredIsDenied(): void {
    $workflowId = 'wf_low_assurance';
    $this->tokenStorage['workflow_id'] = $workflowId;
    $this->sessionManager->method('getSession')->with($workflowId)->willReturn([
      'cpr' => '0101001234',
      'assurance_level' => 'substantial',
      'expires_at' => time() + 600,
    ]);
    $this->setActionConfig('required_assurance_level', 'high');

    $this->logger->expects($this->once())->method('warning');
    $this->action->execute();

    $this->assertFalse($this->tokenStorage['mitid_valid'], 'A substantial session must not satisfy a high-assurance flow.');
  }

  /**
   * A session at or above the required assurance level passes the gate.
   *
   * @covers ::execute
   */
  public function testAssuranceMeetsRequirement(): void {
    $workflowId = 'wf_high_assurance';
    $this->tokenStorage['workflow_id'] = $workflowId;
    $this->sessionManager->method('getSession')->with($workflowId)->willReturn([
      'cpr' => '0101001234',
      'assurance_level' => 'high',
      'expires_at' => time() + 600,
    ]);
    $this->setActionConfig('required_assurance_level', 'substantial');

    $this->action->execute();

    $this->assertTrue($this->tokenStorage['mitid_valid'], 'A high session satisfies a substantial-assurance flow.');
  }

  /**
   * Sets a single configuration value on the action under test.
   */
  private function setActionConfig(string $key, $value): void {
    $property = (new \ReflectionClass($this->action))->getProperty('configuration');
    $property->setAccessible(TRUE);
    $config = $property->getValue($this->action);
    $config[$key] = $value;
    $property->setValue($this->action, $config);
  }

}
