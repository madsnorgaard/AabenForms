<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Controller;

use Drupal\aabenforms_core\Employment\EmploymentLookupInterface;
use Drupal\aabenforms_core\Enrolment\PupilEnrolmentLookupInterface;
use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Http\SessionBearer;
use Drupal\aabenforms_core\Prefill\LegalBasis;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prefill with provenance: GET /api/prefill/{webform_id}.
 *
 * The MitID session travels as "Authorization: Bearer <workflow_id>",
 * never in the URL.
 *
 * Runs, for a verified MitID session, the register lookups the bound flow
 * would run for the form and returns each value together with where it came
 * from (register + contract + honest mode), the legal basis the municipality
 * relies on, when it was fetched, and the id of the audit row the lookup
 * wrote. The SPA renders this as "we already know X, from Y, because Z" so
 * the citizen is not asked for what the authority already holds (digital
 * inklusion, forvaltningsloven §7 vejledningspligt) and can see the data
 * trail.
 *
 * Response shape:
 * @code
 * {
 *   "webform": "merudgifter",
 *   "fields": {
 *     "<element_key>": {
 *       "value": <scalar|null>,
 *       "options": [...],            // only for list sources (children, cases)
 *       "source": {"register": "...", "contract": "...", "mode": "live|live-capable|demo", "label": "..."},
 *       "legal_basis": "...",
 *       "fetched_at": "2026-10-10T12:00:00+02:00",
 *       "audit_id": 123
 *     }
 *   },
 *   "not_asked": ["<element keys the citizen still has to answer>"],
 *   "session": {"name": "...", "assurance_level": "...", "digital_post_exempt": false, "seeded": false}
 * }
 * @endcode
 *
 * The CPR is only ever returned masked (DDMMYY-XXXX), via the single masking
 * rule in MitIdSessionManager. household_income, employer_name and
 * employment_history are virtual keys (no form element carries them); every
 * other key is an element of the form.
 *
 * Trust model: the workflow id is the bearer capability the citizen got at
 * login (unguessable, 15 min). Demo-seeded sessions are honoured only on
 * DDEV, so a seeded identity can never prefill on a real host. Rate limited
 * per client + form like the submit endpoint, because every call fans out to
 * registers.
 */
class PrefillController extends ControllerBase {

  /**
   * Flood event name and limits (same envelope as webform submit).
   */
  protected const FLOOD_EVENT = 'aabenforms_core.prefill';
  protected const FLOOD_LIMIT = 30;
  protected const FLOOD_WINDOW = 60;

  /**
   * Element keys that map onto a session identity attribute.
   *
   * Keys are webform element keys, values are MitIdSessionManager public
   * attribute names (or address.<part>). CPR elements are not listed: they
   * are detected by CprAccess::isCprElement() so the rule stays single.
   */
  protected const IDENTITY_KEYS = [
    'name' => 'name',
    'full_name' => 'name',
    'applicant_name' => 'name',
    'navn' => 'name',
    'fulde_navn' => 'name',
    'given_name' => 'given_name',
    'first_name' => 'given_name',
    'fornavn' => 'given_name',
    'family_name' => 'family_name',
    'last_name' => 'family_name',
    'efternavn' => 'family_name',
    'email' => 'email',
    'applicant_email' => 'email',
    'contact_email' => 'email',
    'e_mail' => 'email',
    'birthdate' => 'birthdate',
    'foedselsdato' => 'birthdate',
    'address' => 'address.street',
    'applicant_address' => 'address.street',
    'adresse' => 'address.street',
    'street' => 'address.street',
    'vejnavn' => 'address.street',
    'postal_code' => 'address.postal_code',
    'postnr' => 'address.postal_code',
    'postnummer' => 'address.postal_code',
    'city' => 'address.city',
    'by' => 'address.city',
    'bynavn' => 'address.city',
    'municipality_code' => 'address.municipality_code',
    'kommunekode' => 'address.municipality_code',
  ];

  /**
   * Element types that are never answered by the citizen.
   */
  protected const NON_INPUT_TYPES = [
    'hidden', 'value', 'markup', 'webform_markup', 'webform_computed_twig',
    'webform_computed_token', 'processed_text', 'container', 'fieldset',
    'details', 'webform_wizard_page', 'webform_flexbox', 'webform_section',
    'item', 'label', 'webform_actions', 'webform_message', 'webform_more',
    'webform_horizontal_rule', 'webform_table', 'webform_table_row',
  ];

  /**
   * Webforms whose bound flow looks up household income.
   *
   * Not merudgifter: the servicelov §41 merudgiftsydelse for a child does not
   * depend on the parents' income, so fetching it would breach data
   * minimisation (DBF art. 5(1)(c)).
   */
  protected const INCOME_WEBFORMS = ['friplads'];

  /**
   * Webforms whose bound flow checks employment (beskæftigelseskrav).
   */
  protected const EMPLOYMENT_WEBFORMS = ['sygedagpenge'];

  /**
   * Webforms whose bound flow resolves one of the citizen's cases.
   */
  protected const CASE_WEBFORMS = ['klage'];

  /**
   * Webforms that ask for the child's current school (STIL elevdata).
   */
  protected const ENROLMENT_WEBFORMS = ['school_transfer'];

  /**
   * Element key for the child's current school.
   */
  protected const SCHOOL_KEY = 'current_school';

  /**
   * Virtual key for household income (no form element carries it).
   */
  protected const INCOME_KEY = 'household_income';

  /**
   * The audit logger (one row per register lookup).
   */
  protected AuditLogger $auditLogger;

  /**
   * The CPR element authority (which element is the applicant CPR).
   */
  protected CprAccess $cprAccess;

  /**
   * The family / custody lookup (SF6006 or demo).
   */
  protected FamilyRelationsLookupInterface $familyLookup;

  /**
   * The pupil enrolment lookup (STIL elevdata; demo rail today).
   */
  protected PupilEnrolmentLookupInterface $enrolmentLookup;

  /**
   * The employment + employer sickness report lookup (demo rail today).
   */
  protected EmploymentLookupInterface $employmentLookup;

  /**
   * The flood service for rate limiting.
   */
  protected FloodInterface $flood;

  /**
   * The time service (fetched_at stamps).
   */
  protected TimeInterface $time;

  /**
   * The MitID session manager, when aabenforms_mitid is installed.
   *
   * Typed loosely: core must not depend on the MitID module's classes.
   */
  protected ?object $sessionManager = NULL;

  /**
   * The case-by-CPR lookup, when aabenforms_case is installed.
   */
  protected ?object $caseLookup = NULL;

  /**
   * The income lookup, when aabenforms_case is installed.
   */
  protected ?object $incomeLookup = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->auditLogger = $container->get('aabenforms_core.audit_logger');
    $instance->cprAccess = $container->get('aabenforms_core.cpr_access');
    $instance->familyLookup = $container->get('aabenforms_core.family_lookup');
    $instance->enrolmentLookup = $container->get('aabenforms_core.pupil_enrolment');
    $instance->employmentLookup = $container->get('aabenforms_core.employment_lookup');
    $instance->flood = $container->get('flood');
    $instance->time = $container->get('datetime.time');
    foreach ([
      'sessionManager' => 'aabenforms_mitid.session_manager',
      'caseLookup' => 'aabenforms_case.case_lookup',
      'incomeLookup' => 'aabenforms_case.income_lookup',
    ] as $property => $service) {
      if ($container->has($service)) {
        $instance->{$property} = $container->get($service);
      }
    }
    return $instance;
  }

  /**
   * Prefills a webform from the registers for a MitID session.
   *
   * Route: /api/prefill/{webform_id}, "Authorization: Bearer <workflow_id>".
   *
   * @param string $webform_id
   *   The webform machine name.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The prefill payload, or an error.
   */
  public function prefill(string $webform_id, Request $request): JsonResponse {
    $webform = Webform::load($webform_id);
    if (!$webform instanceof WebformInterface) {
      return $this->error('Webform not found', 404);
    }
    if (!$webform->isOpen() || !$webform->access('submission_create')) {
      return $this->error('Webform is not open', 403);
    }

    // Flood control: each call fans out to registers, so cap bursts per
    // client + form with the same rolling envelope as the submit endpoint.
    $flood_id = $webform_id . ':' . $request->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $flood_id)) {
      return $this->error('Too many requests, please try again later', 429);
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $flood_id);

    if ($this->sessionManager === NULL) {
      return $this->error('MitID is not available on this site', 501);
    }
    $workflow_id = SessionBearer::fromRequest($request);
    if ($workflow_id === '') {
      return $this->error('MitID session required', 401);
    }
    $session = $this->sessionManager->getSession($workflow_id);
    if (!is_array($session)) {
      // Audit the miss: probing session ids is what an attacker would do.
      $this->auditLogger->logWorkflowAccess($workflow_id, 'prefill_session_read', 'not_found', ['webform' => $webform_id]);
      return $this->error('Session not found or expired', 401);
    }
    // A seeded demo identity may only drive prefill on a DDEV host. Elsewhere
    // the capability is refused even though the session exists, so demo
    // scaffolding can never become a prefill-by-fixated-id on a real site.
    if (!empty($session['demo_seeded']) && !getenv('DDEV_HOSTNAME')) {
      $this->auditLogger->logWorkflowAccess($workflow_id, 'prefill_demo_session_refused', 'failure', ['webform' => $webform_id]);
      return $this->error('Demo sessions are not accepted outside the local environment', 403);
    }

    $public = $this->sessionManager->getPublicAttributes($workflow_id) ?? [];
    $cpr = preg_replace('/\D/', '', (string) ($session['cpr'] ?? '')) ?? '';
    $elements = $webform->getElementsDecodedAndFlattened();
    $legal_basis = LegalBasis::for($webform_id);
    $fetched_at = $this->fetchedAt();
    $fields = [];

    $this->addIdentity($fields, $elements, $public, $cpr, $workflow_id, $webform_id, $legal_basis, $fetched_at);
    $this->addFamily($fields, $elements, $cpr, $workflow_id, $webform_id, $legal_basis, $fetched_at);
    $this->addIncome($fields, $webform_id, $cpr, $workflow_id, $legal_basis, $fetched_at);
    $this->addCases($fields, $elements, $webform_id, $cpr, $workflow_id, $legal_basis, $fetched_at);
    $this->addEmployment($fields, $elements, $webform_id, $cpr, $workflow_id, $legal_basis, $fetched_at);

    $not_asked = [];
    foreach ($elements as $key => $element) {
      if (isset($fields[$key]) || in_array($element['#type'] ?? '', self::NON_INPUT_TYPES, TRUE)) {
        continue;
      }
      $not_asked[] = $key;
    }

    $response = new JsonResponse([
      'webform' => $webform_id,
      'fields' => $fields,
      'not_asked' => $not_asked,
      'session' => [
        'name' => $public['name'] ?? '',
        'assurance_level' => $public['assurance_level'] ?? '',
        'digital_post_exempt' => (bool) ($public['digital_post_exempt'] ?? FALSE),
        'seeded' => (bool) ($public['demo_seeded'] ?? FALSE),
      ],
    ]);
    // Personal data keyed by a bearer capability: never let a shared cache
    // (Drupal page cache, a proxy) hold it.
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Identity source: name, email, address and masked CPR from the session.
   */
  protected function addIdentity(array &$fields, array $elements, array $public, string $cpr, string $workflow_id, string $webform_id, string $legal_basis, string $fetched_at): void {
    $values = [];
    $cprDone = FALSE;
    foreach ($elements as $key => $element) {
      // Only the first CPR element is the applicant; later ones (child_cpr,
      // parent2_cpr) belong to other people and are never the session CPR.
      if (!$cprDone && $this->cprAccess->isCprElement($key, $element)) {
        $cprDone = TRUE;
        if (($public['cpr'] ?? '') !== '') {
          $values[$key] = $public['cpr'];
        }
        continue;
      }
      $attribute = self::IDENTITY_KEYS[$key] ?? NULL;
      if ($attribute === NULL) {
        continue;
      }
      $value = str_starts_with($attribute, 'address.')
        ? ($public['address'][substr($attribute, 8)] ?? NULL)
        : ($public[$attribute] ?? NULL);
      if (is_string($value) && $value !== '') {
        $values[$key] = $value;
      }
    }
    if ($values === []) {
      return;
    }
    $audit_id = $this->auditLogger->log('prefill_identity', $cpr !== '' ? $cpr : $workflow_id, $webform_id, 'success', [
      'workflow_id' => $workflow_id,
      'keys' => array_keys($values),
      'mode' => 'live-capable',
    ]);
    $source = [
      'register' => 'MitID',
      'contract' => 'OIDC (MitID / NemLog-in)',
      'mode' => 'live-capable',
      'label' => (string) $this->t('From your MitID login'),
    ];
    foreach ($values as $key => $value) {
      $fields[$key] = $this->entry($value, $source, $legal_basis, $fetched_at, $audit_id);
    }
  }

  /**
   * Family source: custody children, and derived custody for one of them.
   */
  protected function addFamily(array &$fields, array $elements, string $cpr, string $workflow_id, string $webform_id, string $legal_basis, string $fetched_at): void {
    $asksChild = isset($elements['child_cpr']) || isset($elements['child_name']);
    if (!$asksChild || $cpr === '') {
      return;
    }
    $mode = $this->familyLookup->isDemoMode() ? 'demo' : 'live';
    try {
      $children = $this->familyLookup->childrenOf($cpr);
      $status = 'success';
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_core')->error('Prefill family lookup failed: {message}', ['message' => $e->getMessage()]);
      $children = [];
      $status = 'failure';
    }
    $audit_id = $this->auditLogger->log('prefill_children', $cpr, $webform_id, $status, [
      'workflow_id' => $workflow_id,
      'count' => count($children),
      'mode' => $mode,
    ]);
    if ($status !== 'success') {
      return;
    }
    $source = [
      'register' => 'CPR (Familie+)',
      'contract' => 'SF6006',
      'mode' => $mode,
      'label' => (string) $this->t('From the CPR register'),
    ];

    $options = [];
    foreach ($children as $child) {
      $guardians = $child['guardians'] ?? [];
      $other = NULL;
      foreach ($guardians as $guardian) {
        if (($guardian['cpr'] ?? '') !== $cpr) {
          $other = $guardian;
          break;
        }
      }
      $options[] = [
        'name' => (string) ($child['full_name'] ?? ''),
        'cpr' => $this->sessionManager::maskCpr((string) ($child['cpr'] ?? '')),
        'birth_date' => (string) ($child['birth_date'] ?? ''),
        'custody' => count($guardians) > 1 ? 'joint' : 'sole',
        'other_guardian' => $other === NULL ? NULL : [
          'name' => (string) ($other['full_name'] ?? ''),
          'cpr' => $this->sessionManager::maskCpr((string) ($other['cpr'] ?? '')),
          'same_address' => (bool) ($other['same_address'] ?? FALSE),
        ],
      ];
    }
    $single = count($options) === 1 ? $options[0] : NULL;

    // Enrolment source (school_transfer): the child's current school is
    // known from STIL pupil data, so it is fetched, not asked.
    $schools = [];
    if (isset($elements[self::SCHOOL_KEY]) && in_array($webform_id, self::ENROLMENT_WEBFORMS, TRUE)) {
      $schools = $this->addEnrolment($fields, $children, $options, $cpr, $workflow_id, $webform_id, $legal_basis, $fetched_at);
      // Carry the school on each child option too (index-aligned, as twins
      // can share a masked CPR), so the child choice can show it directly.
      foreach ($options as $i => $option) {
        if (isset($schools[$i])) {
          $options[$i][self::SCHOOL_KEY] = $schools[$i];
        }
      }
    }

    if (isset($elements['child_name'])) {
      $fields['child_name'] = $this->entry($single['name'] ?? NULL, $source, $legal_basis, $fetched_at, $audit_id, $options);
    }
    if (isset($elements['child_cpr'])) {
      $fields['child_cpr'] = $this->entry($single['cpr'] ?? NULL, $source, $legal_basis, $fetched_at, $audit_id, $options);
    }

    // Custody source (school_transfer): derive custody_status from the
    // registry instead of asking. Only sole vs joint is knowable here; whether
    // the other guardian agrees (joint_agreed/joint_disagreed) is the
    // co-signature's job, so the value is 'sole' or 'joint'.
    if (isset($elements['custody_status']) && isset($elements['child_cpr'])) {
      $custody_audit = $this->auditLogger->log('prefill_custody', $cpr, $webform_id, 'success', [
        'workflow_id' => $workflow_id,
        'children' => count($options),
        'mode' => $mode,
      ]);
      $custody_options = array_map(static fn (array $o, int $i): array => [
        'child_cpr' => $o['cpr'],
        'custody_status' => $o['custody'],
        'parent2_cpr' => $o['other_guardian']['cpr'] ?? NULL,
        'parent2_name' => $o['other_guardian']['name'] ?? NULL,
      ] + ($schools === [] ? [] : [self::SCHOOL_KEY => $schools[$i] ?? NULL]), $options, array_keys($options));
      $fields['custody_status'] = $this->entry($single['custody'] ?? NULL, $source, $legal_basis, $fetched_at, $custody_audit, $custody_options);
      if (isset($elements['parent2_cpr'])) {
        $fields['parent2_cpr'] = $this->entry($single['other_guardian']['cpr'] ?? NULL, $source, $legal_basis, $fetched_at, $custody_audit, $custody_options);
      }
    }
  }

  /**
   * Enrolment source: each custody child's current school (STIL elevdata).
   *
   * Writes one audit row for the lookup (hashed applicant CPR, masked child
   * CPRs only) and sets the current_school field: the institution number as
   * value when exactly one child is known, otherwise NULL with per-child
   * options. $children are the raw custody children (plaintext CPR, never
   * returned); $options are the masked child options, index-aligned.
   *
   * @return array
   *   The current school ({institution_number, name} or NULL) per child,
   *   index-aligned with $options (masked CPRs can collide for twins), or
   *   an empty array when the lookup failed.
   */
  protected function addEnrolment(array &$fields, array $children, array $options, string $cpr, string $workflow_id, string $webform_id, string $legal_basis, string $fetched_at): array {
    $mode = $this->enrolmentLookup->isDemoMode() ? 'demo' : 'live';
    $schools = [];
    $status = 'success';
    foreach (array_values($children) as $i => $child) {
      try {
        $enrolment = $this->enrolmentLookup->currentInstitution((string) ($child['cpr'] ?? ''));
      }
      catch (\Exception $e) {
        $this->getLogger('aabenforms_core')->error('Prefill enrolment lookup failed: {message}', ['message' => $e->getMessage()]);
        $enrolment = NULL;
        $status = 'failure';
      }
      $schools[$i] = $enrolment === NULL ? NULL : [
        'institution_number' => (string) $enrolment['institution_number'],
        'name' => (string) $enrolment['name'],
      ];
    }
    $audit_id = $this->auditLogger->log('prefill_enrolment', $cpr, $webform_id, $status, [
      'workflow_id' => $workflow_id,
      'children' => array_column($options, 'cpr'),
      'found' => count(array_filter($schools)),
      'mode' => $mode,
    ]);
    if ($status !== 'success') {
      return [];
    }
    $school_options = [];
    foreach ($schools as $i => $school) {
      $school_options[] = ['child_cpr' => $options[$i]['cpr'], self::SCHOOL_KEY => $school];
    }
    $value = count($schools) === 1 ? (reset($schools)['institution_number'] ?? NULL) : NULL;
    $fields[self::SCHOOL_KEY] = $this->entry($value, [
      'register' => 'STIL elevdata',
      'contract' => 'STIL (Styrelsen for IT og Læring) - integration ikke etableret',
      'mode' => $mode,
      'label' => (string) $this->t('From pupil data at STIL (Styrelsen for IT og Læring)'),
    ], $legal_basis, $fetched_at, $audit_id, $school_options);
    return $schools;
  }

  /**
   * Income source: household income on the eIndkomst rail (demo today).
   */
  protected function addIncome(array &$fields, string $webform_id, string $cpr, string $workflow_id, string $legal_basis, string $fetched_at): void {
    if ($this->incomeLookup === NULL || $cpr === '' || !in_array($webform_id, self::INCOME_WEBFORMS, TRUE)) {
      return;
    }
    $income = $this->incomeLookup->lookup($cpr);
    if ($income === NULL) {
      return;
    }
    $mode = $this->incomeLookup->isDemoMode() ? 'demo' : 'live';
    $audit_id = $this->auditLogger->log('prefill_income', $cpr, $webform_id, 'success', [
      'workflow_id' => $workflow_id,
      'mode' => $mode,
    ]);
    $fields[self::INCOME_KEY] = $this->entry($income, [
      'register' => 'eIndkomst',
      'contract' => 'eIndkomst (Skatteforvaltningen)',
      'mode' => $mode,
      'label' => (string) $this->t('From the income register (eIndkomst)'),
    ], $legal_basis, $fetched_at, $audit_id);
  }

  /**
   * Cases source: the citizen's own cases, offered for case_id (klage).
   */
  protected function addCases(array &$fields, array $elements, string $webform_id, string $cpr, string $workflow_id, string $legal_basis, string $fetched_at): void {
    if ($this->caseLookup === NULL || $cpr === '' || !isset($elements['case_id']) || !in_array($webform_id, self::CASE_WEBFORMS, TRUE)) {
      return;
    }
    $cases = $this->caseLookup->findByCpr($cpr);
    $audit_id = $this->auditLogger->log('prefill_cases', $cpr, $webform_id, 'success', [
      'workflow_id' => $workflow_id,
      'count' => count($cases),
      'mode' => 'live',
    ]);
    $appealable = array_values(array_filter($cases, static fn (array $c): bool => !empty($c['appealable'])));
    $fields['case_id'] = $this->entry(
      count($appealable) === 1 ? $appealable[0]['case_id'] : NULL,
      [
        'register' => 'AabenForms sagsregister',
        'contract' => 'aabenforms_case (SF1470 planned)',
        'mode' => 'live',
        'label' => (string) $this->t('From your cases with the municipality'),
      ],
      $legal_basis,
      $fetched_at,
      $audit_id,
      $cases
    );
  }

  /**
   * Employment source: employer + beskæftigelseskrav, and the sick report.
   *
   * Two lookups, two audit rows: eIndkomst (employer, hours in the reference
   * period) and NemRefusion (the employer's report of the first sick day).
   * Pay is deliberately not returned: the kommune needs it to calculate the
   * benefit, the citizen's browser does not (data minimisation).
   */
  protected function addEmployment(array &$fields, array $elements, string $webform_id, string $cpr, string $workflow_id, string $legal_basis, string $fetched_at): void {
    if ($cpr === '' || !in_array($webform_id, self::EMPLOYMENT_WEBFORMS, TRUE)) {
      return;
    }
    $mode = $this->employmentLookup->isDemoMode() ? 'demo' : 'live';

    $employment = $this->lookupSafely(fn () => $this->employmentLookup->employment($cpr), $status);
    $audit_id = $this->auditLogger->log('prefill_employment', $cpr, $webform_id, $status, [
      'workflow_id' => $workflow_id,
      'found' => $employment !== NULL,
      'mode' => $mode,
    ]);
    if ($employment !== NULL) {
      $source = [
        'register' => 'eIndkomst',
        'contract' => 'eIndkomst (Skatteforvaltningen) - integration ikke etableret',
        'mode' => $mode,
        'label' => (string) $this->t('From the income register (eIndkomst)'),
      ];
      $fields['employer_name'] = $this->entry($employment['employer_name'], $source, $legal_basis, $fetched_at, $audit_id);
      if (isset($elements['employer_cvr'])) {
        $fields['employer_cvr'] = $this->entry($employment['employer_cvr'], $source, $legal_basis, $fetched_at, $audit_id);
      }
      $fields['employment_history'] = $this->entry([
        'total_hours' => $employment['total_hours'],
        'required_hours' => EmploymentLookupInterface::REQUIRED_HOURS,
        'reference_months' => EmploymentLookupInterface::REFERENCE_MONTHS,
        'requirement_met' => $employment['requirement_met'],
        'months' => $employment['months'],
      ], $source, $legal_basis, $fetched_at, $audit_id);
    }

    $report = $this->lookupSafely(fn () => $this->employmentLookup->sicknessReport($cpr), $status);
    $report_audit = $this->auditLogger->log('prefill_sickness_report', $cpr, $webform_id, $status, [
      'workflow_id' => $workflow_id,
      'found' => $report !== NULL,
      'mode' => $mode,
    ]);
    if ($report !== NULL && isset($elements['first_sick_day'])) {
      $fields['first_sick_day'] = $this->entry($report['first_sick_day'], [
        'register' => 'NemRefusion',
        'contract' => 'NemRefusion (Udbetaling Danmark) - integration ikke etableret',
        'mode' => $mode,
        'label' => (string) $this->t('From your employer\'s sickness report (NemRefusion)'),
      ], $legal_basis, $fetched_at, $report_audit) + [
        'employer_period_end' => $report['employer_period_end'],
      ];
    }
  }

  /**
   * Runs a register lookup, turning an exception into a failure status.
   */
  protected function lookupSafely(callable $lookup, ?string &$status): ?array {
    try {
      $status = 'success';
      return $lookup();
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_core')->error('Prefill lookup failed: {message}', ['message' => $e->getMessage()]);
      $status = 'failure';
      return NULL;
    }
  }

  /**
   * Builds one field entry.
   */
  protected function entry(mixed $value, array $source, string $legal_basis, string $fetched_at, ?int $audit_id, ?array $options = NULL): array {
    $entry = [
      'value' => $value,
      'source' => $source,
      'legal_basis' => $legal_basis,
      'fetched_at' => $fetched_at,
      'audit_id' => $audit_id,
    ];
    if ($options !== NULL) {
      $entry['options'] = $options;
    }
    return $entry;
  }

  /**
   * The request time as a Copenhagen-local ISO 8601 timestamp.
   */
  protected function fetchedAt(): string {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format(\DateTimeInterface::ATOM);
  }

  /**
   * Builds a JSON error response that is never cached.
   */
  protected function error(string $message, int $status): JsonResponse {
    $response = new JsonResponse(['error' => $message], $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
