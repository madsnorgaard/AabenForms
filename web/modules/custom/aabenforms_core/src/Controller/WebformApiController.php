<?php

namespace Drupal\aabenforms_core\Controller;

use Drupal\aabenforms_core\Service\TraceStore;
use Drupal\aabenforms_core\Service\WorkflowExecutionCollector;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\webform\Entity\Webform;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Simple REST controller for webform access (bypasses JSON:API permissions).
 */
class WebformApiController extends ControllerBase {

  /**
   * The workflow execution collector.
   */
  protected WorkflowExecutionCollector $executionCollector;

  /**
   * The trace store.
   */
  protected TraceStore $traceStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->executionCollector = $container->get('aabenforms_core.workflow_execution_collector');
    $instance->traceStore = $container->get('aabenforms_core.trace_store');
    return $instance;
  }

  /**
   * Get webform by ID.
   *
   * Route: /api/webform/{id}
   *
   * @param string $id
   *   The webform machine name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with webform data.
   */
  public function getWebform(string $id): JsonResponse {
    $webform = Webform::load($id);

    if (!$webform) {
      return new JsonResponse(['error' => 'Webform not found'], 404);
    }

    if (!$webform->access('view')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $data = [
      'data' => [
        'id' => $webform->id(),
        'type' => 'webform',
        'attributes' => [
          'id' => $webform->id(),
          'title' => $webform->label(),
          'description' => $webform->get('description'),
          'elements' => $webform->getElementsDecodedAndFlattened(),
          'settings' => $webform->getSettings(),
          'requires_mitid' => $this->webformRequiresMitId($webform->id()),
        ],
      ],
    ];

    return new JsonResponse($data);
  }

  /**
   * Persists the execution trace, resolving the case opened from the submission.
   *
   * Never lets a tracing failure break the submission response - the trace is
   * evidence, not part of the transaction.
   *
   * @param int $sid
   *   The submission id.
   * @param string $webform_id
   *   The webform machine name.
   * @param array $execution
   *   The collector's toArray() result.
   */
  protected function persistTrace(int $sid, string $webform_id, array $execution): void {
    try {
      $case_id = NULL;
      $case_ids = $this->entityTypeManager()->getStorage('aabenforms_case')->getQuery()
        ->accessCheck(FALSE)
        ->condition('submission_ref', $sid)
        ->range(0, 1)
        ->execute();
      if ($case_ids) {
        $case_id = (int) reset($case_ids);
      }
      $this->traceStore->save($sid, $webform_id, $case_id, $execution);
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_core')->warning('Trace persist failed for submission @sid: @error', [
        '@sid' => $sid,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Whether any active ECA flow bound to this webform gates on MitID.
   *
   * The flow is the source of truth: a form requires MitID login exactly when
   * a flow listening to its submissions runs the aabenforms_mitid_validate
   * action. The SPA reads this flag and demands a MitID session before it
   * renders the form, so the identity gate is declared once (in the flow) and
   * enforced everywhere.
   *
   * @param string $webform_id
   *   The webform machine name.
   *
   * @return bool
   *   TRUE when a bound flow validates MitID.
   */
  /**
   * Replaces masked or empty CPR values with the CPR from the MitID session.
   *
   * The session endpoint only ever hands the SPA a masked CPR (250692-XXXX),
   * so a prefit CPR field submits the mask back. The authoritative CPR lives
   * server-side in the MitID session; swap it in at intake so downstream
   * actions (Digital Post, CPR lookup) get a real 10-digit CPR. Only elements
   * of a CPR type whose submitted value is empty or masked are touched - a
   * CPR the citizen typed themselves (e.g. their child's) is left alone.
   *
   * @param \Drupal\webform\Entity\Webform $webform
   *   The webform.
   * @param string $workflow_id
   *   The workflow id the MitID session is stored under.
   * @param array $submission_data
   *   The submitted values.
   *
   * @return array
   *   The submission data, possibly with the session CPR filled in.
   */
  protected function fillCprFromMitIdSession(Webform $webform, string $workflow_id, array $submission_data): array {
    if (!\Drupal::hasService('aabenforms_mitid.session_manager')) {
      return $submission_data;
    }
    $sm = \Drupal::service('aabenforms_mitid.session_manager');
    // Only a real, verified MitID session may seed a CPR into a submission. A
    // seeded demo session (demo_seeded) must not - defense in depth against a
    // fixated workflow_id becoming CPR takeover (see also the server-mint in
    // MitIdController::login()).
    $session = $sm->getSession($workflow_id);
    if (!$session || !empty($session['demo_seeded'])) {
      return $submission_data;
    }
    $cpr = $sm->getCprFromSession($workflow_id);
    if (!$cpr) {
      return $submission_data;
    }
    // Only the first CPR element belongs to the authenticated applicant; any
    // further CPR fields (a child's, a partner's) must never inherit it.
    foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
      if (!in_array($element['#type'] ?? '', ['cpr', 'cpr_field'], TRUE)) {
        continue;
      }
      $value = (string) ($submission_data[$key] ?? '');
      if ($value === '' || str_contains(strtoupper($value), 'X')) {
        $submission_data[$key] = $cpr;
      }
      break;
    }
    return $submission_data;
  }

  protected function webformRequiresMitId(string $webform_id): bool {
    try {
      $storage = $this->entityTypeManager()->getStorage('eca');
    }
    catch (\Exception) {
      return FALSE;
    }
    foreach ($storage->loadMultiple() as $eca) {
      if (!$eca->status()) {
        continue;
      }
      $bound = FALSE;
      foreach ($eca->get('events') ?? [] as $event) {
        $type = $event['configuration']['type'] ?? '';
        if ($type === 'webform_submission ' . $webform_id) {
          $bound = TRUE;
          break;
        }
      }
      if (!$bound) {
        continue;
      }
      foreach ($eca->get('actions') ?? [] as $action) {
        if (($action['plugin'] ?? '') === 'aabenforms_mitid_validate') {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Submit webform data.
   *
   * Route: /api/webform/{id}/submit.
   *
   * @param string $id
   *   The webform machine name.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with submission result and workflow execution data.
   */
  public function submitWebform(string $id, Request $request): JsonResponse {
    $webform = Webform::load($id);

    if (!$webform) {
      return new JsonResponse(['error' => 'Webform not found'], 404);
    }

    if (!$webform->access('submission_create')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // CSRF for a cross-origin SPA: reject a browser request whose Origin is not
    // the trusted frontend. A cookie-bound _csrf_token cannot be used here (the
    // SPA is on a different origin and has no session cookie). curl/server
    // clients send no Origin and fall through to the MitID + flood checks.
    $origin = $request->headers->get('Origin');
    if ($origin) {
      $allowed_origins = array_filter([$request->getSchemeAndHttpHost(), getenv('CORS_ALLOW_ORIGIN') ?: '']);
      if (!in_array($origin, $allowed_origins, TRUE)) {
        return new JsonResponse(['error' => 'Cross-origin request rejected'], 403);
      }
    }

    // Flood control: every accepted submission synchronously drives the full ECA
    // flow (CPR/CVR lookups, Digital Post), so cap bursts per client + form. A
    // rolling one-minute window blunts DoS / cost amplification while staying
    // generous for legitimate use and self-clearing (no hour-long lockouts).
    $flood = \Drupal::service('flood');
    $flood_id = $id . ':' . $request->getClientIp();
    if (!$flood->isAllowed('aabenforms_core.webform_submit', 30, 60, $flood_id)) {
      return new JsonResponse(['error' => 'Too many submissions, please try again later'], 429);
    }
    $flood->register('aabenforms_core.webform_submit', 60, $flood_id);

    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (!$data || !isset($data['data'])) {
      return new JsonResponse(['error' => 'Invalid submission data'], 400);
    }

    $submission_data = $data['data']['attributes']['data'] ?? $data['data'];

    // The SPA POSTs from a different origin than the backend cookie domain, so
    // the MitID session cookie is not shared. The unguessable workflow_id the
    // SPA holds from login travels in the payload instead; stash it on the
    // request so MitIdValidateAction can scope the identity gate to it when the
    // ECA workflow fires synchronously inside save() below.
    $workflow_id = $data['data']['attributes']['workflow_id']
      ?? $data['data']['workflow_id']
      ?? $data['workflow_id']
      ?? NULL;
    if (is_string($workflow_id) && $workflow_id !== '') {
      $request->attributes->set('aabenforms_workflow_id', $workflow_id);
      $submission_data = $this->fillCprFromMitIdSession($webform, $workflow_id, $submission_data);
    }

    // Server-side identity gate. If a flow behind this form validates MitID, a
    // submission without a valid MitID session is rejected here - the
    // requires_mitid flag is otherwise only advisory to the SPA, and demo-mode
    // gates must not be a bypass. This also turns the ECA-internal deny into an
    // early, cheap rejection (no synchronous external calls for an unauth POST).
    if ($this->webformRequiresMitId($id)) {
      $sm = \Drupal::hasService('aabenforms_mitid.session_manager')
        ? \Drupal::service('aabenforms_mitid.session_manager')
        : NULL;
      if (!is_string($workflow_id) || $workflow_id === '' || !$sm || !$sm->hasValidSession($workflow_id)) {
        return new JsonResponse(['error' => 'MitID authentication required'], 401);
      }
    }

    $values = [
      'webform_id' => $id,
      'entity_type' => NULL,
      'entity_id' => NULL,
      'in_draft' => FALSE,
      'uid' => $this->currentUser()->id(),
      'langcode' => $this->languageManager()->getCurrentLanguage()->getId(),
      'token' => Crypt::randomBytesBase64(),
      'uri' => $request->getRequestUri(),
      'remote_addr' => $request->getClientIp(),
      'data' => $submission_data,
    ];

    try {
      $submission = $this->entityTypeManager()
        ->getStorage('webform_submission')
        ->create($values);

      // ECA workflows fire synchronously during save().
      // The WorkflowExecutionCollector captures each step.
      $submission->save();

      $this->getLogger('aabenforms_core')->notice('Webform submission created: @sid for webform @webform', [
        '@sid' => $submission->id(),
        '@webform' => $id,
      ]);

      $response_data = [
        'data' => [
          'id' => $submission->id(),
          'type' => 'webform_submission',
          'attributes' => [
            'sid' => $submission->id(),
            'created' => $submission->getCreatedTime(),
            'completed' => $submission->getCompletedTime(),
          ],
        ],
      ];

      // Append workflow execution data if any steps were collected, and
      // persist the trace so the submission can be traced after the fact.
      if ($this->executionCollector->hasSteps()) {
        $execution = $this->executionCollector->toArray();
        $response_data['workflow'] = $execution;
        $this->persistTrace((int) $submission->id(), $id, $execution);
      }

      return new JsonResponse($response_data, 201);
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_core')->error('Webform submission failed: @error', [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      return new JsonResponse([
        'error' => 'Submission failed',
        'message' => 'An error occurred while processing your submission. Please try again.',
      ], 500);
    }
  }

}
