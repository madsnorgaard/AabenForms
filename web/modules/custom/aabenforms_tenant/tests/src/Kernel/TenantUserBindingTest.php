<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_tenant\Kernel;

use Drupal\aabenforms_tenant\Access\TenantMembershipAccessCheck;
use Drupal\domain\Entity\Domain;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Proves per-user tenant binding pins a caseworker to their kommune.
 *
 * A user bound to a tenant (via field_domain_access) sees only that tenant's
 * cases and ONLY on that tenant's host; on a foreign host the inbox is empty,
 * records are denied, and the route access check hard-blocks the page. Unbound
 * users keep today's host-based behavior and bypass operators see across.
 *
 * @group aabenforms_tenant
 */
class TenantUserBindingTest extends KernelTestBase {

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
    $this->installConfig(['aabenforms_tenant']);

    Domain::create(['id' => 'aarhus', 'hostname' => 'aarhus.test', 'name' => 'Aarhus'])->save();
    Domain::create(['id' => 'odense', 'hostname' => 'odense.test', 'name' => 'Odense'])->save();

    // Reuse the domain_access per-user domain field as the tenant binding.
    FieldStorageConfig::create([
      'field_name' => 'field_domain_access',
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'domain'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_domain_access',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Domain Access',
    ])->save();

    $this->setActiveDomain('aarhus');
  }

  /**
   * A bound user sees only their tenant, only on their tenant's host.
   */
  public function testBoundUserPinnedToTenant(): void {
    $this->createUser();
    $worker = $this->createUser(['view aabenforms_case']);
    $worker->set('field_domain_access', ['aarhus'])->save();

    [$caseA, $caseB] = $this->seedTwoCases();

    // On the foreign host (odense): bound worker sees neither case, empty query.
    $this->setActiveDomain('odense');
    $this->assertFalse($caseA->access('view', $worker), 'Aarhus-bound worker cannot view the Aarhus case from Odense host.');
    $this->assertFalse($caseB->access('view', $worker), 'Aarhus-bound worker cannot view the Odense case.');
    $this->assertSame([], $this->visibleCaseIds($worker), 'Bound worker on a foreign host sees an empty inbox.');

    // On their own host (aarhus): they see their case, never Odense's.
    $this->setActiveDomain('aarhus');
    $this->assertTrue($caseA->access('view', $worker), 'Aarhus-bound worker sees the Aarhus case on the Aarhus host.');
    $this->assertFalse($caseB->access('view', $worker), 'Aarhus-bound worker never sees the Odense case.');
    $this->assertSame([$caseA->id()], array_values($this->visibleCaseIds($worker)), 'Bound worker sees only their own tenant case.');
  }

  /**
   * An unbound user keeps the host-based behavior; an operator sees across.
   */
  public function testUnboundAndOperatorUnaffected(): void {
    $this->createUser();
    $unbound = $this->createUser(['view aabenforms_case']);
    $operator = $this->createUser(['view aabenforms_case', 'bypass tenant isolation']);
    [$caseA, $caseB] = $this->seedTwoCases();

    // Unbound worker: today's host behavior (sees the current host's tenant).
    $this->setActiveDomain('aarhus');
    $this->assertTrue($caseA->access('view', $unbound), 'Unbound worker sees the current host tenant case.');
    $this->assertFalse($caseB->access('view', $unbound), 'Unbound worker does not see the other host tenant case.');

    // Operator with the bypass permission sees across tenants.
    $this->assertTrue($caseB->access('view', $operator), 'Bypass operator sees any tenant.');
  }

  /**
   * The login binding map appends the mapped tenant to a matching user.
   */
  public function testAutoBindFromClaimMap(): void {
    $this->config('aabenforms_tenant.settings')
      ->set('tenant_binding.claim_field', 'cvr')
      ->set('tenant_binding.map', [['claim_value' => '12345678', 'tenant_id' => 'aarhus']])
      ->save();

    $this->createUser();
    $user = $this->createUser();
    $user->set('field_domain_access', [])->save();

    $added = _aabenforms_tenant_apply_binding_map($user, ['cvr' => '12345678']);
    $this->assertSame(['aarhus'], $added, 'A matching CVR claim binds the mapped tenant.');
    $bound = array_column($user->get('field_domain_access')->getValue(), 'target_id');
    $this->assertContains('aarhus', $bound);

    // Idempotent + non-matching claim leaves the binding unchanged.
    $this->assertSame([], _aabenforms_tenant_apply_binding_map($user, ['cvr' => '12345678']));
    $this->assertSame([], _aabenforms_tenant_apply_binding_map($user, ['cvr' => '99999999']));
  }

  /**
   * The route access check forbids a bound user on a foreign tenant host.
   */
  public function testRouteAccessCheckBlocksForeignHost(): void {
    $this->createUser();
    $worker = $this->createUser(['view aabenforms_case']);
    $worker->set('field_domain_access', ['aarhus'])->save();
    $check = new TenantMembershipAccessCheck($this->container->get('aabenforms_core.tenant_resolver'));

    $this->setActiveDomain('odense');
    $this->assertTrue($check->access($worker)->isForbidden(), 'Bound worker is blocked on a foreign host route.');

    $this->setActiveDomain('aarhus');
    $this->assertTrue($check->access($worker)->isAllowed(), 'Bound worker is allowed on their own host route.');

    // Unbound user is never route-blocked.
    $unbound = $this->createUser(['view aabenforms_case']);
    $this->setActiveDomain('odense');
    $this->assertTrue($check->access($unbound)->isAllowed(), 'Unbound worker is not route-blocked.');
  }

  /**
   * Seeds one case per tenant (Aarhus, Odense) and returns them.
   *
   * @return \Drupal\aabenforms_case\Entity\AabenformsCase[]
   *   [caseA (aarhus), caseB (odense)].
   */
  private function seedTwoCases(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('aabenforms_case');
    $this->setActiveDomain('aarhus');
    $caseA = $storage->create(['title' => 'Sag A', 'case_type' => 'byggesag']);
    $caseA->save();
    $this->setActiveDomain('odense');
    $caseB = $storage->create(['title' => 'Sag B', 'case_type' => 'friplads']);
    $caseB->save();
    return [$caseA, $caseB];
  }

  /**
   * The case ids visible to an account through an access-checked query.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to run the query as.
   *
   * @return array
   *   The visible case ids.
   */
  private function visibleCaseIds($account): array {
    $storage = \Drupal::entityTypeManager()->getStorage('aabenforms_case');
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($account);
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('id')->execute();
    $switcher->switchBack();
    return array_values($ids);
  }

  /**
   * Sets the active domain (the "current tenant").
   */
  private function setActiveDomain(string $id): void {
    $negotiator = \Drupal::service('domain.negotiator');
    // Force the lazy one-time negotiation first; otherwise the next
    // getActiveDomain() re-negotiates and overwrites this explicit domain with
    // the default one (setActiveDomain does not set the "negotiated" flag).
    $negotiator->getActiveDomain();
    $negotiator->setActiveDomain(Domain::load($id));
    \Drupal::entityTypeManager()->getAccessControlHandler('aabenforms_case')->resetCache();
  }

}
