<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Kernel;

use Drupal\aabenforms_core\Service\FrontendUrl;
use Drupal\aabenforms_workflows\Controller\SchoolTransferReceiptController;
use Drupal\aabenforms_workflows\Form\ParentApprovalForm;
use Drupal\aabenforms_workflows\Form\SchoolTransferDecisionForm;
use Drupal\Core\Form\FormState;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the skoleskift co-signer receipt and the co-sign return redirect.
 *
 * @group aabenforms_workflows
 */
class SchoolTransferReceiptTest extends KernelTestBase {

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
    'eca_content',
    'webform',
    'aabenforms_core',
    'aabenforms_webform',
    'aabenforms_mitid',
    'aabenforms_case',
    'aabenforms_workflows',
  ];

  protected const FREJA_CPR = '0101904521';
  protected const LARS_CPR = '0803755210';
  protected const EMIL_CPR = '0109182345';

  /**
   * The DDEV_HOSTNAME value present before the test, to restore.
   */
  protected string|false $previousDdevHostname = FALSE;

  /**
   * The co-signed submission.
   */
  protected WebformSubmission $submission;

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

    // Seeded demo sessions are only honoured on DDEV.
    $this->previousDdevHostname = getenv('DDEV_HOSTNAME');
    putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 1]));

    // CPRs are encrypted at rest; the receipt must match on the decrypted
    // value.
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

    $schools = ['751101' => 'Frederiksbjerg Skole', '751104' => 'Risskov Skole'];
    Webform::create([
      'id' => 'school_transfer',
      'title' => 'Skoleskift',
      'elements' => Yaml::encode([
        'applicant_cpr' => ['#type' => 'cpr_field', '#title' => 'CPR'],
        'child_name' => ['#type' => 'textfield', '#title' => 'Barnets navn'],
        'child_cpr' => ['#type' => 'cpr_field', '#title' => 'Barnets CPR'],
        'current_school' => ['#type' => 'select', '#title' => 'Nu', '#options' => $schools],
        'target_school' => ['#type' => 'select', '#title' => 'Ønsket', '#options' => $schools],
        'parent2_cpr' => ['#type' => 'cpr_field', '#title' => 'Anden forælder'],
        'parent1_status' => ['#type' => 'hidden'],
        'parent2_status' => ['#type' => 'hidden'],
        'caseworker_status' => ['#type' => 'hidden'],
        'caseworker_comment' => ['#type' => 'hidden'],
      ]),
    ])->save();

    $this->submission = WebformSubmission::create([
      'webform_id' => 'school_transfer',
      'data' => [
        'applicant_cpr' => self::FREJA_CPR,
        'child_name' => 'Emil Nielsen Andersen',
        'child_cpr' => self::EMIL_CPR,
        'current_school' => '751101',
        'target_school' => '751104',
        'parent2_cpr' => self::LARS_CPR,
        'parent1_status' => 'complete',
        'parent2_status' => 'complete',
        'caseworker_status' => 'pending',
      ],
    ]);
    $this->submission->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->previousDdevHostname === FALSE ? 'DDEV_HOSTNAME' : 'DDEV_HOSTNAME=' . $this->previousDdevHostname);
    parent::tearDown();
  }

  /**
   * Calls the receipt endpoint with an optional Authorization header.
   */
  protected function receipt(?string $session, array $query = []): array {
    $request = Request::create('/api/skoleskift/medunderskrift', 'GET', $query);
    if ($session !== NULL) {
      $request->headers->set('Authorization', 'Bearer ' . $session);
    }
    $response = SchoolTransferReceiptController::create($this->container)->receipt($request);
    return [
      'status' => $response->getStatusCode(),
      'raw' => (string) $response->getContent(),
      'body' => json_decode((string) $response->getContent(), TRUE),
    ];
  }

  /**
   * Seeds a demo persona session.
   */
  protected function seed(string $persona): string {
    $id = $this->container->get('aabenforms_mitid.session_manager')->seedDemoSession($persona, 'wf_test_' . $persona);
    $this->assertNotNull($id);
    return $id;
  }

  /**
   * The receipt is authorised only by the co-signer's own session header.
   */
  public function testReceiptAuthorisation(): void {
    $this->assertSame(401, $this->receipt(NULL)['status']);
    $this->assertSame(401, $this->receipt('wf_does_not_exist')['status']);
    // A session id in the URL is never accepted.
    $lars = $this->seed('lars');
    $this->assertSame(401, $this->receipt(NULL, ['session' => $lars])['status']);
    // Freja applied but is not the co-signer (parent2) of any submission.
    $this->assertSame(404, $this->receipt($this->seed('freja'))['status']);
  }

  /**
   * Lars gets the receipt, matched on the session CPR server-side, no CPR out.
   */
  public function testReceiptForCoSigner(): void {
    $result = $this->receipt($this->seed('lars'));
    $this->assertSame(200, $result['status']);
    $data = $result['body']['data'];
    $this->assertSame((int) $this->submission->id(), $data['submission_id']);
    $this->assertSame((int) $this->submission->getCreatedTime(), $data['submitted']);
    $this->assertSame('awaiting_decision', $data['stage']);
    $this->assertSame('Emil Nielsen Andersen', $data['child_name']);
    $this->assertSame('Risskov Skole', $data['target_school']);
    $this->assertSame([], $data['decision_letters_sent_to']);
    $this->assertCount(2, $data['signed_by']);
    foreach ([self::FREJA_CPR, self::LARS_CPR, self::EMIL_CPR] as $cpr) {
      $this->assertStringNotContainsString(substr($cpr, 0, 6), $result['raw'], 'No CPR (not even masked) in the receipt');
    }

    // Once decided, the stage moves on.
    $this->submission->setElementData('caseworker_status', 'approved')->save();
    $this->assertSame('in_progress', $this->receipt($this->seed('lars'))['body']['data']['stage']);
  }

  /**
   * The school leader sees masked CPRs only.
   */
  public function testStaffSummaryIsMasked(): void {
    $view = $this->container->get('aabenforms_workflows.school_transfer_case_view');
    $pending = $view->awaitingDecision();
    $this->assertCount(1, $pending);
    $summary = $view->staffSummary($pending[0]);
    $this->assertSame('010918-XXXX', $summary['child_cpr_masked']);
    $this->assertSame('080375-XXXX', $summary['signatures'][1]['cpr_masked']);
    $this->assertStringNotContainsString(self::LARS_CPR, json_encode($summary));
  }

  /**
   * A school transfer co-sign returns to the frontend; nothing else does.
   */
  public function testCoSignReturnRedirect(): void {
    $form = ParentApprovalForm::create($this->container);
    $prop = new \ReflectionProperty(ParentApprovalForm::class, 'frontendUrl');
    $prop->setValue($form, new FrontendUrl('https://aabenforms-frontend.ddev.site:3001'));

    $this->assertSame(
      'https://aabenforms-frontend.ddev.site:3001/demo/skoleskift?som=lars&medunderskrevet=1',
      $form->frontendReturnUrl('school_transfer', 'approve')
    );
    $this->assertNull($form->frontendReturnUrl('school_transfer', 'reject'));
    $this->assertNull($form->frontendReturnUrl('parent_request_form', 'approve'));

    // No valid allowlisted origin: keep the Drupal completion page.
    $bad_origins = [
      '',
      '*',
      'https://evil.example/path',
      'javascript:alert(1)',
      'https://user@evil.example',
      '//evil.example',
    ];
    foreach ($bad_origins as $bad) {
      $prop->setValue($form, new FrontendUrl($bad));
      $this->assertNull($form->frontendReturnUrl('school_transfer', 'approve'), "Origin '$bad' is rejected");
    }
    // A list takes the first entry.
    $this->assertSame('https://a.example', (new FrontendUrl('https://a.example, https://b.example'))->origin());
  }

  /**
   * The decision form requires an org-chart employee and a rejection reason.
   */
  public function testDecisionFormGates(): void {
    $this->config('aabenforms_workflows.org_chart')->set('employees', [
      'E1003' => ['name' => 'Skoleleder', 'account_name' => 'skoleleder'],
    ])->save();
    $sid = (int) $this->submission->id();
    $submit = function (string $account, array $values): array {
      $this->container->get('current_user')->setAccount(new UserSession(['uid' => 7, 'name' => $account]));
      $state = (new FormState())->setValues($values)->addBuildInfo('args', [$this->submission]);
      $this->container->get('form_builder')->submitForm(SchoolTransferDecisionForm::class, $state);
      return array_map('strval', $state->getErrors());
    };

    $errors = $submit('someone', ['decision' => 'approved']);
    $this->assertStringContainsString('not registered as an employee', implode(' ', $errors));
    $this->assertSame('pending', WebformSubmission::load($sid)->getElementData('caseworker_status'));

    $errors = $submit('skoleleder', ['decision' => 'rejected', 'rejection_reason' => '']);
    $this->assertArrayHasKey('rejection_reason', $errors, json_encode($errors));

    $errors = $submit('skoleleder', ['decision' => 'rejected', 'rejection_reason' => 'Ingen plads på skolen.']);
    $this->assertSame([], $errors);
    $storage = $this->container->get('entity_type.manager')->getStorage('webform_submission');
    $storage->resetCache([$sid]);
    $saved = $storage->load($sid);
    $this->assertSame('rejected', $saved->getElementData('caseworker_status'));
    $this->assertSame('Ingen plads på skolen.', $saved->getElementData('caseworker_comment'));

    // Already decided: a second decision is refused.
    $errors = $submit('skoleleder', ['decision' => 'approved']);
    $this->assertStringContainsString('already been decided', implode(' ', $errors));
  }

}
