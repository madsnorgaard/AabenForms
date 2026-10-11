<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post_eca\Kernel;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Digital Post fritagelse end-to-end: send action -> log -> case -> klagefrist.
 *
 * Runs the real aabenforms_digital_post_send and aabenforms_case_set_klagefrist
 * actions in the order the merudgifter flow does (afslag letter, then
 * klagefrist), with the DEMO registration lookup and the fake_db transport.
 *
 * @group aabenforms_digital_post
 */
class FritagelseDecisionLetterTest extends KernelTestBase {

  /**
   * Birthe Jensen: demo persona fritaget for Digital Post.
   */
  private const BIRTHE = '2403482210';

  /**
   * Freja Nielsen: demo persona tilmeldt Digital Post.
   */
  private const FREJA = '0101904521';

  /**
   * A CPR that is not in the demo fixture (registration unknown).
   */
  private const UNLISTED = '1111111118';

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
    'externalauth',
    'openid_connect',
    'modeler_api',
    'modeler',
    'bpmn_io',
    'eca',
    'eca_content',
    'webform',
    'aabenforms_core',
    'aabenforms_mitid',
    'aabenforms_workflows',
    'aabenforms_case',
    'aabenforms_digital_post',
    'aabenforms_digital_post_eca',
  ];

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
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
    $this->installConfig(['aabenforms_digital_post']);
    $this->config('aabenforms_digital_post.settings')
      ->set('test_mode', 'fake_db')
      ->set('registration_lookup', 'demo')
      ->set('sender_cvr', '12345678')
      ->set('sender_name', 'Test Kommune')
      ->save();
    $this->config('aabenforms_case.settings')
      ->set('physical_post_delivery_days', 5)
      ->save();
    Webform::create(['id' => 'merudgifter', 'title' => 'Merudgifter'])->save();
  }

  /**
   * Exempt persona: physical print job, case channel physical, shifted frist.
   */
  public function testExemptCitizenGetsPhysicalLetterAndShiftedKlagefrist(): void {
    $case = $this->decidedCaseFor(self::BIRTHE);
    $this->sendDecisionLetter();

    $rows = $this->logRows();
    $this->assertCount(1, $rows, 'Exactly one letter recorded');
    $this->assertSame('physical', $rows[0]['channel']);
    $this->assertSame('queued_print', $rows[0]['status']);
    $this->assertSame('physical_demo', $rows[0]['mode']);

    $this->assertSame('success', $this->token('digital_post_result_status'), 'Flow gate sees success, so flows do not change');
    $this->assertSame('physical', $this->token('digital_post_result_delivery_channel'));

    $this->setKlagefrist();
    $case = $this->reload($case);
    $this->assertSame('physical', $case->get('delivery_channel')->value);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame($now + (5 * 86400) + (4 * 7 * 86400), (int) $case->get('klagefrist')->value, 'Klagefrist runs from send date + 5 days');
  }

  /**
   * Registered persona: digital only, klagefrist unchanged.
   */
  public function testRegisteredCitizenStaysDigitalWithUnchangedKlagefrist(): void {
    $case = $this->decidedCaseFor(self::FREJA);
    $this->sendDecisionLetter();

    $rows = $this->logRows();
    $this->assertCount(1, $rows);
    $this->assertSame('digital', $rows[0]['channel']);
    $this->assertSame('success', $rows[0]['status']);
    $this->assertSame('fake_db', $rows[0]['mode']);
    $this->assertSame('digital', $this->token('digital_post_result_delivery_channel'));

    $this->setKlagefrist();
    $case = $this->reload($case);
    $this->assertSame('digital', $case->get('delivery_channel')->value);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame($now + (4 * 7 * 86400), (int) $case->get('klagefrist')->value, 'Digital klagefrist is unchanged');
  }

  /**
   * Unknown status: both channels, manual follow-up, citizen-favourable frist.
   */
  public function testUnknownStatusSendsBothAndFlagsManualFollowUp(): void {
    $case = $this->decidedCaseFor(self::UNLISTED);
    $this->sendDecisionLetter();

    $rows = $this->logRows();
    $this->assertCount(2, $rows, 'One digital and one physical row');
    $channels = array_column($rows, 'channel');
    sort($channels);
    $this->assertSame(['digital', 'physical'], $channels);
    $this->assertSame('success', $this->token('digital_post_result_status'));
    $this->assertSame('digital_and_physical', $this->token('digital_post_result_delivery_channel'));

    $followUps = (int) $this->container->get('database')->select('aabenforms_audit_log', 'a')
      ->condition('action', 'digital_post_manual_followup')
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, $followUps, 'A manual follow-up audit event was emitted');

    $this->setKlagefrist();
    $case = $this->reload($case);
    $this->assertSame('digital_and_physical', $case->get('delivery_channel')->value);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->assertSame($now + (5 * 86400) + (4 * 7 * 86400), (int) $case->get('klagefrist')->value);
  }

  /**
   * Live mode: the DEMO print channel refuses, the send fails closed.
   */
  public function testLiveModeFailsClosedForExemptCitizen(): void {
    $this->config('aabenforms_digital_post.settings')->set('test_mode', 'live_test')->save();
    $case = $this->decidedCaseFor(self::BIRTHE);
    $this->sendDecisionLetter();

    // The demo fixture is disabled in live mode, so Birthe is "unknown": the
    // digital leg goes to the live client and the physical leg is refused.
    $this->assertSame('failed', $this->token('digital_post_result_status'), 'No fake success in live mode');
    $physical = $this->container->get('database')->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['status', 'reason_code'])
      ->condition('channel', 'physical')
      ->execute()->fetchAssoc();
    $this->assertSame('failure', $physical['status']);
    $this->assertSame('PRINT_UNAVAILABLE', $physical['reason_code']);
    $this->assertNull($this->reload($case)->get('delivery_channel')->value, 'No channel recorded for a failed send');
  }

  /**
   * Creates a submission + decided case and seeds the flow's tokens.
   */
  private function decidedCaseFor(string $cpr): AabenformsCase {
    $submission = WebformSubmission::create([
      'webform_id' => 'merudgifter',
      'data' => ['note' => 'Ansøgning'],
    ]);
    $submission->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    /** @var \Drupal\aabenforms_case\Entity\AabenformsCase $case */
    $case = $storage->create([
      'title' => 'Merudgifter',
      'case_type' => 'merudgifter',
      'status' => 'afgoerelse',
      'submission_ref' => $submission->id(),
    ]);
    $case->save();
    $tokens = $this->container->get('eca.token_services');
    $tokens->addTokenData('webform_submission', $submission);
    $tokens->addTokenData('case_id', (string) $case->id());
    $tokens->addTokenData('citizen_cpr', $cpr);
    return $case;
  }

  /**
   * Runs the send action configured like the merudgifter afslag letter.
   */
  private function sendDecisionLetter(): void {
    $this->container->get('plugin.manager.action')
      ->createInstance('aabenforms_digital_post_send', [
        'recipient_token' => '[citizen_cpr]',
        'recipient_type' => 'cpr',
        'sender_cvr_token' => '',
        'subject_template' => 'Afgørelse: merudgiftsydelse',
        'body_template' => '<p>Afslag. Se klagevejledning.</p>',
        'type' => 'Digital Post',
        'result_token' => 'digital_post_result',
      ])
      ->execute();
  }

  /**
   * Runs the klagefrist action like the merudgifter flow.
   */
  private function setKlagefrist(): void {
    $this->container->get('plugin.manager.action')
      ->createInstance('aabenforms_case_set_klagefrist', [
        'case_id_token' => '[case_id]',
        'klagefrist_uger' => 4,
      ])
      ->execute();
  }

  /**
   * Returns all log rows.
   *
   * @return array<int, array<string, string>>
   *   The rows.
   */
  private function logRows(): array {
    return $this->container->get('database')->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['transaction_id', 'mode', 'channel', 'status'])
      ->orderBy('id')
      ->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Reads a scalar ECA token as a string.
   */
  private function token(string $name): string {
    $value = $this->container->get('eca.token_services')->getTokenData($name);
    if (is_object($value) && method_exists($value, 'getString')) {
      return $value->getString();
    }
    return (string) $value;
  }

  /**
   * Reloads a case fresh.
   */
  private function reload(AabenformsCase $case): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $storage->resetCache([$case->id()]);
    return $storage->load($case->id());
  }

}
