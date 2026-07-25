<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Drush;

use Drupal\aabenforms_tenant\Service\TenantProvisioner;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for standing up AabenForms tenants (kommuner / magistrater).
 *
 * A tenant needs a Domain plus a matching per-tenant CPR key and encryption
 * profile whose ids line up exactly with what the runtime derives; getting any
 * of that wrong makes CPR encryption fail closed. `aabenforms:tenant:provision`
 * creates all three idempotently, so standing up e.g. "Aarhus / Teknik og
 * Miljoe" is one command instead of manual config.
 */
final class TenantCommands extends DrushCommands {

  public function __construct(
    private readonly TenantProvisioner $provisioner,
  ) {
    parent::__construct();
  }

  /**
   * Provision a tenant: Domain + per-tenant CPR key + encryption profile.
   *
   * Idempotent: artifacts that already exist are left untouched. The per-tenant
   * key uses the `env` provider, so this command creates only config - you must
   * set the reported environment variable (base64 of 32 random bytes) and
   * restart before CPR can be stored for the tenant.
   *
   * @param string $tenantId
   *   The canonical tenant id (lowercase letters, digits, underscores), e.g.
   *   aarhus_mtm. Becomes the Domain id and the tenant discriminator.
   * @param string $label
   *   The human-readable tenant name, e.g. "Aarhus - Teknik og Miljoe".
   * @param array $options
   *   The command options.
   *
   * @option hostname
   *   The hostname routing to this tenant. Defaults to
   *   <tenant-id-with-dashes>.aabenforms.dk.
   * @option dry-run
   *   Report what would be created without changing anything.
   *
   * @command aabenforms:tenant:provision
   * @aliases af:tenant-provision
   * @usage drush aabenforms:tenant:provision aarhus_mtm "Aarhus - Teknik og Miljoe" --hostname=mtm.aarhus.aabenforms.dk
   *   Stand up the Teknik og Miljoe magistrat as an isolated tenant.
   * @usage drush aabenforms:tenant:provision aarhus_mtm "Aarhus - Teknik og Miljoe" --dry-run
   *   Show what provisioning would create.
   */
  public function provision(
    string $tenantId,
    string $label,
    array $options = ['hostname' => NULL, 'dry-run' => FALSE],
  ): int {
    $hostname = is_string($options['hostname']) && $options['hostname'] !== ''
      ? $options['hostname']
      : str_replace('_', '-', $tenantId) . '.aabenforms.dk';

    if (!empty($options['dry-run'])) {
      return $this->dryRun($tenantId, $hostname);
    }

    try {
      $result = $this->provisioner->provision($tenantId, $label, $hostname);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    if ($result['created'] === []) {
      $this->io()->success(dt('Tenant @id already provisioned (@host); nothing changed.', [
        '@id' => $result['tenant_id'],
        '@host' => $result['hostname'],
      ]));
    }
    else {
      $this->io()->success(dt('Provisioned tenant @id (@host):', [
        '@id' => $result['tenant_id'],
        '@host' => $result['hostname'],
      ]));
      $this->io()->listing($result['created']);
      if ($result['existing'] !== []) {
        $this->io()->writeln(dt('Already present:'));
        $this->io()->listing($result['existing']);
      }
    }

    $this->io()->writeln(dt('CPR key material is read from the environment variable: @env', [
      '@env' => $result['env_variable'],
    ]));
    foreach ($result['warnings'] as $warning) {
      $this->logger()->warning($warning);
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Prints what provisioning would create for a tenant.
   */
  private function dryRun(string $tenantId, string $hostname): int {
    try {
      $state = $this->provisioner->describe($tenantId);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $mark = static fn (bool $present): string => $present ? 'exists' : 'would create';
    $this->io()->writeln(dt('Dry run for tenant @id (@host):', [
      '@id' => $state['tenant_id'],
      '@host' => $hostname,
    ]));
    $this->io()->table(
      [dt('Artifact'), dt('State')],
      [
        ['domain.record.' . $state['tenant_id'], $mark($state['domain'])],
        ['key.key.cpr_' . $state['tenant_id'], $mark($state['key'])],
        ['encrypt.profile.aabenforms_aes256_' . $state['tenant_id'], $mark($state['profile'])],
        [$state['env_variable'], $state['env_set'] ? 'set' : 'NOT set'],
      ],
    );
    return self::EXIT_SUCCESS;
  }

}
