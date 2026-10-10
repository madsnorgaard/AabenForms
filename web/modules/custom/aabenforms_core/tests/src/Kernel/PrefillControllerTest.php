<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\aabenforms_case\Service\CaseLookup;
use Drupal\aabenforms_core\Controller\PrefillController;
use Drupal\aabenforms_core\Prefill\LegalBasis;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests GET /api/prefill/{webform_id}?session= end to end (issue #250).
 *
 * Seeds demo MitID sessions on a simulated DDEV host and asserts the payload
 * for the merudgifter form (identity + children, no income), the klage form
 * (identity + cases), the sygedagpenge form (identity + employment + the
 * employer's sickness report), the audit trail each lookup leaves, and the gates
 * (missing/unknown session, demo session off DDEV, closed form).
 *
 * @group aabenforms_core
 */
class PrefillControllerTest extends KernelTestBase {

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
    'aabenforms_webform',
    'aabenforms_mitid',
    'aabenforms_case',
  ];

  /**
   * Birthe's synthetic CPR (DemoPersonas::get('birthe')).
   */
  protected const BIRTHE_CPR = '2403482210';

  /**
   * Freja's synthetic CPR (DemoPersonas::get('freja')).
   */
  protected const FREJA_CPR = '0101904521';

  /**
   * The DDEV_HOSTNAME value present before the test, to restore.
   */
  protected string|false $previousDdevHostname = FALSE;

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

    // Act as the super user so webform access rules do not get in the way of
    // what is under test (the gate under test is the MitID session).
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 1]));

    Webform::create([
      'id' => 'merudgifter',
      'title' => 'Merudgifter',
      'elements' => Yaml::encode([
        'workflow_id' => ['#type' => 'hidden'],
        'applicant_cpr' => ['#type' => 'cpr_field', '#title' => 'CPR'],
        'child_name' => ['#type' => 'textfield', '#title' => 'Barnets navn'],
        'child_cpr' => ['#type' => 'cpr_field', '#title' => 'Barnets CPR'],
        'funktionsnedsaettelse' => ['#type' => 'textarea', '#title' => 'Funktionsnedsættelse'],
        'maanedlig_merudgift' => ['#type' => 'number', '#title' => 'Månedlig merudgift'],
      ]),
    ])->save();
    Webform::create([
      'id' => 'klage',
      'title' => 'Klage',
      'elements' => Yaml::encode([
        'case_id' => ['#type' => 'number', '#title' => 'Sagsnummer'],
        'applicant_cpr' => ['#type' => 'textfield', '#title' => 'CPR'],
        'klage_begrundelse' => ['#type' => 'textarea', '#title' => 'Begrundelse'],
      ]),
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->previousDdevHostname === FALSE ? 'DDEV_HOSTNAME' : 'DDEV_HOSTNAME=' . $this->previousDdevHostname);
    parent::tearDown();
  }

  /**
   * Calls the controller as the route would and decodes the JSON.
   *
   * @return array{status:int, body:array|null, cache_control:string}
   *   The HTTP status, decoded body and Cache-Control header.
   */
  protected function prefill(string $webform_id, ?string $session): array {
    $query = $session === NULL ? [] : ['session' => $session];
    $request = Request::create('/api/prefill/' . $webform_id, 'GET', $query);
    $response = PrefillController::create($this->container)->prefill($webform_id, $request);
    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode((string) $response->getContent(), TRUE),
      'cache_control' => (string) $response->headers->get('Cache-Control'),
    ];
  }

  /**
   * Seeds a demo persona session and returns its workflow id.
   */
  protected function seed(string $persona): string {
    $id = $this->container->get('aabenforms_mitid.session_manager')->seedDemoSession($persona, 'wf_test_' . $persona);
    $this->assertNotNull($id, "Seeded a session for $persona");
    return $id;
  }

  /**
   * Formats a timestamp the way the case summary does (Copenhagen date).
   */
  protected function cphDate(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format('Y-m-d');
  }

  /**
   * Returns the prefill audit rows for a webform, keyed by action.
   */
  protected function auditRows(string $webform_id): array {
    $rows = $this->container->get('database')->select('aabenforms_audit_log', 'a')
      ->fields('a')
      ->condition('purpose', $webform_id)
      ->condition('action', 'prefill_%', 'LIKE')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $byAction = [];
    foreach ($rows as $row) {
      $byAction[$row['action']] = $row;
    }
    return $byAction;
  }

  /**
   * Mikkel's synthetic CPR (DemoPersonas::get('mikkel')).
   */
  protected const MIKKEL_CPR = '1502856234';

  /**
   * Merudgifter: identity and two custody children (demo), never income.
   */
  public function testMerudgifterPrefillWithProvenance(): void {
    $session = $this->seed('freja');
    $result = $this->prefill('merudgifter', $session);

    $this->assertSame(200, $result['status']);
    $this->assertStringContainsString('no-store', $result['cache_control']);
    $body = $result['body'];
    $this->assertSame('merudgifter', $body['webform']);
    $this->assertSame('Freja Nielsen', $body['session']['name']);
    $this->assertSame('substantial', $body['session']['assurance_level']);
    $this->assertFalse($body['session']['digital_post_exempt']);
    $this->assertTrue($body['session']['seeded']);

    $fields = $body['fields'];
    $legal = LegalBasis::for('merudgifter');

    // Identity: masked CPR only, never the serial.
    $cpr = $fields['applicant_cpr'];
    $this->assertSame('010190-XXXX', $cpr['value']);
    $this->assertSame('MitID', $cpr['source']['register']);
    $this->assertSame('live-capable', $cpr['source']['mode']);
    $this->assertSame($legal, $cpr['legal_basis']);
    $this->assertIsInt($cpr['audit_id']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/', $cpr['fetched_at']);
    $this->assertStringNotContainsString(self::FREJA_CPR, json_encode($body), 'The raw CPR never leaves the server');

    // Children: Freja has two (Emil, Oliver), both joint custody with Lars,
    // so no single value is prefilled but both are offered.
    $children = $fields['child_cpr'];
    $this->assertNull($children['value']);
    $this->assertCount(2, $children['options']);
    $this->assertSame('SF6006', $children['source']['contract']);
    $this->assertSame('demo', $children['source']['mode'], 'No Serviceplatformen certificate: family data is demo');
    $this->assertSame('joint', $children['options'][0]['custody']);
    $this->assertSame('Emil Nielsen Andersen', $children['options'][0]['name']);
    $this->assertSame('010918-XXXX', $children['options'][0]['cpr']);
    $this->assertSame('080375-XXXX', $children['options'][0]['other_guardian']['cpr']);
    $this->assertSame($children['audit_id'], $fields['child_name']['audit_id'], 'One lookup, one audit row, shared by both child keys');

    // SEL §41 does not depend on the parents' income: it is not fetched
    // (data minimisation).
    $this->assertArrayNotHasKey('household_income', $fields);

    // Not asked: what the registers cannot answer. Hidden workflow_id is not
    // a question either.
    $this->assertSame(['funktionsnedsaettelse', 'maanedlig_merudgift'], $body['not_asked']);

    // Every lookup wrote one audit row with the hashed CPR and the form as
    // purpose, and the response echoes those row ids.
    $rows = $this->auditRows('merudgifter');
    $actions = array_keys($rows);
    sort($actions);
    $this->assertSame(['prefill_children', 'prefill_identity'], $actions);
    $hash = hash('sha256', self::FREJA_CPR);
    foreach ($rows as $row) {
      $this->assertSame($hash, $row['identifier_hash']);
      $this->assertSame('success', $row['status']);
    }
    $this->assertSame((int) $rows['prefill_identity']['id'], $cpr['audit_id']);
    $this->assertSame((int) $rows['prefill_children']['id'], $children['audit_id']);
  }

  /**
   * Sygedagpenge: identity, employer + beskæftigelseskrav, first sick day.
   */
  public function testSygedagpengePrefillWithEmployment(): void {
    Webform::create([
      'id' => 'sygedagpenge',
      'title' => 'Sygedagpenge - oplysningsskema',
      'elements' => Yaml::encode([
        'workflow_id' => ['#type' => 'hidden'],
        'applicant_cpr' => ['#type' => 'cpr_field', '#title' => 'CPR'],
        'employer_cvr' => ['#type' => 'textfield', '#title' => 'CVR'],
        'first_sick_day' => ['#type' => 'date', '#title' => 'Første sygedag'],
        'sickness_description' => ['#type' => 'textarea', '#title' => 'Hvad kan du ikke'],
        'expected_duration' => ['#type' => 'select', '#title' => 'Varighed', '#options' => ['unknown' => 'Ved ikke']],
        'in_treatment' => ['#type' => 'radios', '#title' => 'Behandling', '#options' => ['ja' => 'Ja', 'nej' => 'Nej']],
        'contact_phone' => ['#type' => 'tel', '#title' => 'Telefon'],
      ]),
    ])->save();

    $result = $this->prefill('sygedagpenge', $this->seed('mikkel'));
    $this->assertSame(200, $result['status']);
    $this->assertStringContainsString('no-store', $result['cache_control']);
    $body = $result['body'];
    $fields = $body['fields'];
    $legal = LegalBasis::for('sygedagpenge');
    $this->assertTrue(LegalBasis::has('sygedagpenge'));
    $this->assertStringContainsString('Sygedagpengeloven', $legal);

    $this->assertSame('150285-XXXX', $fields['applicant_cpr']['value']);
    $json = json_encode($body);
    $this->assertStringNotContainsString(self::MIKKEL_CPR, $json, 'The raw CPR never leaves the server');
    $this->assertStringNotContainsString('31500', $json, 'Pay is not sent to the browser');

    // Employer + history from the demo eIndkomst rail, one audit row.
    $this->assertSame('29876543', $fields['employer_cvr']['value']);
    $this->assertStringContainsString('(demo)', $fields['employer_name']['value']);
    $history = $fields['employment_history']['value'];
    $this->assertTrue($history['requirement_met']);
    $this->assertSame(240, $history['required_hours']);
    $this->assertCount(6, $history['months']);
    $this->assertSame(array_sum(array_column($history['months'], 'hours')), $history['total_hours']);
    foreach (['employer_name', 'employer_cvr', 'employment_history'] as $key) {
      $this->assertSame('eIndkomst', $fields[$key]['source']['register']);
      $this->assertSame('demo', $fields[$key]['source']['mode'], 'No eIndkomst integration: employment is demo');
      $this->assertSame($legal, $fields[$key]['legal_basis']);
    }
    $this->assertSame($fields['employer_cvr']['audit_id'], $fields['employment_history']['audit_id']);

    // First sick day from the employer's report (demo NemRefusion).
    $sick = $fields['first_sick_day'];
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $sick['value']);
    $this->assertSame('NemRefusion', $sick['source']['register']);
    $this->assertSame('demo', $sick['source']['mode']);
    $end = new \DateTimeImmutable($sick['employer_period_end']);
    $this->assertSame(29, (int) (new \DateTimeImmutable($sick['value']))->diff($end)->days, '30 calendar-day arbejdsgiverperiode');

    $this->assertSame(['sickness_description', 'expected_duration', 'in_treatment', 'contact_phone'], $body['not_asked']);

    $rows = $this->auditRows('sygedagpenge');
    $actions = array_keys($rows);
    sort($actions);
    $this->assertSame(['prefill_employment', 'prefill_identity', 'prefill_sickness_report'], $actions);
    foreach ($rows as $row) {
      $this->assertSame(hash('sha256', self::MIKKEL_CPR), $row['identifier_hash']);
      $this->assertStringNotContainsString(self::MIKKEL_CPR, (string) $row['context']);
    }
    $this->assertSame((int) $rows['prefill_employment']['id'], $fields['employer_cvr']['audit_id']);
    $this->assertSame((int) $rows['prefill_sickness_report']['id'], $sick['audit_id']);
  }

  /**
   * Klage: Birthe's decided afslag case is offered for case_id.
   */
  public function testKlagePrefillOffersOwnCases(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $case = $storage->create([
      'title' => 'Merudgifter - afslag (demo)',
      'case_type' => 'merudgifter',
      'status' => 'afgoerelse',
      'afgoerelse_type' => 'afslag',
      'afgoerelse_dato' => $now - (10 * 86400),
      'klagefrist' => $now + (18 * 86400),
      'subject_cpr_hash' => CaseLookup::hashCpr(self::BIRTHE_CPR),
    ]);
    $case->save();
    // Somebody else's case must never show up.
    $storage->create([
      'title' => 'Fremmed sag',
      'case_type' => 'merudgifter',
      'status' => 'afgoerelse',
      'afgoerelse_type' => 'afslag',
      'subject_cpr_hash' => CaseLookup::hashCpr(self::FREJA_CPR),
    ])->save();

    $session = $this->seed('birthe');
    $result = $this->prefill('klage', $session);

    $this->assertSame(200, $result['status']);
    $body = $result['body'];
    $this->assertSame('Birthe Jensen', $body['session']['name']);
    $this->assertTrue($body['session']['digital_post_exempt'], 'The Digital Post exemption rides along from the persona');

    $this->assertSame('240348-XXXX', $body['fields']['applicant_cpr']['value']);

    $cases = $body['fields']['case_id'];
    $this->assertSame((int) $case->id(), $cases['value'], 'Exactly one appealable case is prefilled');
    $this->assertCount(1, $cases['options']);
    $offered = $cases['options'][0];
    $this->assertSame('afslag', $offered['afgoerelse_type']);
    $this->assertSame('afgoerelse', $offered['status']);
    $this->assertTrue($offered['appealable']);
    $this->assertSame($this->cphDate($now - (10 * 86400)), $offered['afgoerelse_date']);
    $this->assertSame($this->cphDate($now + (18 * 86400)), $offered['klagefrist']);
    $this->assertSame('live', $cases['source']['mode']);
    $this->assertSame(LegalBasis::for('klage'), $cases['legal_basis']);
    $this->assertArrayNotHasKey('subject_cpr_hash', $offered);

    $this->assertSame(['klage_begrundelse'], $body['not_asked']);

    $rows = $this->auditRows('klage');
    $this->assertArrayHasKey('prefill_cases', $rows);
    $this->assertSame(hash('sha256', self::BIRTHE_CPR), $rows['prefill_cases']['identifier_hash']);
    $this->assertSame((int) $rows['prefill_cases']['id'], $cases['audit_id']);
  }

  /**
   * School transfer: current school per custody child from the STIL demo rail.
   */
  public function testSchoolTransferPrefillsCurrentSchool(): void {
    Webform::create([
      'id' => 'school_transfer',
      'title' => 'Skoleskift',
      'elements' => Yaml::encode([
        'child_name' => ['#type' => 'textfield', '#title' => 'Barnets navn'],
        'child_cpr' => ['#type' => 'cpr_field', '#title' => 'Barnets CPR'],
        'current_school' => [
          '#type' => 'select',
          '#title' => 'Nuværende skole',
          '#options' => [
            '751101' => 'Frederiksbjerg Skole',
            '751102' => 'Katrinebjergskolen',
            '751103' => 'Møllevangskolen',
          ],
        ],
        'custody_status' => [
          '#type' => 'select',
          '#title' => 'Forældremyndighed',
          '#options' => ['sole' => 'Ene', 'joint' => 'Fælles'],
        ],
        'new_school' => ['#type' => 'textfield', '#title' => 'Ønsket skole'],
      ]),
    ])->save();

    // Freja: two children (Emil, Oliver), so no single value, options only.
    $body = $this->prefill('school_transfer', $this->seed('freja'))['body'];
    $school = $body['fields']['current_school'];
    $this->assertNull($school['value']);
    $this->assertSame([
      [
        'child_cpr' => '010918-XXXX',
        'current_school' => ['institution_number' => '751101', 'name' => 'Frederiksbjerg Skole'],
      ],
      [
        'child_cpr' => '200510-XXXX',
        'current_school' => ['institution_number' => '751102', 'name' => 'Katrinebjergskolen'],
      ],
    ], $school['options']);
    $this->assertSame('STIL elevdata', $school['source']['register']);
    $this->assertSame('demo', $school['source']['mode'], 'No STIL integration: enrolment is demo');
    $this->assertStringContainsString('integration ikke etableret', $school['source']['contract']);
    $this->assertSame(LegalBasis::for('school_transfer'), $school['legal_basis']);
    $this->assertSame('751101', $body['fields']['custody_status']['options'][0]['current_school']['institution_number']);
    $this->assertNotContains('current_school', $body['not_asked']);
    $this->assertSame(['new_school'], $body['not_asked']);
    $json = json_encode($body);
    $this->assertStringNotContainsString('0109182345', $json, 'Child CPR never leaves unmasked');
    $this->assertStringNotContainsString(self::FREJA_CPR, $json);

    $rows = $this->auditRows('school_transfer');
    $this->assertArrayHasKey('prefill_enrolment', $rows);
    $row = $rows['prefill_enrolment'];
    $this->assertSame(hash('sha256', self::FREJA_CPR), $row['identifier_hash']);
    $this->assertSame('success', $row['status']);
    $this->assertSame((int) $row['id'], $school['audit_id']);
    $this->assertStringContainsString('010918-XXXX', (string) $row['context']);
    $this->assertStringNotContainsString('0109182345', (string) $row['context']);

    // Sofie: sole custody of Alma, so the school is unambiguous.
    $body = $this->prefill('school_transfer', $this->seed('sofie'))['body'];
    $this->assertSame('751103', $body['fields']['current_school']['value']);
    $this->assertSame('Møllevangskolen', $body['fields']['current_school']['options'][0]['current_school']['name']);
  }

  /**
   * Without a session, or with an unknown one, nothing is disclosed.
   */
  public function testMissingOrUnknownSessionIsRejected(): void {
    $this->assertSame(401, $this->prefill('merudgifter', NULL)['status']);
    $this->assertSame(401, $this->prefill('merudgifter', 'wf_does_not_exist')['status']);
    $this->assertSame([], $this->auditRows('merudgifter'), 'No lookup ran');
  }

  /**
   * A seeded demo session is refused off DDEV, even though it exists.
   */
  public function testSeededSessionIsRefusedOutsideDdev(): void {
    $session = $this->seed('birthe');
    putenv('DDEV_HOSTNAME');
    $result = $this->prefill('klage', $session);
    $this->assertSame(403, $result['status']);
    $this->assertSame([], $this->auditRows('klage'), 'No lookup ran');
  }

  /**
   * Unknown and closed webforms are not prefilled.
   */
  public function testUnknownOrClosedWebform(): void {
    $session = $this->seed('freja');
    $this->assertSame(404, $this->prefill('nope', $session)['status']);
    Webform::create(['id' => 'lukket', 'title' => 'Lukket', 'status' => 'closed'])->save();
    $this->assertSame(403, $this->prefill('lukket', $session)['status']);
  }

}
