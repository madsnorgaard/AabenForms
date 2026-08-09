<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Proves no CPR-typed webform element escapes the presave encryption (#172).
 *
 * The regression this guards: the presave hook matched the element type
 * 'cpr_field' while the plugin id was 'aabenforms_cpr_field', so the type
 * branch was dead and encryption survived only on the key-naming convention.
 * A CPR-typed element with a non-conventional key (cpr_test_form's
 * cpr_number) was stored in plaintext.
 *
 * @group aabenforms_core
 * @group encryption
 */
class CprEncryptionPresaveTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'webform',
    'aabenforms_core',
    'aabenforms_webform',
  ];

  /**
   * The CPR access helper.
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected $cprAccess;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);

    // Single-tenant context (no active domain), so protect() uses the
    // default profile.
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

    $this->cprAccess = $this->container->get('aabenforms_core.cpr_access');
  }

  /**
   * The cpr_field webform element plugin resolves under its shipped id.
   *
   * All shipped forms use '#type': cpr_field. Before #172 no plugin carried
   * that id, so Webform fell back to the generic element and the forms lost
   * modulus-11 validation and masked display.
   */
  public function testCprFieldPluginResolvesUnderShippedId(): void {
    /** @var \Drupal\webform\Plugin\WebformElementManagerInterface $manager */
    $manager = $this->container->get('plugin.manager.webform.element');
    $this->assertTrue($manager->hasDefinition('cpr_field'), 'cpr_field webform element plugin exists');
    $element = ['#type' => 'cpr_field'];
    $this->assertSame('cpr_field', $manager->getElementInstance($element)->getPluginId(), 'cpr_field resolves to the real plugin, not the generic fallback');
    // The matching render element exists too, so Drupal-rendered forms get
    // an input rather than nothing.
    $this->assertNotEmpty($this->container->get('element_info')->getInfo('cpr_field'), 'cpr_field render element is defined');
  }

  /**
   * Every CPR-bearing element variant is encrypted at rest, others untouched.
   */
  public function testPresaveEncryptsAllCprVariants(): void {
    $webform = Webform::create([
      'id' => 'cpr_presave_test',
      'title' => 'CPR presave test',
    ]);
    $webform->setElements([
      // The #172 shape: CPR-typed element, key outside the convention.
      'cpr_number' => ['#type' => 'cpr_field', '#title' => 'CPR'],
      // Stored/imported config may still carry the legacy id.
      'legacy_typed' => ['#type' => 'aabenforms_cpr_field', '#title' => 'CPR legacy'],
      // Plain textfield covered only by the key convention.
      'applicant_cpr' => ['#type' => 'textfield', '#title' => 'Ansøgers CPR'],
      // Must stay untouched.
      'note' => ['#type' => 'textfield', '#title' => 'Note'],
    ]);
    $webform->save();

    $submission = WebformSubmission::create([
      'webform_id' => 'cpr_presave_test',
      'data' => [
        'cpr_number' => '0101904521',
        'legacy_typed' => '1502856234',
        'applicant_cpr' => '2506924015',
        'note' => 'ikke et CPR',
      ],
    ]);
    $submission->save();

    $stored = WebformSubmission::load($submission->id())->getData();
    foreach (['cpr_number', 'legacy_typed', 'applicant_cpr'] as $key) {
      $this->assertTrue($this->cprAccess->isProtected($stored[$key]), "$key is encrypted at rest");
    }
    $this->assertSame('ikke et CPR', $stored['note'], 'non-CPR field is untouched');

    // The point of use can still read the value back.
    $this->assertSame('0101904521', $this->cprAccess->reveal($stored['cpr_number']));

    // Re-saving does not double-encrypt.
    $reloaded = WebformSubmission::load($submission->id());
    $reloaded->save();
    $this->assertSame('0101904521', $this->cprAccess->reveal(WebformSubmission::load($submission->id())->getData()['cpr_number']));
  }

}
