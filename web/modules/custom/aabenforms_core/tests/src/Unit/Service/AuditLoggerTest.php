<?php

namespace Drupal\Tests\aabenforms_core\Unit\Service;

use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests AuditLogger service.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Service\AuditLogger
 * @group aabenforms_core
 * @group audit
 * @group gdpr
 */
class AuditLoggerTest extends UnitTestCase {

  /**
   * The audit logger service.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger
   */
  protected AuditLogger $auditLogger;

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Mock current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * Mock request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock dependencies.
    $this->database = $this->createMock(Connection::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('aabenforms_audit')
      ->willReturn($this->logger);

    // Default mocks.
    $this->currentUser->method('id')->willReturn(1);
    $this->time->method('getRequestTime')->willReturn(1706356800);

    $request = $this->createMock(Request::class);
    $request->method('getClientIp')->willReturn('192.168.1.100');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    // Single-tenant context: no active tenant, so tenant_id is empty.
    $tenantResolver = $this->getMockBuilder(TenantResolver::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getCurrentTenantId'])
      ->getMock();
    $tenantResolver->method('getCurrentTenantId')->willReturn(NULL);

    // Create service.
    $this->auditLogger = new AuditLogger(
      $this->database,
      $this->currentUser,
      $this->requestStack,
      $this->time,
      $loggerFactory,
      $tenantResolver
    );
  }

  /**
   * Tests logCprLookup with successful lookup.
   *
   * @covers ::logCprLookup
   * @covers ::log
   */
  public function testLogCprLookup(): void {
    $cpr = '0101001234';
    $purpose = 'Validate citizen identity for form submission';
    $status = 'success';

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => hash('sha256', $cpr),
        'purpose' => $purpose,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logCprLookup($cpr, $purpose, $status);
  }

  /**
   * Tests logCprLookup with additional context.
   *
   * @covers ::logCprLookup
   * @covers ::log
   */
  public function testLogCprLookupWithContext(): void {
    $cpr = '0101001234';
    $purpose = 'Verify address for building permit';
    $status = 'success';
    $context = [
      'workflow_id' => 'building_permit_001',
      'submission_id' => 'sub_12345',
      'tenant' => 'aarhus',
    ];

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => hash('sha256', $cpr),
        'purpose' => $purpose,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => json_encode($context),
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logCprLookup($cpr, $purpose, $status, $context);
  }

  /**
   * Tests logCprLookup with protected person.
   *
   * @covers ::logCprLookup
   * @covers ::log
   */
  public function testLogCprLookupProtectedPerson(): void {
    $cpr = '0202003456';
    $purpose = 'Attempt to lookup protected person';
    $status = 'protected_person';

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => hash('sha256', $cpr),
        'purpose' => $purpose,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logCprLookup($cpr, $purpose, $status);
  }

  /**
   * Tests logCvrLookup.
   *
   * @covers ::logCvrLookup
   * @covers ::log
   */
  public function testLogCvrLookup(): void {
    $cvr = '12345678';
    $purpose = 'Validate company for business license';
    $status = 'success';

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'cvr_lookup',
        'identifier_hash' => hash('sha256', $cvr),
        'purpose' => $purpose,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logCvrLookup($cvr, $purpose, $status);
  }

  /**
   * Tests logCvrLookup with failure status.
   *
   * @covers ::logCvrLookup
   * @covers ::log
   */
  public function testLogCvrLookupFailure(): void {
    $cvr = '87654321';
    $purpose = 'Lookup company for permit application';
    $status = 'failure';
    $context = ['error' => 'Company not found'];

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'cvr_lookup',
        'identifier_hash' => hash('sha256', $cvr),
        'purpose' => $purpose,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => json_encode($context),
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logCvrLookup($cvr, $purpose, $status, $context);
  }

  /**
   * Tests logWorkflowAccess.
   *
   * @covers ::logWorkflowAccess
   * @covers ::log
   */
  public function testLogWorkflowAccess(): void {
    $workflowId = 'citizen_complaint_workflow';
    $action = 'view';
    $status = 'granted';

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'workflow_access',
        'identifier_hash' => hash('sha256', $workflowId),
        'purpose' => $action,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logWorkflowAccess($workflowId, $action, $status);
  }

  /**
   * Tests logWorkflowAccess with denied access.
   *
   * @covers ::logWorkflowAccess
   * @covers ::log
   */
  public function testLogWorkflowAccessDenied(): void {
    $workflowId = 'sensitive_workflow';
    $action = 'modify';
    $status = 'denied';
    $context = ['reason' => 'Insufficient permissions'];

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'workflow_access',
        'identifier_hash' => hash('sha256', $workflowId),
        'purpose' => $action,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => json_encode($context),
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logWorkflowAccess($workflowId, $action, $status, $context);
  }

  /**
   * Tests log method handles database exceptions gracefully.
   *
   * @covers ::logCprLookup
   * @covers ::log
   */
  public function testLogHandlesDatabaseException(): void {
    $cpr = '0303005678';
    $purpose = 'Test exception handling';
    $status = 'success';

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willThrowException(new \Exception('Database connection lost'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to write audit log: {error}',
        $this->callback(function ($context) {
          return isset($context['error']) &&
                 strpos($context['error'], 'Database connection lost') !== FALSE &&
                 isset($context['action']) &&
                 $context['action'] === 'cpr_lookup';
        })
      );

    // Should not throw exception.
    $this->auditLogger->logCprLookup($cpr, $purpose, $status);
  }

  /**
   * Tests getAuditLog retrieves logs.
   *
   * @covers ::getAuditLog
   */
  public function testGetAuditLog(): void {
    $mockResults = [
      [
        'id' => 1,
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => hash('sha256', '0101001234'),
        'purpose' => 'Validate citizen',
        'status' => 'success',
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ],
      [
        'id' => 2,
        'uid' => 1,
        'action' => 'cvr_lookup',
        'identifier_hash' => hash('sha256', '12345678'),
        'purpose' => 'Validate company',
        'status' => 'success',
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356900,
      ],
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($mockResults);

    $query = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fields', 'orderBy', 'range', 'condition', 'execute'])
      ->getMock();
    $query->method('fields')->willReturnSelf();
    $query->method('orderBy')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->with('aabenforms_audit_log', 'al')
      ->willReturn($query);

    $results = $this->auditLogger->getAuditLog();

    $this->assertCount(2, $results);
    $this->assertEquals('cpr_lookup', $results[0]['action']);
    $this->assertEquals('cvr_lookup', $results[1]['action']);
  }

  /**
   * Tests getAuditLog with conditions.
   *
   * @covers ::getAuditLog
   */
  public function testGetAuditLogWithConditions(): void {
    $mockResults = [
      [
        'id' => 1,
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => hash('sha256', '0101001234'),
        'purpose' => 'Validate citizen',
        'status' => 'success',
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ],
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($mockResults);

    $query = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fields', 'orderBy', 'range', 'condition', 'execute'])
      ->getMock();
    $query->method('fields')->willReturnSelf();
    $query->method('orderBy')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->expects($this->once())
      ->method('condition')
      ->with('action', 'cpr_lookup')
      ->willReturnSelf();
    $query->method('execute')->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->with('aabenforms_audit_log', 'al')
      ->willReturn($query);

    $results = $this->auditLogger->getAuditLog(['action' => 'cpr_lookup']);

    $this->assertCount(1, $results);
    $this->assertEquals('cpr_lookup', $results[0]['action']);
  }

  /**
   * Tests getAuditLog with pagination.
   *
   * @covers ::getAuditLog
   */
  public function testGetAuditLogWithPagination(): void {
    $mockResults = [
      [
        'id' => 11,
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => hash('sha256', '0101001234'),
        'purpose' => 'Validate citizen',
        'status' => 'success',
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ],
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($mockResults);

    $query = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fields', 'orderBy', 'range', 'condition', 'execute'])
      ->getMock();
    $query->method('fields')->willReturnSelf();
    $query->method('orderBy')->willReturnSelf();
    $query->expects($this->once())
      ->method('range')
      ->with(10, 50)
      ->willReturnSelf();
    $query->method('execute')->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->with('aabenforms_audit_log', 'al')
      ->willReturn($query);

    $results = $this->auditLogger->getAuditLog([], 50, 10);

    $this->assertCount(1, $results);
  }

  /**
   * Tests identifier is hashed with SHA-256.
   *
   * @covers ::logCprLookup
   * @covers ::log
   */
  public function testIdentifierIsHashedNotPlaintext(): void {
    $cpr = '0404007890';
    $purpose = 'Test hashing';
    $status = 'success';

    $expectedHash = hash('sha256', $cpr);

    $this->database->expects($this->once())
      ->method('insert')
      ->with('aabenforms_audit_log')
      ->willReturn($this->createMockInsertQuery([
        'uid' => 1,
        'action' => 'cpr_lookup',
        'identifier_hash' => $expectedHash,
        'purpose' => $purpose,
        'status' => $status,
        'ip_address' => '192.168.1.100',
        'context' => '[]',
        'timestamp' => 1706356800,
      ]));

    $this->auditLogger->logCprLookup($cpr, $purpose, $status);

    // Verify hash is correct and not plaintext.
    $this->assertNotEquals($cpr, $expectedHash);
    // SHA-256 is 64 hex chars.
    $this->assertEquals(64, strlen($expectedHash));
  }

  /**
   * Helper to create mock insert query.
   *
   * @param array $expectedFields
   *   Expected field values.
   *
   * @return object
   *   Mock insert query object.
   */
  protected function createMockInsertQuery(array $expectedFields) {
    return new class($expectedFields) {

      /**
       * Constructor.
       */
      public function __construct(
        public array $expectedFields,
      ) {}

      /**
       * Mock fields method.
       */
      public function fields(array $fields): self {
        foreach ($this->expectedFields as $key => $expectedValue) {
          if (!isset($fields[$key])) {
            throw new \Exception("Missing field: {$key}");
          }
          if ($fields[$key] !== $expectedValue) {
            throw new \Exception("Field {$key} mismatch. Expected: {$expectedValue}, Got: {$fields[$key]}");
          }
        }
        return $this;
      }

      /**
       * Mock execute method.
       */
      public function execute(): void {
        // Simulate successful insert.
      }

    };
  }

}
