<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\aabenforms_core\Controller\WebformApiController;
use Drupal\aabenforms_core\Service\WebformStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\webform\Entity\Webform;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the wizard pages and GEDISA guidance exposed by GET /api/webform/{id}.
 *
 * Covers WebformStructure::pages() (wizard pages in order, nested containers
 * flattened, hidden elements and containers left out, loose inputs in a
 * synthetic first page, flat forms give []), WebformStructure::gedisa()
 * (every key present, nulls and empty lists when unset, the third-party
 * settings schema accepts the authored shape) and the submit path's
 * enforcement of the required erklaering checkbox (422 naming the field).
 *
 * @group aabenforms_core
 */
class WebformStructureTest extends KernelTestBase {

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
    'aabenforms_digital_post',
  ];

  /**
   * The service under test.
   */
  protected WebformStructure $structure;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);

    // The submission presave encrypts CPR elements with the default profile.
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

    $this->structure = $this->container->get('aabenforms_core.webform_structure');
  }

  /**
   * Builds the GEDISA-shaped test form: two wizard pages plus guidance.
   */
  protected function createWizardWebform(): Webform {
    $webform = Webform::create([
      'id' => 'gedisa_test',
      'title' => 'GEDISA test',
      'third_party_settings' => [
        'aabenforms_core' => [
          'intro' => '<p>Her søger du om dækning af merudgifter.</p>',
          'have_ready' => ['Barnets CPR-nummer', 'Et overslag over udgifterne'],
          'legal_basis' => [
            ['title' => 'Serviceloven § 41', 'url' => 'https://www.retsinformation.dk/eli/lta/2024/1089'],
            ['title' => 'Forvaltningsloven § 7'],
          ],
          'expected_response' => 'Du får normalt svar inden for 8 uger.',
          'next_steps' => '<p>Vi sender svaret i Digital Post.</p>',
        ],
      ],
    ]);
    $webform->setElements([
      'workflow_id' => ['#type' => 'hidden'],
      'om_ansoegningen' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Om ansøgningen',
        'intro' => ['#type' => 'webform_markup', '#markup' => '<p>Intro</p>'],
        'screen_barn_under_18' => [
          '#type' => 'radios',
          '#title' => 'Er barnet under 18 år?',
          '#options' => ['ja' => 'Ja', 'nej' => 'Nej'],
        ],
        'stop_barn_over_18' => [
          '#type' => 'webform_markup',
          '#markup' => '<p>Du kan ikke søge her.</p>',
          '#attributes' => ['data-aabenforms-alert' => 'stop'],
          '#states' => ['visible' => [':input[name="screen_barn_under_18"]' => ['value' => 'nej']]],
        ],
      ],
      'opsummering' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Opsummering og underskrift',
        '#description' => 'Tjek dine svar.',
        'gruppe' => [
          '#type' => 'fieldset',
          '#title' => 'Kontakt',
          'applicant_phone' => ['#type' => 'tel', '#title' => 'Telefon'],
          'intern_status' => ['#type' => 'hidden'],
        ],
        'erklaering' => [
          '#type' => 'checkbox',
          '#title' => 'Jeg erklærer, at oplysningerne er rigtige.',
          '#required' => TRUE,
          '#required_error' => 'Du skal bekræfte erklæringen, før du kan sende.',
        ],
      ],
    ]);
    $webform->save();
    return Webform::load('gedisa_test');
  }

  /**
   * Pages follow the wizard containers, flattened, without hidden elements.
   */
  public function testPagesFromWizardContainers(): void {
    $webform = $this->createWizardWebform();

    $pages = $this->structure->pages($webform);
    $this->assertCount(2, $pages, 'the hidden root element does not create a synthetic page');
    $this->assertSame([
      'key' => 'om_ansoegningen',
      'title' => 'Om ansøgningen',
      'description' => '',
      'elements' => ['intro', 'screen_barn_under_18', 'stop_barn_over_18'],
    ], $pages[0]);
    $this->assertSame([
      'key' => 'opsummering',
      'title' => 'Opsummering og underskrift',
      'description' => 'Tjek dine svar.',
      'elements' => ['applicant_phone', 'erklaering'],
    ], $pages[1], 'the fieldset is flattened, listed by its children only, and its hidden child is skipped');
  }

  /**
   * Visible inputs outside every page land in a synthetic first page.
   */
  public function testLooseVisibleInputsGetSyntheticFirstPage(): void {
    $webform = Webform::create(['id' => 'loose_test', 'title' => 'Loose']);
    $webform->setElements([
      'workflow_id' => ['#type' => 'hidden'],
      'note' => ['#type' => 'textfield', '#title' => 'Note'],
      'side' => [
        '#type' => 'webform_wizard_page',
        '#title' => 'Side',
        'felt' => ['#type' => 'textfield', '#title' => 'Felt'],
      ],
    ]);
    $webform->save();

    $pages = $this->structure->pages($webform);
    $this->assertCount(2, $pages);
    $this->assertSame('start', $pages[0]['key']);
    $this->assertSame(['note'], $pages[0]['elements'], 'only the visible loose input is listed');
    $this->assertSame(['felt'], $pages[1]['elements']);
  }

  /**
   * A form without wizard pages returns no pages (the SPA renders one step).
   */
  public function testFlatFormHasNoPages(): void {
    $webform = Webform::create(['id' => 'flat_test', 'title' => 'Flat']);
    $webform->setElements([
      'applicant_cpr' => ['#type' => 'cpr_field', '#title' => 'CPR'],
      'child_name' => ['#type' => 'textfield', '#title' => 'Navn'],
    ]);
    $webform->save();

    $this->assertSame([], $this->structure->pages($webform));
  }

  /**
   * Guidance comes from third-party settings with every key present.
   */
  public function testGedisaFromThirdPartySettings(): void {
    $webform = $this->createWizardWebform();

    $gedisa = $this->structure->gedisa($webform);
    $this->assertSame(['intro', 'have_ready', 'legal_basis', 'expected_response', 'next_steps', 'recipient'], array_keys($gedisa));
    $this->assertSame('<p>Her søger du om dækning af merudgifter.</p>', $gedisa['intro']);
    $this->assertSame(['Barnets CPR-nummer', 'Et overslag over udgifterne'], $gedisa['have_ready']);
    $this->assertSame([
      ['title' => 'Serviceloven § 41', 'url' => 'https://www.retsinformation.dk/eli/lta/2024/1089'],
      ['title' => 'Forvaltningsloven § 7'],
    ], $gedisa['legal_basis'], 'url is present only when authored');
    $this->assertSame('Du får normalt svar inden for 8 uger.', $gedisa['expected_response']);
    $this->assertSame('<p>Vi sender svaret i Digital Post.</p>', $gedisa['next_steps']);
    $this->assertSame(['name', 'department', 'phone', 'email', 'hours'], array_keys($gedisa['recipient']));
    // Single-tenant test site without a Digital Post sender name: nothing is
    // known about the recipient, and the keys are still there.
    $this->assertNull($gedisa['recipient']['name']);
  }

  /**
   * Unset guidance yields nulls and empty lists, never missing keys.
   */
  public function testGedisaDefaultsWhenUnset(): void {
    $webform = Webform::create(['id' => 'plain_test', 'title' => 'Plain']);
    $webform->setElements(['note' => ['#type' => 'textfield', '#title' => 'Note']]);
    $webform->save();

    $this->assertSame([
      'intro' => NULL,
      'have_ready' => [],
      'legal_basis' => [],
      'expected_response' => NULL,
      'next_steps' => NULL,
      'recipient' => ['name' => NULL, 'department' => NULL, 'phone' => NULL, 'email' => NULL, 'hours' => NULL],
    ], $this->structure->gedisa($webform));
  }

  /**
   * The Digital Post sender name is the recipient name when configured.
   */
  public function testRecipientNameFromDigitalPostSender(): void {
    $this->installConfig(['aabenforms_digital_post']);
    $this->config('aabenforms_digital_post.settings')->set('sender_name', 'Demo Kommune')->save();
    $webform = $this->createWizardWebform();

    $this->assertSame('Demo Kommune', $this->structure->gedisa($webform)['recipient']['name']);
  }

  /**
   * Submit rejects a missing or unticked erklaering with 422 naming the field.
   */
  public function testSubmitRejectsMissingDeclaration(): void {
    $this->createWizardWebform();
    $controller = WebformApiController::create($this->container);

    foreach ([[], ['erklaering' => 0], ['erklaering' => '0'], ['erklaering' => FALSE]] as $data) {
      $response = $controller->submitWebform('gedisa_test', $this->submitRequest([
        'screen_barn_under_18' => 'ja',
        'applicant_phone' => '12345678',
      ] + $data));
      $this->assertSame(422, $response->getStatusCode());
      $body = json_decode((string) $response->getContent(), TRUE);
      $this->assertSame('Validation failed', $body['error']);
      $this->assertSame('erklaering', $body['field']);
      $this->assertSame('Du skal bekræfte erklæringen, før du kan sende.', $body['errors']['erklaering']);
    }

    $this->assertSame(0, (int) \Drupal::entityQuery('webform_submission')->accessCheck(FALSE)->count()->execute(), 'nothing was stored');
  }

  /**
   * Submit accepts a ticked erklaering.
   */
  public function testSubmitAcceptsTickedDeclaration(): void {
    $this->createWizardWebform();
    $controller = WebformApiController::create($this->container);

    $response = $controller->submitWebform('gedisa_test', $this->submitRequest([
      'screen_barn_under_18' => 'ja',
      'applicant_phone' => '12345678',
      'erklaering' => 1,
    ]));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(1, (int) \Drupal::entityQuery('webform_submission')->accessCheck(FALSE)->count()->execute());
  }

  /**
   * Builds a POST /api/webform/{id}/submit request from the SPA's shape.
   */
  protected function submitRequest(array $data): Request {
    return Request::create(
      '/api/webform/gedisa_test/submit',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json'],
      json_encode(['data' => ['attributes' => ['data' => $data]]]),
    );
  }

}
