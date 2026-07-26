<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_institution\Kernel;

use Drupal\aabenforms_institution\Entity\AabenformsInstitution;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the institution entity, registry queries and org-chart decoration.
 *
 * @group aabenforms_institution
 */
class InstitutionRegistryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'file',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'modeler_api',
    'eca',
    'webform',
    'aabenforms_core',
    // Hard dep of aabenforms_workflows: parent_cpr_verifier injects
    // aabenforms_mitid.session_manager.
    'aabenforms_mitid',
    'aabenforms_workflows',
    'aabenforms_institution',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aabenforms_institution');
  }

  /**
   * Creates an institution.
   *
   * @param array $values
   *   Entity values.
   *
   * @return \Drupal\aabenforms_institution\Entity\AabenformsInstitution
   *   The saved institution.
   */
  protected function createInstitution(array $values): AabenformsInstitution {
    /** @var \Drupal\aabenforms_institution\Entity\AabenformsInstitution $institution */
    $institution = $this->container->get('entity_type.manager')
      ->getStorage('aabenforms_institution')
      ->create($values + ['type' => AabenformsInstitution::TYPE_SKOLE, 'active' => TRUE]);
    $institution->save();
    return $institution;
  }

  /**
   * FindByNumber resolves institutions by institutionsnummer.
   */
  public function testFindByNumber(): void {
    $this->createInstitution([
      'name' => 'Frederiksbjerg Skole',
      'institution_number' => '751101',
      'district' => 'Frederiksbjerg',
      'leader_email' => 'leder@example.dk',
    ]);

    $registry = $this->container->get('aabenforms_institution.registry');
    $found = $registry->findByNumber('751101');
    $this->assertNotNull($found);
    $this->assertSame('Frederiksbjerg Skole', $found->label());
    $this->assertNull($registry->findByNumber('999999'));
    $this->assertNull($registry->findByNumber(''));
  }

  /**
   * Duplicate institution numbers are rejected by the unique constraint.
   */
  public function testInstitutionNumberUnique(): void {
    $this->createInstitution(['name' => 'A', 'institution_number' => '751101']);
    $duplicate = $this->container->get('entity_type.manager')
      ->getStorage('aabenforms_institution')
      ->create([
        'name' => 'B',
        'institution_number' => '751101',
        'type' => AabenformsInstitution::TYPE_SKOLE,
      ]);
    $violations = $duplicate->validate();
    $this->assertGreaterThan(0, $violations->count());
  }

  /**
   * Leader email escalates up the parent chain when the school has none.
   */
  public function testLeaderEmailEscalatesToParent(): void {
    $forvaltning = $this->createInstitution([
      'name' => 'Børn og Unge',
      'institution_number' => '751000',
      'type' => AabenformsInstitution::TYPE_FORVALTNING,
      'leader_email' => 'bu@example.dk',
    ]);
    $this->createInstitution([
      'name' => 'Skåde Skole',
      'institution_number' => '751105',
      'leader_email' => '',
      'parent_institution' => $forvaltning->id(),
    ]);

    $registry = $this->container->get('aabenforms_institution.registry');
    $this->assertSame('bu@example.dk', $registry->leaderEmailFor('751105'));
    // A school with its own leader email uses it directly.
    $this->createInstitution([
      'name' => 'Risskov Skole',
      'institution_number' => '751104',
      'leader_email' => 'risskov@example.dk',
      'parent_institution' => $forvaltning->id(),
    ]);
    $this->assertSame('risskov@example.dk', $registry->leaderEmailFor('751104'));
    // Unknown institution: ''.
    $this->assertSame('', $registry->leaderEmailFor('999999'));
  }

  /**
   * ListActive filters by type and excludes inactive institutions.
   */
  public function testListActive(): void {
    $this->createInstitution(['name' => 'B skole', 'institution_number' => '751102']);
    $this->createInstitution(['name' => 'A skole', 'institution_number' => '751101']);
    $this->createInstitution([
      'name' => 'Lukket skole',
      'institution_number' => '751199',
      'active' => FALSE,
    ]);
    $this->createInstitution([
      'name' => 'Solstrålen',
      'institution_number' => '751201',
      'type' => AabenformsInstitution::TYPE_DAGTILBUD,
    ]);

    $registry = $this->container->get('aabenforms_institution.registry');
    $schools = $registry->listActive(AabenformsInstitution::TYPE_SKOLE);
    $this->assertCount(2, $schools);
    $this->assertSame('A skole', $schools[0]->label());
    $this->assertCount(3, $registry->listActive());
  }

  /**
   * The org-chart decorator resolves inst:-prefixed ids via the registry.
   */
  public function testOrgChartDecoration(): void {
    $this->createInstitution([
      'name' => 'Frederiksbjerg Skole',
      'institution_number' => '751101',
      'leader_email' => 'leder@example.dk',
    ]);

    $orgChart = $this->container->get('aabenforms_workflows.org_chart');
    $this->assertSame('leder@example.dk', $orgChart->findManagerEmail('inst:751101'));
    $this->assertSame('', $orgChart->findManagerEmail('inst:999999'));
    // Non-institution ids delegate to the decorated config directory.
    $this->assertSame('', $orgChart->findManagerEmail('unknown-employee'));
  }

}
