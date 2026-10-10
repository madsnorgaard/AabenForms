<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_case\Kernel;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_case\Service\CaseLookup;
use Drupal\aabenforms_case\Service\KlageDemoSeeder;
use Drupal\Core\Serialization\Yaml;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;

/**
 * Tests the klage demo seeder (drush aabenforms:demo:seed-klage-case).
 *
 * @group aabenforms_case
 */
class KlageDemoSeederTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'modeler_api',
    'eca',
    'webform',
    'aabenforms_core',
    'aabenforms_case',
  ];

  /**
   * The seeder under test.
   */
  protected KlageDemoSeeder $seeder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installEntitySchema('aabenforms_case');
    $this->installSchema('webform', ['webform']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);
    $this->config('aabenforms_case.settings')
      ->set('frister', ['merudgifter' => ['unit' => 'hverdage', 'amount' => 20]])
      ->save();

    Key::create([
      'id' => 'aabenforms_cpr_test',
      'label' => 'CPR test key',
      'key_type' => 'encryption',
      'key_type_settings' => ['key_size' => '256'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => base64_encode(random_bytes(32)), 'base64_encoded' => TRUE],
      'key_input' => 'none',
    ])->save();
    EncryptionProfile::create([
      'id' => 'aabenforms_aes256',
      'label' => 'AES 256',
      'encryption_method' => 'real_aes',
      'encryption_key' => 'aabenforms_cpr_test',
    ])->save();

    $this->seeder = $this->container->get('aabenforms_case.klage_demo_seeder');
  }

  /**
   * Installs the merudgifter webform the seeder files its application on.
   */
  protected function createMerudgifterWebform(): void {
    Webform::create([
      'id' => 'merudgifter',
      'title' => 'Merudgifter',
      'elements' => Yaml::encode([
        'applicant_cpr' => ['#type' => 'textfield', '#title' => 'CPR'],
        'funktionsnedsaettelse' => ['#type' => 'textarea', '#title' => 'Funktionsnedsættelse'],
        'maanedlig_merudgift' => ['#type' => 'number', '#title' => 'Månedlig merudgift'],
      ]),
    ])->save();
  }

  /**
   * Loads a case by id.
   */
  protected function loadCase(int $id): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $storage->resetCache([$id]);
    $case = $storage->load($id);
    $this->assertInstanceOf(AabenformsCase::class, $case);
    return $case;
  }

  /**
   * First run creates a decided afslag case; second run is a no-op.
   */
  public function testSeedIsIdempotent(): void {
    $this->createMerudgifterWebform();
    $now = $this->container->get('datetime.time')->getRequestTime();
    $first = $this->seeder->seed(10);
    $this->assertSame(KlageDemoSeeder::STATUS_CREATED, $first['status']);
    $this->assertTrue($first['appealable']);
    $this->assertNull($first['previous_case_id']);

    $case = $this->loadCase($first['case_id']);
    $this->assertSame('merudgifter', $case->getCaseType());
    $this->assertSame('afgoerelse', $case->getStatus());
    $this->assertSame('afslag', $case->get('afgoerelse_type')->value);
    $this->assertSame('afsluttet', $case->get('partshoering_state')->value);
    $this->assertSame($now - (10 * 86400), $case->getAfgoerelseDato());
    $this->assertSame(
      $this->container->get('aabenforms_case.frist_clock')->computeKlagefrist($now - (10 * 86400), 4),
      (int) $case->get('klagefrist')->value,
      'Klagefrist comes from the FristClock, four weeks from the decision'
    );
    $this->assertSame(CaseLookup::hashCpr(KlageDemoSeeder::PERSONA_CPR), $case->getSubjectCprHash());
    $this->assertNotNull($case->getSubmissionId(), 'The case is backed by a submission');
    // Three revisions: modtaget, oplyst, afgoerelse.
    $vids = $this->container->get('entity_type.manager')->getStorage('aabenforms_case')
      ->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', $case->id())->execute();
    $this->assertCount(3, $vids);

    // The CPR is encrypted on the submission, never on the case.
    $submission = $this->container->get('entity_type.manager')->getStorage('webform_submission')->load($case->getSubmissionId());
    $this->assertTrue($this->container->get('aabenforms_core.cpr_access')->isProtected((string) $submission->getElementData('applicant_cpr')));

    $second = $this->seeder->seed(10);
    $this->assertSame(KlageDemoSeeder::STATUS_EXISTS, $second['status']);
    $this->assertSame($first['case_id'], $second['case_id']);
    $this->assertCount(1, $this->container->get('aabenforms_case.case_lookup')->findByCpr(KlageDemoSeeder::PERSONA_CPR));
  }

  /**
   * A spent case is kept without --reset and replaced with --reset.
   */
  public function testResetReplacesSpentCaseOnly(): void {
    $this->createMerudgifterWebform();
    $first = $this->seeder->seed(10);
    $this->assertSame(KlageDemoSeeder::STATUS_CREATED, $first['status']);

    // Reset on a still-appealable case never duplicates it.
    $noop = $this->seeder->seed(10, TRUE);
    $this->assertSame(KlageDemoSeeder::STATUS_EXISTS, $noop['status']);
    $this->assertSame($first['case_id'], $noop['case_id']);

    // The scenario was run: the case got appealed.
    $this->loadCase($first['case_id'])->setStatus('paaklaget')->save();

    $kept = $this->seeder->seed(10);
    $this->assertSame(KlageDemoSeeder::STATUS_EXISTS, $kept['status']);
    $this->assertSame($first['case_id'], $kept['case_id']);
    $this->assertFalse($kept['appealable']);

    $reset = $this->seeder->seed(10, TRUE);
    $this->assertSame(KlageDemoSeeder::STATUS_RESET, $reset['status']);
    $this->assertNotSame($first['case_id'], $reset['case_id']);
    $this->assertSame($first['case_id'], $reset['previous_case_id']);
    $this->assertTrue($reset['appealable']);
    $this->assertSame('afgoerelse', $this->loadCase($reset['case_id'])->getStatus());
    $this->assertSame('paaklaget', $this->loadCase($first['case_id'])->getStatus(), 'The spent case is left as history');

    // And the fresh one is now the idempotent target, even with --reset.
    $again = $this->seeder->seed(10, TRUE);
    $this->assertSame(KlageDemoSeeder::STATUS_EXISTS, $again['status']);
    $this->assertSame($reset['case_id'], $again['case_id']);
    $this->assertCount(2, $this->container->get('aabenforms_case.case_lookup')->findByCpr(KlageDemoSeeder::PERSONA_CPR));
  }

  /**
   * Without the merudgifter webform the seeder refuses rather than guesses.
   */
  public function testMissingWebformIsAnError(): void {
    $this->expectException(\RuntimeException::class);
    $this->seeder->seed(10);
  }

}
