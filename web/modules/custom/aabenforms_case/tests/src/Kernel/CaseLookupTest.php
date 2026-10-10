<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_case\Kernel;

use Drupal\aabenforms_case\Service\CaseLookup;
use Drupal\Core\Serialization\Yaml;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests the hash-only case-by-CPR index (CaseLookup::findByCpr).
 *
 * @group aabenforms_case
 */
class CaseLookupTest extends KernelTestBase {

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

  protected const CPR_A = '2403482210';
  protected const CPR_B = '0101904521';

  /**
   * The service under test.
   */
  protected CaseLookup $lookup;

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
      ->set('frister', ['default' => ['unit' => 'hverdage', 'amount' => 28]])
      ->save();

    // Encryption profile so the presave hook can protect the CPR at rest and
    // the lookup has something real to reveal.
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

    $this->lookup = $this->container->get('aabenforms_case.case_lookup');
  }

  /**
   * The hash is the audit log's function over the bare digits.
   */
  public function testHashMatchesAuditLogConvention(): void {
    $this->assertSame(hash('sha256', self::CPR_A), CaseLookup::hashCpr(self::CPR_A));
    $this->assertSame(hash('sha256', self::CPR_A), CaseLookup::hashCpr('240348-2210'), 'Formatting is ignored');
    $this->assertSame('', CaseLookup::hashCpr('12345'), 'Not a CPR, no hash');
    $this->assertSame('', CaseLookup::hashCpr(''));
  }

  /**
   * The lookup returns only the subject's cases, newest first, summarized.
   */
  public function testFindByCprReturnsOwnCasesNewestFirst(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $first = $storage->create([
      'title' => 'Første sag',
      'case_type' => 'merudgifter',
      'subject_cpr_hash' => CaseLookup::hashCpr(self::CPR_A),
    ]);
    $first->save();
    $storage->create([
      'title' => 'Fremmed sag',
      'case_type' => 'friplads',
      'subject_cpr_hash' => CaseLookup::hashCpr(self::CPR_B),
    ])->save();
    $second = $storage->create([
      'title' => 'Anden sag',
      'case_type' => 'merudgifter',
      'status' => 'afgoerelse',
      'afgoerelse_type' => 'afslag',
      'afgoerelse_dato' => 1704099600,
      'klagefrist' => 1706518800,
      'subject_cpr_hash' => CaseLookup::hashCpr(self::CPR_A),
    ]);
    $second->save();
    // A case without a hash (no applicant CPR) is nobody's.
    $storage->create(['title' => 'Anonym sag', 'case_type' => 'underretning'])->save();

    $cases = $this->lookup->findByCpr(self::CPR_A);
    $this->assertCount(2, $cases);
    $this->assertSame((int) $second->id(), $cases[0]['case_id']);
    $this->assertSame((int) $first->id(), $cases[1]['case_id']);

    $decided = $cases[0];
    $this->assertSame('Anden sag', $decided['title']);
    $this->assertSame('afgoerelse', $decided['status']);
    $this->assertSame('Afgørelse', $decided['status_label']);
    $this->assertSame('afslag', $decided['afgoerelse_type']);
    $this->assertSame('2024-01-01', $decided['afgoerelse_date']);
    $this->assertSame('2024-01-29', $decided['klagefrist']);
    $this->assertTrue($decided['appealable']);
    $this->assertArrayNotHasKey('subject_cpr_hash', $decided, 'The hash never leaves the service');

    $open = $cases[1];
    $this->assertSame('modtaget', $open['status']);
    $this->assertNull($open['afgoerelse_type']);
    $this->assertNull($open['afgoerelse_date']);
    $this->assertFalse($open['appealable']);

    $this->assertCount(1, $this->lookup->findByCpr(self::CPR_B));
    $this->assertSame([], $this->lookup->findByCpr('0000000000'));
    $this->assertSame([], $this->lookup->findByCpr('not a cpr'));
    $this->assertSame([], $this->lookup->findByCprHash('not-a-hash'));
  }

  /**
   * A case decided before afgoerelse_dato existed dates from its revisions.
   */
  public function testDecisionDateFallsBackToRevisionHistory(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $case = $storage->create([
      'title' => 'Gammel sag',
      'case_type' => 'merudgifter',
      'subject_cpr_hash' => CaseLookup::hashCpr(self::CPR_A),
    ]);
    $case->save();
    $case->setStatus('oplyst')->save();
    $case->set('afgoerelse_type', 'medhold')->setStatus('afgoerelse')->save();

    $summary = $this->lookup->findByCpr(self::CPR_A)[0];
    $this->assertSame('medhold', $summary['afgoerelse_type']);
    $today = (new \DateTimeImmutable('@' . $this->container->get('datetime.time')->getRequestTime()))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format('Y-m-d');
    $this->assertSame($today, $summary['afgoerelse_date']);
  }

  /**
   * The subject hash is derived from the first CPR element, decrypted once.
   */
  public function testSubjectHashFromEncryptedSubmission(): void {
    Webform::create([
      'id' => 'merudgifter',
      'title' => 'Merudgifter',
      'elements' => Yaml::encode([
        'applicant_cpr' => ['#type' => 'textfield', '#title' => 'CPR'],
        'child_cpr' => ['#type' => 'textfield', '#title' => 'Barnets CPR'],
      ]),
    ])->save();
    $submission = WebformSubmission::create([
      'webform_id' => 'merudgifter',
      'data' => ['applicant_cpr' => self::CPR_A, 'child_cpr' => '0109182345'],
    ]);
    $submission->save();
    $stored = (string) $submission->getElementData('applicant_cpr');
    $this->assertTrue($this->container->get('aabenforms_core.cpr_access')->isProtected($stored), 'CPR is encrypted at rest');

    $this->assertSame(CaseLookup::hashCpr(self::CPR_A), $this->lookup->subjectHashFromSubmission($submission), 'Applicant, not the child');

    // And aabenforms_case_open stamps it on the new case.
    $this->container->get('eca.token_services')->addTokenData('webform_submission', $submission);
    $this->container->get('plugin.manager.action')
      ->createInstance('aabenforms_case_open', ['case_type' => 'merudgifter', 'case_id_token' => 'case_id'])
      ->execute();
    $cases = $this->lookup->findByCpr(self::CPR_A);
    $this->assertCount(1, $cases);
    $this->assertSame((int) $submission->id(), (int) $this->container->get('entity_type.manager')
      ->getStorage('aabenforms_case')->load($cases[0]['case_id'])->getSubmissionId());
  }

}
