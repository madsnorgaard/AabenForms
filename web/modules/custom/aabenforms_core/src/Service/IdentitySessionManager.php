<?php

namespace Drupal\aabenforms_core\Service;

use Drupal\aabenforms_core\Identity\SessionManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Provider-neutral, flow-scoped verified-identity session store.
 *
 * The canonical implementation of the identity session store, aliased as
 * `aabenforms_identity.session_manager`. It lives in aabenforms_core so BOTH
 * identity rails depend only on core: the MitID OIDC rail
 * (Drupal\aabenforms_mitid\Service\MitIdSessionManager, which adds demo-seeding
 * and address helpers) and the NemLog-in OIOSAML 3 rail. Both read and write the
 * SAME KeyValueExpirable collection, so a session stored by either rail is
 * readable by every downstream consumer (the ECA gate, the webform intake).
 * This removes the previous hard coupling of the production SAML rail to the
 * demo MitID module.
 *
 * Storage rationale (unchanged from the original MitID store): a
 * KeyValueExpirable collection keyed by the unguessable workflow_id bearer
 * handle - NOT a user-bound PrivateTempStore - so a cross-origin SPA fetch can
 * read the session without a shared cookie.
 */
class IdentitySessionManager implements SessionManagerInterface {

  /**
   * Session expiration time in seconds (15 minutes).
   */
  protected const SESSION_EXPIRATION = 900;

  /**
   * The shared keyvalue-expirable collection name.
   *
   * Kept as the historical MitID collection so sessions written by the MitID
   * rail remain readable and both rails share one store.
   */
  protected const STORE_COLLECTION = 'aabenforms_mitid_sessions';

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an IdentitySessionManager.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $keyValue
   *   The keyvalue-expirable factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\aabenforms_core\Service\AuditLogger $auditLogger
   *   The audit logger.
   */
  public function __construct(
    protected readonly KeyValueExpirableFactoryInterface $keyValue,
    protected readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly AuditLogger $auditLogger,
  ) {
    $this->logger = $loggerFactory->get('aabenforms_identity');
  }

  /**
   * Returns the underlying keyvalue-expirable store.
   */
  protected function store() {
    return $this->keyValue->get(static::STORE_COLLECTION);
  }

  /**
   * {@inheritdoc}
   */
  public function storeSession(string $workflow_id, array $session_data): bool {
    try {
      $session_data['created_at'] = $this->time->getRequestTime();
      $session_data['expires_at'] = $this->time->getRequestTime() + static::SESSION_EXPIRATION;
      $session_data['workflow_id'] = $workflow_id;
      $this->store()->setWithExpire($workflow_id, $session_data, static::SESSION_EXPIRATION);
      if (isset($session_data['cpr'])) {
        $this->auditLogger->logWorkflowAccess(
          $workflow_id,
          'identity_session_created',
          'success',
          ['assurance_level' => $session_data['assurance_level'] ?? 'unknown']
        );
      }
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store identity session: {error}', [
        'error' => $e->getMessage(),
        'workflow_id' => $workflow_id,
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSession(string $workflow_id): ?array {
    try {
      $session_data = $this->store()->get($workflow_id);
      if (!$session_data || !is_array($session_data)) {
        return NULL;
      }
      $expiresAt = $session_data['expires_at'] ?? 0;
      if ($expiresAt < $this->time->getRequestTime()) {
        $this->deleteSession($workflow_id);
        return NULL;
      }
      return $session_data;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve identity session: {error}', [
        'error' => $e->getMessage(),
        'workflow_id' => $workflow_id,
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteSession(string $workflow_id): bool {
    try {
      $this->store()->delete($workflow_id);
      $this->auditLogger->logWorkflowAccess($workflow_id, 'identity_session_deleted', 'success', []);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete identity session: {error}', [
        'error' => $e->getMessage(),
        'workflow_id' => $workflow_id,
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasValidSession(string $workflow_id): bool {
    return $this->getSession($workflow_id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCprFromSession(string $workflow_id): ?string {
    return $this->getSession($workflow_id)['cpr'] ?? NULL;
  }

  /**
   * {@inheritdoc}
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

}
