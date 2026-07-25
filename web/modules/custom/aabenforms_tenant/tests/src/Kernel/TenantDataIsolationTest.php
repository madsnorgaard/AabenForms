<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_tenant\Kernel;

use Drupal\domain\Entity\Domain;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Proves one kommune's cases and CPR are invisible to another.
 *
 * @group aabenforms_tenant
 */
class TenantDataIsolationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'node',
    'key', 'encrypt', 'real_aes',
    'domain', 'domain_access',
    'modeler_api', 'eca', 'webform',
    'aabenforms_core', 'aabenforms_case', 'aabenforms_tenant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('domain');
    $this->installEntitySchema('aabenforms_case');
    $this->installConfig(['system', 'field']);
    // Two tenants.
    Domain::create(['id' => 'aarhus', 'hostname' => 'aarhus.test', 'name' => 'Aarhus'])->save();
    Domain::create(['id' => 'odense', 'hostname' => 'odense.test', 'name' => 'Odense'])->save();
    $this->setActiveDomain('aarhus');
  }

  /**
   * A case owned by one kommune is not viewable or queryable from another.
   */
  public function testCaseAccessIsolation(): void {
    // The first created user is uid 1 (root, bypasses all access) - burn it.
    $this->createUser();
    $worker = $this->createUser(['view aabenforms_case']);
    $operator = $this->createUser(['view aabenforms_case', 'bypass tenant isolation']);
    $storage = \Drupal::entityTypeManager()->getStorage('aabenforms_case');

    // Case created while Aarhus is active is stamped tenant_id = aarhus.
    $this->setActiveDomain('aarhus');
    $caseA = $storage->create(['title' => 'Sag A', 'case_type' => 'underretning']);
    $caseA->save();
    $this->assertSame('aarhus', $caseA->get('tenant_id')->value, 'Case is stamped with the active tenant on save.');

    $this->setActiveDomain('odense');
    $caseB = $storage->create(['title' => 'Sag B', 'case_type' => 'underretning']);
    $caseB->save();
    $this->assertSame('odense', $caseB->get('tenant_id')->value);

    // Entity access: from Odense, Aarhus' case is forbidden; its own is allowed.
    $this->setActiveDomain('odense');
    $this->assertFalse($caseA->access('view', $worker), 'Odense worker cannot view an Aarhus case.');
    $this->assertTrue($caseB->access('view', $worker), 'Odense worker can view an Odense case.');
    // The bypass operator sees across tenants.
    $this->assertTrue($caseA->access('view', $operator), 'A bypass operator sees any tenant.');

    // From Aarhus, the Aarhus case is visible again (scoped, not blanket-deny).
    $this->setActiveDomain('aarhus');
    $this->assertTrue($caseA->access('view', $worker), 'Aarhus worker can view the Aarhus case.');

    // Query isolation: from Odense, as the worker, the query returns only Odense.
    $this->setActiveDomain('odense');
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($worker);
    $ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    $switcher->switchBack();
    $this->assertEqualsCanonicalizing([$caseB->id() => $caseB->id()], $ids, 'Query returns only the current tenant\'s cases.');
  }

  /**
   * A CPR encrypted for one kommune cannot be revealed as another.
   */
  public function testCprCrossTenantDecryptFails(): void {
    $this->createTenantKey('aarhus');
    $this->createTenantKey('odense');
    $cpr = \Drupal::service('aabenforms_core.cpr_access');

    $this->setActiveDomain('aarhus');
    $ciphertext = $cpr->protect('2506924015');
    $this->assertStringStartsWith('AFENC2:aarhus:', $ciphertext, 'CPR is encrypted with the tenant key.');
    $this->assertSame('2506924015', $cpr->reveal($ciphertext), 'Same-tenant reveal works.');

    // From Odense, revealing the Aarhus ciphertext uses the Odense key -> fails.
    $this->setActiveDomain('odense');
    $this->assertSame('', $cpr->reveal($ciphertext), 'Cross-tenant CPR decrypt fails.');
  }

  /**
   * Sets the active domain (the "current tenant").
   */
  private function setActiveDomain(string $id): void {
    \Drupal::service('domain.negotiator')->setActiveDomain(Domain::load($id));
    // The per-request entity-access static cache is not domain-aware; reset it
    // so each in-test domain switch recomputes (real requests are one domain).
    \Drupal::entityTypeManager()->getAccessControlHandler('aabenforms_case')->resetCache();
  }

  /**
   * Creates a real_aes key + encryption profile for a tenant.
   */
  private function createTenantKey(string $tenant): void {
    Key::create([
      'id' => 'cpr_' . $tenant,
      'label' => 'CPR ' . $tenant,
      'key_type' => 'encryption',
      'key_type_settings' => ['key_size' => '256'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => base64_encode(random_bytes(32)), 'base64_encoded' => TRUE],
      'key_input' => 'none',
    ])->save();
    EncryptionProfile::create([
      'id' => 'aabenforms_aes256_' . $tenant,
      'label' => 'AES ' . $tenant,
      'encryption_method' => 'real_aes',
      'encryption_key' => 'cpr_' . $tenant,
    ])->save();
  }

}
