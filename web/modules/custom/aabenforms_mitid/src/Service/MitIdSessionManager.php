<?php

namespace Drupal\aabenforms_mitid\Service;

use Drupal\aabenforms_core\Identity\SessionManagerInterface;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_mitid\DemoPersonas;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing flow-scoped MitID sessions.
 *
 * Unlike traditional Drupal user sessions, MitID sessions are:
 * - Tied to workflow instances (not user accounts)
 * - Short-lived (15 minutes default)
 * - Auto-expiring (no permanent storage)
 * - GDPR compliant (deleted after workflow completion)
 *
 * Storage: KeyValueExpirable (NOT PrivateTempStore). The workflow_id IS
 * the bearer capability - anyone holding it can read the session for 15
 * minutes. PrivateTempStore was user-bound which broke the demo's
 * cross-origin fetch (frontend on a different host than the backend cookie
 * domain saw a fresh anonymous session and couldn't find the entry).
 * The workflow_id is generated with random_bytes so it's unguessable.
 */
class MitIdSessionManager implements SessionManagerInterface {

  /**
   * Session expiration time (15 minutes).
   */
  protected const SESSION_EXPIRATION = 900;

  /**
   * The keyvalue-expirable store collection name.
   */
  private const STORE_COLLECTION = 'aabenforms_mitid_sessions';

  /**
   * The keyvalue-expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected KeyValueExpirableFactoryInterface $keyValue;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The audit logger.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger
   */
  protected AuditLogger $auditLogger;

  /**
   * Constructs a MitIdSessionManager.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value
   *   The keyvalue-expirable factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\aabenforms_core\Service\AuditLogger $audit_logger
   *   The audit logger.
   */
  public function __construct(
    KeyValueExpirableFactoryInterface $key_value,
    TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory,
    AuditLogger $audit_logger,
  ) {
    $this->keyValue = $key_value;
    $this->time = $time;
    $this->logger = $logger_factory->get('aabenforms_mitid');
    $this->auditLogger = $audit_logger;
  }

  /**
   * Returns the underlying keyvalue-expirable store.
   */
  private function store() {
    return $this->keyValue->get(self::STORE_COLLECTION);
  }

  /**
   * Stores MitID session data for a workflow instance.
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   * @param array $session_data
   *   The session data to store.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function storeSession(string $workflow_id, array $session_data): bool {
    try {
      // Add metadata.
      $session_data['created_at'] = $this->time->getRequestTime();
      $session_data['expires_at'] = $this->time->getRequestTime() + self::SESSION_EXPIRATION;
      $session_data['workflow_id'] = $workflow_id;

      // Store session in the keyvalue-expirable store with the configured TTL
      // - the value auto-disappears after SESSION_EXPIRATION seconds.
      $this->store()->setWithExpire($workflow_id, $session_data, self::SESSION_EXPIRATION);

      $this->logger->info('MitID session stored for workflow: {workflow_id}', [
        'workflow_id' => $workflow_id,
      ]);

      // Audit log.
      if (isset($session_data['cpr'])) {
        $this->auditLogger->logWorkflowAccess(
          $workflow_id,
          'mitid_session_created',
          'success',
          ['assurance_level' => $session_data['assurance_level'] ?? 'unknown']
        );
      }

      return TRUE;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store MitID session: {error}', [
        'error' => $e->getMessage(),
        'workflow_id' => $workflow_id,
      ]);
      return FALSE;
    }
  }

  /**
   * Seeds a MitID session for a demo persona (test/demo harness only).
   *
   * Stores a session shaped exactly like a real MitID callback would, so a
   * MitID-gated flow can be exercised through its happy path without a live
   * authentication. The caller is responsible for access control: this is
   * reached only from the `aabenforms:seed-mitid-session` Drush command and the
   * permission-gated modeler "Test as demo citizen" action.
   *
   * @param string $persona
   *   A demo persona slug (see \Drupal\aabenforms_mitid\DemoPersonas).
   * @param string|null $workflow_id
   *   The workflow id to key the session under. When NULL an unguessable
   *   `wf_<hex>` handle is generated (matching MitIdController).
   *
   * @return string|null
   *   The workflow id the session was stored under, or NULL when the persona
   *   is unknown or storage failed.
   */
  public function seedDemoSession(string $persona, ?string $workflow_id = NULL): ?string {
    $data = DemoPersonas::get($persona);
    if ($data === NULL) {
      $this->logger->warning('Refusing to seed unknown demo persona: {persona}', [
        'persona' => $persona,
      ]);
      return NULL;
    }

    // Mark the session so the audit trail never confuses a seeded demo
    // identity with a real MitID authentication.
    $data['demo_seeded'] = TRUE;
    $data['persona'] = $persona;

    $workflow_id = $workflow_id ?: 'wf_' . substr(Crypt::randomBytesBase64(24), 0, 32);

    return $this->storeSession($workflow_id, $data) ? $workflow_id : NULL;
  }

  /**
   * Retrieves MitID session data for a workflow instance.
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   *
   * @return array|null
   *   The session data, or NULL if not found or expired.
   */
  public function getSession(string $workflow_id): ?array {
    try {
      $session_data = $this->store()->get($workflow_id);

      if (!$session_data || !is_array($session_data)) {
        return NULL;
      }

      // Defense-in-depth - the keyvalue store should already have GC'd this,
      // but check the saved expires_at as well in case TTL drift.
      $expiresAt = $session_data['expires_at'] ?? 0;
      if ($expiresAt < $this->time->getRequestTime()) {
        $this->logger->info('MitID session expired for workflow: {workflow_id}', [
          'workflow_id' => $workflow_id,
        ]);
        $this->deleteSession($workflow_id);
        return NULL;
      }

      return $session_data;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve MitID session: {error}', [
        'error' => $e->getMessage(),
        'workflow_id' => $workflow_id,
      ]);
      return NULL;
    }
  }

  /**
   * Deletes MitID session data for a workflow instance.
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteSession(string $workflow_id): bool {
    try {
      $this->store()->delete($workflow_id);

      $this->logger->info('MitID session deleted for workflow: {workflow_id}', [
        'workflow_id' => $workflow_id,
      ]);

      // Audit log.
      $this->auditLogger->logWorkflowAccess(
        $workflow_id,
        'mitid_session_deleted',
        'success',
        []
      );

      return TRUE;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete MitID session: {error}', [
        'error' => $e->getMessage(),
        'workflow_id' => $workflow_id,
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if a valid MitID session exists for a workflow.
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   *
   * @return bool
   *   TRUE if valid session exists, FALSE otherwise.
   */
  public function hasValidSession(string $workflow_id): bool {
    return $this->getSession($workflow_id) !== NULL;
  }

  /**
   * Gets CPR number from workflow session.
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   *
   * @return string|null
   *   The CPR number, or NULL if not available.
   */
  public function getCprFromSession(string $workflow_id): ?string {
    $session = $this->getSession($workflow_id);
    return $session['cpr'] ?? NULL;
  }

  /**
   * Gets person data from workflow session.
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   *
   * @return array|null
   *   The person data, or NULL if not available.
   */
  public function getPersonDataFromSession(string $workflow_id): ?array {
    $session = $this->getSession($workflow_id);

    if (!$session) {
      return NULL;
    }

    return [
      'cpr' => $session['cpr'] ?? NULL,
      'name' => $session['name'] ?? NULL,
      'given_name' => $session['given_name'] ?? NULL,
      'family_name' => $session['family_name'] ?? NULL,
      'birthdate' => $session['birthdate'] ?? NULL,
      'email' => $session['email'] ?? NULL,
      'assurance_level' => $session['assurance_level'] ?? NULL,
      'mitid_uuid' => $session['mitid_uuid'] ?? NULL,
    ];
  }

  /**
   * Gets address from workflow session.
   *
   * Returns NULL when no address keys are present (the typical case for real
   * MitID/NemLog-in - those IdPs don't issue address claims, so the frontend
   * falls back to manual entry).
   *
   * @param string $workflow_id
   *   The workflow instance ID.
   *
   * @return array|null
   *   Array with keys street, postal_code, city, municipality_code when at
   *   least one is present in the session; NULL otherwise.
   */
  public function getAddressFromSession(string $workflow_id): ?array {
    $session = $this->getSession($workflow_id);
    if (!$session) {
      return NULL;
    }
    $hasAny = isset($session['street'])
      || isset($session['postal_code'])
      || isset($session['city'])
      || isset($session['municipality_code']);
    if (!$hasAny) {
      return NULL;
    }
    return [
      'street' => $session['street'] ?? NULL,
      'postal_code' => $session['postal_code'] ?? NULL,
      'city' => $session['city'] ?? NULL,
      'municipality_code' => $session['municipality_code'] ?? NULL,
    ];
  }

}
