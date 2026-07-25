<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_tenant\Kernel;

use Drupal\aabenforms_tenant\Service\TenantProvisioner;
use Drupal\domain\Entity\Domain;
use Drupal\KernelTests\KernelTestBase;

/**
 * Proves one-command tenant provisioning wires up CprAccess isolation.
 *
 * The provisioner is only correct if the config it creates matches what the
 * runtime derives: the Domain id must equal the tenant discriminator, and the
 * encryption profile id must equal CprAccess::tenantProfile(). This test drives
 * the real provisioner (with the production `env` key provider) and then proves
 * CPR round-trips for the tenant and CANNOT be revealed by another - so a green
 * test means a provisioned tenant genuinely isolates CPR.
 *
 * @group aabenforms_tenant
 * @covers \Drupal\aabenforms_tenant\Service\TenantProvisioner
 */
class TenantProvisionerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'node',
    'key', 'encrypt', 'real_aes',
    'domain', 'domain_access',
    'modeler_api', 'eca', 'webform',
    'aabenforms_core', 'aabenforms_tenant',
  ];

  /**
   * The tenant provisioner under test.
   *
   * @var \Drupal\aabenforms_tenant\Service\TenantProvisioner
   */
  private TenantProvisioner $provisioner;

  /**
   * Env variables set during a test, cleared in tearDown.
   *
   * @var string[]
   */
  private array $envVars = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('domain');
    $this->installConfig(['system', 'field']);
    $this->provisioner = $this->container->get('aabenforms_tenant.tenant_provisioner');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->envVars as $name) {
      putenv($name);
    }
    parent::tearDown();
  }

  /**
   * Provisioning creates the Domain, key and profile with the exact ids.
   */
  public function testProvisionCreatesArtifacts(): void {
    $result = $this->provisioner->provision(
      'aarhus_mtm',
      'Aarhus - Teknik og Miljoe',
      'mtm.aarhus.aabenforms.test',
    );

    $this->assertSame('aarhus_mtm', $result['tenant_id']);
    $this->assertSame('AABENFORMS_CPR_KEY_AARHUS_MTM', $result['env_variable']);
    $this->assertContains('domain.record.aarhus_mtm', $result['created']);
    $this->assertContains('key.key.cpr_aarhus_mtm', $result['created']);
    $this->assertContains('encrypt.profile.aabenforms_aes256_aarhus_mtm', $result['created']);

    $domain = $this->container->get('entity_type.manager')->getStorage('domain')->load('aarhus_mtm');
    $this->assertNotNull($domain);
    $this->assertSame('mtm.aarhus.aabenforms.test', $domain->getHostname());

    $key = $this->container->get('entity_type.manager')->getStorage('key')->load('cpr_aarhus_mtm');
    $this->assertNotNull($key);
    $this->assertSame('env', $key->get('key_provider'));
    $this->assertSame('AABENFORMS_CPR_KEY_AARHUS_MTM', $key->get('key_provider_settings')['env_variable']);

    $profile = $this->container->get('entity_type.manager')->getStorage('encryption_profile')->load('aabenforms_aes256_aarhus_mtm');
    $this->assertNotNull($profile);
    $this->assertSame('real_aes', $profile->get('encryption_method'));
    $this->assertSame('cpr_aarhus_mtm', $profile->get('encryption_key'));

    // The env var is not set, so the operator is warned.
    $this->assertNotEmpty($result['warnings']);
  }

  /**
   * A provisioned tenant round-trips CPR; another tenant cannot reveal it.
   */
  public function testProvisionEnablesCprIsolation(): void {
    $this->provisionWithKey('aarhus_mtm', 'Aarhus - Teknik og Miljoe', 'mtm.aarhus.test');
    $this->provisionWithKey('aarhus_mbu', 'Aarhus - Boern og Unge', 'mbu.aarhus.test');
    $cpr = $this->container->get('aabenforms_core.cpr_access');

    $this->setActiveDomain('aarhus_mtm');
    $ciphertext = $cpr->protect('2506924015');
    $this->assertStringStartsWith('AFENC2:aarhus_mtm:', $ciphertext);
    $this->assertSame('2506924015', $cpr->reveal($ciphertext), 'Same-tenant reveal works.');

    // From another magistrat the wrong key is used, so the reveal fails.
    $this->setActiveDomain('aarhus_mbu');
    $this->assertSame('', $cpr->reveal($ciphertext), 'Cross-tenant CPR decrypt fails.');
  }

  /**
   * Re-running provisioning creates nothing and reports the artifacts existing.
   */
  public function testProvisionIsIdempotent(): void {
    $this->provisioner->provision('odense', 'Odense Kommune', 'odense.test');
    $second = $this->provisioner->provision('odense', 'Odense Kommune', 'odense.test');

    $this->assertSame([], $second['created'], 'A second provision creates nothing.');
    $this->assertContains('domain.record.odense', $second['existing']);
    $this->assertContains('key.key.cpr_odense', $second['existing']);
    $this->assertContains('encrypt.profile.aabenforms_aes256_odense', $second['existing']);
  }

  /**
   * A hostname already owned by another tenant is rejected clearly.
   */
  public function testDuplicateHostnameRejected(): void {
    $this->provisioner->provision('aarhus', 'Aarhus Kommune', 'shared.test');
    $this->expectException(\RuntimeException::class);
    $this->provisioner->provision('odense', 'Odense Kommune', 'shared.test');
  }

  /**
   * A non-canonical tenant id is rejected before anything is created.
   */
  public function testInvalidTenantIdRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->provisioner->provision('Aarhus.MTM', 'Aarhus', 'x.test');
  }

  /**
   * Provisions a tenant and sets its CPR key env var to real key material.
   */
  private function provisionWithKey(string $tenantId, string $label, string $hostname): void {
    $envVar = 'AABENFORMS_CPR_KEY_' . strtoupper($tenantId);
    putenv($envVar . '=' . base64_encode(random_bytes(32)));
    $this->envVars[] = $envVar;
    $this->provisioner->provision($tenantId, $label, $hostname);
  }

  /**
   * Sets the active domain (the "current tenant").
   */
  private function setActiveDomain(string $id): void {
    $this->container->get('domain.negotiator')->setActiveDomain(Domain::load($id));
  }

}
