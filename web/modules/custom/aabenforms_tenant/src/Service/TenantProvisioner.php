<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Service;

use Drupal\Core\Config\ConfigValueException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Stands up a tenant (kommune or magistrat) in one idempotent operation.
 *
 * A tenant is a `domain` config entity; its machine id is what
 * TenantResolver::getCurrentTenantId() returns and what
 * aabenforms_tenant stamps onto every case/submission. For CPR to be
 * encrypted per tenant, that tenant also needs a `key` + `encrypt.profile`
 * whose profile id EXACTLY matches what CprAccess::tenantProfile() derives
 * (aabenforms_aes256_<sanitized-id>). Doing this by hand is fragile: a
 * mismatched profile id makes CPR encryption fail closed (submissions
 * rejected), and a shared key silently breaks cross-tenant isolation.
 *
 * This service creates all three config entities in the exact shape the
 * runtime expects, load-or-create so re-running is safe. It NEVER writes or
 * reads secret material: the per-tenant key uses the `env` provider (one
 * variable per tenant, mirroring the global AABENFORMS_CPR_KEY), so the key
 * lives only in the host environment - off git and off the database. The
 * operator injects it out of band; provision() reports the variable name and
 * warns while it is still empty.
 */
final class TenantProvisioner {

  /**
   * The per-tenant profile-id prefix.
   *
   * MUST stay in lockstep with CprAccess::DEFAULT_PROFILE (which is protected,
   * so it cannot be referenced directly). The runtime profile id is
   * CprAccess::tenantProfile() = this prefix . '_' . sanitized-tenant-id.
   *
   * @see \Drupal\aabenforms_core\Service\CprAccess::tenantProfile()
   */
  private const PROFILE_PREFIX = 'aabenforms_aes256';

  /**
   * The environment-variable prefix for a tenant's CPR key material.
   */
  private const ENV_PREFIX = 'AABENFORMS_CPR_KEY_';

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Constructs a TenantProvisioner.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('aabenforms_tenant');
  }

  /**
   * Provisions a tenant: Domain + per-tenant CPR key + encryption profile.
   *
   * Idempotent: each artifact is created only if absent, so re-running reports
   * "already present" and changes nothing.
   *
   * @param string $tenantId
   *   The canonical tenant id (lowercase [a-z0-9_], e.g. "aarhus_mtm"). This
   *   becomes the Domain id, so it must already be in sanitized form - the
   *   runtime derives the profile id from the Domain id and a mismatch would
   *   break CPR encryption.
   * @param string $label
   *   The human-readable tenant name (e.g. "Aarhus - Teknik og Miljoe").
   * @param string $hostname
   *   The hostname that routes to this tenant (e.g. mtm.aarhus.aabenforms.dk).
   *
   * @return array
   *   A result array with keys: tenant_id, hostname, env_variable, created
   *   (string[]), existing (string[]), warnings (string[]).
   *
   * @throws \InvalidArgumentException
   *   When the tenant id is empty or not already sanitized, or the hostname is
   *   empty.
   * @throws \RuntimeException
   *   When the hostname is already registered to a different tenant.
   */
  public function provision(string $tenantId, string $label, string $hostname): array {
    $tenantId = $this->assertCanonicalId($tenantId);
    $hostname = trim($hostname);
    if ($hostname === '') {
      throw new \InvalidArgumentException('A hostname is required to provision a tenant.');
    }
    if (trim($label) === '') {
      throw new \InvalidArgumentException('A label is required to provision a tenant.');
    }

    $created = [];
    $existing = [];

    $this->provisionDomain($tenantId, $label, $hostname, $created, $existing);
    $this->provisionKey($tenantId, $label, $created, $existing);
    $this->provisionProfile($tenantId, $label, $created, $existing);

    $envVariable = $this->envVariable($tenantId);
    $warnings = [];
    if ((string) getenv($envVariable) === '') {
      $warnings[] = sprintf(
        'Set %s (base64 of 32 random bytes) in the host environment and restart before CPR can be stored for this tenant.',
        $envVariable,
      );
    }

    if ($created !== []) {
      $this->logger->info('Provisioned tenant @id: @changes', [
        '@id' => $tenantId,
        '@changes' => implode('; ', $created),
      ]);
    }

    return [
      'tenant_id' => $tenantId,
      'hostname' => $hostname,
      'env_variable' => $envVariable,
      'created' => $created,
      'existing' => $existing,
      'warnings' => $warnings,
    ];
  }

  /**
   * Reports the provisioning state of a tenant without changing anything.
   *
   * @param string $tenantId
   *   The tenant id.
   *
   * @return array
   *   Keys: tenant_id, env_variable, domain (bool), key (bool), profile
   *   (bool), env_set (bool), complete (bool). "complete" is TRUE when all
   *   three config entities exist AND the key env variable is set.
   */
  public function describe(string $tenantId): array {
    $tenantId = $this->assertCanonicalId($tenantId);
    $domain = $this->storage('domain')->load($tenantId) !== NULL;
    $key = $this->storage('key')->load($this->keyId($tenantId)) !== NULL;
    $profile = $this->storage('encryption_profile')->load($this->profileId($tenantId)) !== NULL;
    $envVariable = $this->envVariable($tenantId);
    $envSet = (string) getenv($envVariable) !== '';
    return [
      'tenant_id' => $tenantId,
      'env_variable' => $envVariable,
      'domain' => $domain,
      'key' => $key,
      'profile' => $profile,
      'env_set' => $envSet,
      'complete' => $domain && $key && $profile && $envSet,
    ];
  }

  /**
   * Load-or-create the Domain record for the tenant.
   */
  private function provisionDomain(string $tenantId, string $label, string $hostname, array &$created, array &$existing): void {
    $storage = $this->storage('domain');
    if ($storage->load($tenantId) !== NULL) {
      $existing[] = 'domain.record.' . $tenantId;
      return;
    }
    // Guard against a hostname already registered to a different tenant, which
    // would otherwise surface as an opaque ConfigValueException on save.
    if (method_exists($storage, 'loadByHostname')) {
      $clash = $storage->loadByHostname($hostname);
      if ($clash !== NULL) {
        throw new \RuntimeException(sprintf(
          'The hostname %s is already registered to tenant %s.',
          $hostname,
          $clash->id(),
        ));
      }
    }
    try {
      $storage->create([
        'id' => $tenantId,
        'hostname' => $hostname,
        'name' => $label,
        'scheme' => 'https',
        'status' => 1,
      ])->save();
    }
    catch (ConfigValueException $e) {
      throw new \RuntimeException(sprintf(
        'Could not create the domain for tenant %s: %s',
        $tenantId,
        $e->getMessage(),
      ), 0, $e);
    }
    $created[] = 'domain.record.' . $tenantId;
  }

  /**
   * Load-or-create the per-tenant CPR key (env provider, no secret in config).
   */
  private function provisionKey(string $tenantId, string $label, array &$created, array &$existing): void {
    $storage = $this->storage('key');
    $keyId = $this->keyId($tenantId);
    if ($storage->load($keyId) !== NULL) {
      $existing[] = 'key.key.' . $keyId;
      return;
    }
    $storage->create([
      'id' => $keyId,
      'label' => 'CPR ' . $label,
      'description' => 'Per-tenant CPR encryption key, read from the ' . $this->envVariable($tenantId) . ' environment variable.',
      'key_type' => 'encryption',
      'key_type_settings' => ['key_size' => 256],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => $this->envVariable($tenantId),
        'base64_encoded' => TRUE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
      'key_input_settings' => [],
    ])->save();
    $created[] = 'key.key.' . $keyId;
  }

  /**
   * Load-or-create the per-tenant encryption profile CprAccess resolves.
   */
  private function provisionProfile(string $tenantId, string $label, array &$created, array &$existing): void {
    $storage = $this->storage('encryption_profile');
    $profileId = $this->profileId($tenantId);
    if ($storage->load($profileId) !== NULL) {
      $existing[] = 'encrypt.profile.' . $profileId;
      return;
    }
    $storage->create([
      'id' => $profileId,
      'label' => 'AabenForms AES-256 ' . $label,
      'encryption_method' => 'real_aes',
      'encryption_method_configuration' => ['mode' => 'cbc'],
      'encryption_key' => $this->keyId($tenantId),
    ])->save();
    $created[] = 'encrypt.profile.' . $profileId;
  }

  /**
   * Validates and returns the canonical tenant id.
   */
  private function assertCanonicalId(string $tenantId): string {
    $tenantId = trim($tenantId);
    $canonical = preg_replace('/[^a-z0-9_]/', '', strtolower($tenantId));
    if ($tenantId === '' || $tenantId !== $canonical) {
      throw new \InvalidArgumentException(sprintf(
        'Tenant id "%s" is invalid; use lowercase letters, digits and underscores only (e.g. aarhus_mtm).',
        $tenantId,
      ));
    }
    return $tenantId;
  }

  /**
   * The key id for a tenant.
   */
  private function keyId(string $tenantId): string {
    return 'cpr_' . $tenantId;
  }

  /**
   * The encryption profile id for a tenant (matches CprAccess::tenantProfile).
   */
  private function profileId(string $tenantId): string {
    return self::PROFILE_PREFIX . '_' . $tenantId;
  }

  /**
   * The environment variable holding a tenant's CPR key material.
   */
  private function envVariable(string $tenantId): string {
    return self::ENV_PREFIX . strtoupper($tenantId);
  }

  /**
   * Returns a config-entity storage handler.
   */
  private function storage(string $entityTypeId): EntityStorageInterface {
    return $this->entityTypeManager->getStorage($entityTypeId);
  }

}
