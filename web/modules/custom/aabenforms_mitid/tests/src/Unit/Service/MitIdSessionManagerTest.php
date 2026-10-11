<?php

namespace Drupal\Tests\aabenforms_mitid\Unit\Service;

use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests MitID session management.
 *
 * Backed by KeyValueExpirable (not PrivateTempStore) so the workflow_id can
 * function as a true bearer capability across origins - the demo SPA reads
 * the session from a different host than the cookie domain.
 *
 * @coversDefaultClass \Drupal\aabenforms_mitid\Service\MitIdSessionManager
 * @group aabenforms_mitid
 */
class MitIdSessionManagerTest extends UnitTestCase {

  /**
   * The session manager service.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $sessionManager;

  /**
   * Mock keyvalue-expirable store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $store;

  /**
   * Mock time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * Mock logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mock audit logger.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $auditLogger;

  /**
   * Current timestamp for testing.
   *
   * @var int
   */
  protected int $currentTime = 1706198400;

  /**
   * Session TTL in seconds (mirrors MitIdSessionManager::SESSION_EXPIRATION).
   *
   * @var int
   */
  protected int $sessionTtl = 900;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the keyvalue-expirable store + the factory that returns it.
    $this->store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $keyValueFactory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyValueFactory->method('get')
      ->with('aabenforms_mitid_sessions')
      ->willReturn($this->store);

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')
      ->willReturn($this->currentTime);

    $this->logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('aabenforms_mitid')
      ->willReturn($this->logger);

    $this->auditLogger = $this->createMock(AuditLogger::class);

    $this->sessionManager = new MitIdSessionManager(
      $keyValueFactory,
      $this->time,
      $loggerFactory,
      $this->auditLogger
    );
  }

  /**
   * Tests storing a session successfully.
   *
   * @covers ::storeSession
   */
  public function testStoreSession(): void {
    $workflowId = 'workflow-123';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'assurance_level' => 'substantial',
      'mitid_uuid' => 'uuid-123',
    ];

    // Expected stored data with metadata.
    $expectedData = $sessionData + [
      'created_at' => $this->currentTime,
      'expires_at' => $this->currentTime + $this->sessionTtl,
      'workflow_id' => $workflowId,
    ];

    $this->store->expects($this->once())
      ->method('setWithExpire')
      ->with($workflowId, $expectedData, $this->sessionTtl);

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        'MitID session stored for workflow: {workflow_id}',
        ['workflow_id' => $workflowId]
      );

    $this->auditLogger->expects($this->once())
      ->method('logWorkflowAccess')
      ->with(
        $workflowId,
        'mitid_session_created',
        'success',
        ['assurance_level' => 'substantial']
      );

    $result = $this->sessionManager->storeSession($workflowId, $sessionData);
    $this->assertTrue($result);
  }

  /**
   * Tests storing session without CPR (no audit log).
   *
   * @covers ::storeSession
   */
  public function testStoreSessionWithoutCpr(): void {
    $workflowId = 'workflow-456';
    $sessionData = [
      'temp_data' => 'value',
    ];

    $this->store->expects($this->once())
      ->method('setWithExpire');

    $this->logger->expects($this->once())
      ->method('info');

    // Should NOT call audit logger without CPR.
    $this->auditLogger->expects($this->never())
      ->method('logWorkflowAccess');

    $result = $this->sessionManager->storeSession($workflowId, $sessionData);
    $this->assertTrue($result);
  }

  /**
   * Tests store session with exception.
   *
   * @covers ::storeSession
   */
  public function testStoreSessionWithException(): void {
    $workflowId = 'workflow-789';
    $sessionData = ['data' => 'value'];

    $this->store->expects($this->once())
      ->method('setWithExpire')
      ->willThrowException(new \Exception('Storage error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to store MitID session: {error}',
        [
          'error' => 'Storage error',
          'workflow_id' => $workflowId,
        ]
      );

    $result = $this->sessionManager->storeSession($workflowId, $sessionData);
    $this->assertFalse($result);
  }

  /**
   * Tests retrieving a valid session.
   *
   * @covers ::getSession
   */
  public function testGetValidSession(): void {
    $workflowId = 'workflow-valid';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'created_at' => $this->currentTime - 300,
      'expires_at' => $this->currentTime + 600,
      'workflow_id' => $workflowId,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->with($workflowId)
      ->willReturn($sessionData);

    $result = $this->sessionManager->getSession($workflowId);
    $this->assertEquals($sessionData, $result);
  }

  /**
   * Tests retrieving non-existent session.
   *
   * @covers ::getSession
   */
  public function testGetNonExistentSession(): void {
    $workflowId = 'workflow-missing';

    $this->store->expects($this->once())
      ->method('get')
      ->with($workflowId)
      ->willReturn(NULL);

    $result = $this->sessionManager->getSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests retrieving expired session.
   *
   * @covers ::getSession
   */
  public function testGetExpiredSession(): void {
    $workflowId = 'workflow-expired';
    $sessionData = [
      'cpr' => '0101001234',
      'created_at' => $this->currentTime - 1000,
      'expires_at' => $this->currentTime - 100,
      'workflow_id' => $workflowId,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->with($workflowId)
      ->willReturn($sessionData);

    // Logger called twice: once for expiration, once for deletion.
    $this->logger->expects($this->exactly(2))
      ->method('info');

    // Should delete expired session.
    $this->store->expects($this->once())
      ->method('delete')
      ->with($workflowId);

    $result = $this->sessionManager->getSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests get session with exception.
   *
   * @covers ::getSession
   */
  public function testGetSessionWithException(): void {
    $workflowId = 'workflow-error';

    $this->store->expects($this->once())
      ->method('get')
      ->willThrowException(new \Exception('Retrieval error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to retrieve MitID session: {error}',
        [
          'error' => 'Retrieval error',
          'workflow_id' => $workflowId,
        ]
      );

    $result = $this->sessionManager->getSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests deleting a session successfully.
   *
   * @covers ::deleteSession
   */
  public function testDeleteSession(): void {
    $workflowId = 'workflow-delete';

    $this->store->expects($this->once())
      ->method('delete')
      ->with($workflowId);

    $this->logger->expects($this->once())
      ->method('info')
      ->with(
        'MitID session deleted for workflow: {workflow_id}',
        ['workflow_id' => $workflowId]
      );

    $this->auditLogger->expects($this->once())
      ->method('logWorkflowAccess')
      ->with(
        $workflowId,
        'mitid_session_deleted',
        'success',
        []
      );

    $result = $this->sessionManager->deleteSession($workflowId);
    $this->assertTrue($result);
  }

  /**
   * Tests delete session with exception.
   *
   * @covers ::deleteSession
   */
  public function testDeleteSessionWithException(): void {
    $workflowId = 'workflow-delete-fail';

    $this->store->expects($this->once())
      ->method('delete')
      ->willThrowException(new \Exception('Delete error'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to delete MitID session: {error}',
        [
          'error' => 'Delete error',
          'workflow_id' => $workflowId,
        ]
      );

    $result = $this->sessionManager->deleteSession($workflowId);
    $this->assertFalse($result);
  }

  /**
   * Tests checking for valid session.
   *
   * @covers ::hasValidSession
   */
  public function testHasValidSessionTrue(): void {
    $workflowId = 'workflow-has-valid';
    $sessionData = [
      'cpr' => '0101001234',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->hasValidSession($workflowId);
    $this->assertTrue($result);
  }

  /**
   * Tests checking for invalid session.
   *
   * @covers ::hasValidSession
   */
  public function testHasValidSessionFalse(): void {
    $workflowId = 'workflow-no-valid';

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn(NULL);

    $result = $this->sessionManager->hasValidSession($workflowId);
    $this->assertFalse($result);
  }

  /**
   * Tests getting CPR from session.
   *
   * @covers ::getCprFromSession
   */
  public function testGetCprFromSession(): void {
    $workflowId = 'workflow-cpr';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->getCprFromSession($workflowId);
    $this->assertEquals('0101001234', $result);
  }

  /**
   * Tests getting CPR from missing session.
   *
   * @covers ::getCprFromSession
   */
  public function testGetCprFromMissingSession(): void {
    $workflowId = 'workflow-no-cpr';

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn(NULL);

    $result = $this->sessionManager->getCprFromSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests getting CPR from session without CPR field.
   *
   * @covers ::getCprFromSession
   */
  public function testGetCprFromSessionWithoutCprField(): void {
    $workflowId = 'workflow-no-cpr-field';
    $sessionData = [
      'name' => 'Test Testesen',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->getCprFromSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests getting person data from session.
   *
   * @covers ::getPersonDataFromSession
   */
  public function testGetPersonDataFromSession(): void {
    $workflowId = 'workflow-person';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'given_name' => 'Test',
      'family_name' => 'Testesen',
      'birthdate' => '2000-01-01',
      'email' => 'test@example.dk',
      'assurance_level' => 'substantial',
      'mitid_uuid' => 'uuid-123',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->getPersonDataFromSession($workflowId);

    $expected = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'given_name' => 'Test',
      'family_name' => 'Testesen',
      'birthdate' => '2000-01-01',
      'email' => 'test@example.dk',
      'assurance_level' => 'substantial',
      'mitid_uuid' => 'uuid-123',
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Tests getting person data from partial session.
   *
   * @covers ::getPersonDataFromSession
   */
  public function testGetPersonDataFromPartialSession(): void {
    $workflowId = 'workflow-partial';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->getPersonDataFromSession($workflowId);

    $expected = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'given_name' => NULL,
      'family_name' => NULL,
      'birthdate' => NULL,
      'email' => NULL,
      'assurance_level' => NULL,
      'mitid_uuid' => NULL,
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Tests getting person data from missing session.
   *
   * @covers ::getPersonDataFromSession
   */
  public function testGetPersonDataFromMissingSession(): void {
    $workflowId = 'workflow-no-person';

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn(NULL);

    $result = $this->sessionManager->getPersonDataFromSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests getAddressFromSession returns NULL when no address keys present.
   *
   * @covers ::getAddressFromSession
   */
  public function testGetAddressFromSessionNoAddress(): void {
    $workflowId = 'workflow-no-addr';
    $sessionData = [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->getAddressFromSession($workflowId);
    $this->assertNull($result);
  }

  /**
   * Tests getAddressFromSession early-returns NULL when the session itself is gone.
   *
   * Distinct from the no-address-keys path - this exercises the line that
   * checks getSession() === NULL before looking at the address keys.
   *
   * @covers ::getAddressFromSession
   */
  public function testGetAddressFromMissingSession(): void {
    $workflowId = 'workflow-no-session';

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn(NULL);

    $this->assertNull($this->sessionManager->getAddressFromSession($workflowId));
  }

  /**
   * Tests getAddressFromSession returns the partial address block.
   *
   * Only one of the four address keys is present; the others land as NULL
   * in the returned array.
   *
   * @covers ::getAddressFromSession
   */
  public function testGetAddressFromSessionPartialAddress(): void {
    $workflowId = 'workflow-partial-addr';
    $sessionData = [
      'cpr' => '0101001234',
      'street' => 'Nørrebrogade 142',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $this->assertSame([
      'street' => 'Nørrebrogade 142',
      'postal_code' => NULL,
      'city' => NULL,
      'municipality_code' => NULL,
    ], $this->sessionManager->getAddressFromSession($workflowId));
  }

  /**
   * Tests hasValidSession returns FALSE when stored data is past its expires_at.
   *
   * Covers the keyvalue-TTL-drift defense-in-depth branch.
   *
   * @covers ::hasValidSession
   * @covers ::getSession
   */
  public function testHasValidSessionFalseForExpired(): void {
    $workflowId = 'workflow-expired';
    $expired = [
      'cpr' => '0101001234',
      'expires_at' => $this->currentTime - 1,
      'workflow_id' => $workflowId,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($expired);
    $this->store->expects($this->once())
      ->method('delete')
      ->with($workflowId);

    $this->assertFalse($this->sessionManager->hasValidSession($workflowId));
  }

  /**
   * Tests getSession returns NULL when the store returns a non-array value.
   *
   * @covers ::getSession
   */
  public function testGetSessionReturnsNullWhenStoreReturnsNonArray(): void {
    $workflowId = 'workflow-corrupt';

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn('not-an-array');

    $this->assertNull($this->sessionManager->getSession($workflowId));
  }

  /**
   * Tests getAddressFromSession returns the address block when present.
   *
   * @covers ::getAddressFromSession
   */
  public function testGetAddressFromSessionWithAddress(): void {
    $workflowId = 'workflow-addr';
    $sessionData = [
      'cpr' => '0101001234',
      'street' => 'Nørrebrogade 142, 3. tv.',
      'postal_code' => '2200',
      'city' => 'København N',
      'municipality_code' => '0101',
      'expires_at' => $this->currentTime + 600,
    ];

    $this->store->expects($this->once())
      ->method('get')
      ->willReturn($sessionData);

    $result = $this->sessionManager->getAddressFromSession($workflowId);

    $expected = [
      'street' => 'Nørrebrogade 142, 3. tv.',
      'postal_code' => '2200',
      'city' => 'København N',
      'municipality_code' => '0101',
    ];

    $this->assertEquals($expected, $result);
  }

  /**
   * Tests seeding a demo persona stores a real-shaped session.
   *
   * @covers ::seedDemoSession
   */
  public function testSeedDemoSessionStoresPersona(): void {
    putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    $captured = NULL;
    $this->store->expects($this->once())
      ->method('setWithExpire')
      ->willReturnCallback(function ($key, $value, $ttl) use (&$captured): void {
        $captured = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
      });

    $workflowId = $this->sessionManager->seedDemoSession('freja');

    $this->assertIsString($workflowId);
    $this->assertStringStartsWith('wf_', $workflowId);
    $this->assertSame($workflowId, $captured['key']);
    $this->assertSame('0101904521', $captured['value']['cpr']);
    $this->assertSame('Freja Nielsen', $captured['value']['name']);
    $this->assertTrue($captured['value']['demo_seeded']);
    $this->assertSame('freja', $captured['value']['persona']);
    $this->assertSame($this->sessionTtl, $captured['ttl']);
  }

  /**
   * Tests an explicit workflow id is honoured.
   *
   * @covers ::seedDemoSession
   */
  public function testSeedDemoSessionExplicitWorkflowId(): void {
    putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    $this->store->expects($this->once())->method('setWithExpire');

    $result = $this->sessionManager->seedDemoSession('lars', 'wf_fixed');

    $this->assertSame('wf_fixed', $result);
  }

  /**
   * Tests an unknown persona is refused without touching storage.
   *
   * @covers ::seedDemoSession
   */
  public function testSeedDemoSessionUnknownPersona(): void {
    putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    $this->store->expects($this->never())->method('setWithExpire');
    $this->logger->expects($this->once())->method('warning');

    $this->assertNull($this->sessionManager->seedDemoSession('nobody'));
  }

  /**
   * Tests a seeded identity can never exist outside local development.
   *
   * @covers ::seedDemoSession
   */
  public function testSeedDemoSessionRefusedOutsideDdev(): void {
    putenv('DDEV_HOSTNAME');
    putenv('AABENFORMS_DEMO_SITE=1');
    $this->store->expects($this->never())->method('setWithExpire');

    $this->assertNull($this->sessionManager->seedDemoSession('freja'), 'Not even on the public demo site');
    putenv('AABENFORMS_DEMO_SITE');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Unit tests share one process: restore the real environment.
    $original = $_SERVER['DDEV_HOSTNAME'] ?? $_ENV['DDEV_HOSTNAME'] ?? '';
    putenv($original === '' ? 'DDEV_HOSTNAME' : 'DDEV_HOSTNAME=' . $original);
    putenv('AABENFORMS_DEMO_SITE');
    parent::tearDown();
  }

}
