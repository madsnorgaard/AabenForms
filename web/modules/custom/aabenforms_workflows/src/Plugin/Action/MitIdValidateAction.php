<?php

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ECA Action: Validate MitID session.
 */
#[Action(
  id: 'aabenforms_mitid_validate',
  label: new TranslatableMarkup('Validate MitID Session'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Validates that a MitID authentication session is active and valid.'),
  version_introduced: '2.0.0',
)]
class MitIdValidateAction extends AabenFormsActionBase {

  /**
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $sessionManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Browser-session key under which MitID login binds the workflow id.
   *
   * Set by MitIdController::callback() after a successful MitID/NemLog-in
   * authentication. It is the per-browser, unguessable handle to the stored
   * session and is the bridge between login and the flow gate.
   */
  protected const SESSION_WORKFLOW_KEY = 'mitid_workflow_id';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->sessionManager = $container->get('aabenforms_mitid.session_manager');
    $instance->configFactory = $container->get('config.factory');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * Request attribute the webform controller stashes the submitted id under.
   *
   * @see \Drupal\aabenforms_core\Controller\WebformApiController::submitWebform()
   */
  protected const REQUEST_WORKFLOW_ATTR = 'aabenforms_workflow_id';

  /**
   * Resolves the workflow id that scopes the MitID session to validate.
   *
   * `workflow_id` is the bearer capability minted at MitID login (an
   * unguessable `wf_<hex>` the session is stored under); whoever holds it can
   * read that session for 15 minutes. The SPA that drove the login holds it,
   * so the gate accepts it back from the submission. Resolution order:
   *
   * 1. The configured ECA token (`workflow_id_token`) - a flow or upstream
   *    action may have populated it explicitly.
   * 2. The id the submission carried, stashed on the request by the webform
   *    controller (or passed as a `workflow_id` query param). This is the
   *    cross-origin path: the SPA POSTs from a different host than the backend
   *    cookie domain, so a shared session cookie is NOT available - the id
   *    travels in the request payload instead.
   * 3. The browser session's `mitid_workflow_id`, bound at login by
   *    MitIdController. This is the same-origin path: the modeler test harness
   *    (and any same-origin submission) carries the backend cookie.
   *
   * Fail-safe in every case: each source only yields a handle the caller
   * already legitimately holds, and an empty result routes to the deny
   * terminal. It can never manufacture identity.
   *
   * @return string
   *   The resolved workflow id, or '' when none is available.
   */
  protected function resolveWorkflowId(): string {
    $tokenName = $this->configuration['workflow_id_token'] ?? 'workflow_id';
    $workflowId = $this->getTokenValue($tokenName, '');
    if (!empty($workflowId)) {
      return $workflowId;
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      // Cross-origin SPA: the id rides in the request payload.
      $fromRequest = $request->attributes->get(self::REQUEST_WORKFLOW_ATTR)
        ?? $request->query->get('workflow_id');
      if (is_string($fromRequest) && $fromRequest !== '') {
        $this->log('MitID validation: workflow id taken from submission payload', [], 'info');
        return $fromRequest;
      }

      // Same-origin: the login-bound browser session. Avoid forcing a session
      // to start when none exists (e.g. CLI/cron), which would be a no-op.
      $session = $request->getSession();
      if ($session && $session->isStarted()) {
        $bound = $session->get(self::SESSION_WORKFLOW_KEY);
        if (is_string($bound) && $bound !== '') {
          $this->log('MitID validation: workflow id taken from browser session (login-bound)', [], 'info');
          return $bound;
        }
      }
    }

    return '';
  }

  /**
   * Whether MitID demo mode (pass with no real session) is permitted.
   *
   * Default is FALSE: without a verified session the action fails CLOSED so
   * the audit trail never records an unverified identity as "verified".
   */
  protected function demoModeAllowed(): bool {
    return (bool) ($this->configFactory->get('aabenforms_workflows.settings')->get('allow_mitid_demo_mode') ?? FALSE);
  }

  /**
   * Records the "no valid MitID session" outcome, fail-closed by default.
   *
   * @param string $context
   *   Short reason for logging.
   */
  protected function recordNoSession(string $context): void {
    if ($this->demoModeAllowed()) {
      // Demo mode lets a workflow run without a real session, but it must NEVER
      // claim a verified identity: the status scalar the gates read stays
      // non-verified ('demo_unverified'), and the legacy boolean is FALSE, so no
      // gate comparing == 'verified' can be fooled into treating an unverified
      // demo submission as authenticated. The audit trail records the truth.
      $this->log('MitID validation: ' . $context . ' - demo mode (allow_mitid_demo_mode on), identity NOT verified', [], 'info');
      $this->setTokenValue($this->configuration['result_token'], FALSE);
      $this->setResultStatus('demo_unverified');
      $this->recordStep('MitID Identity Validation', 'Demo mode: identity was NOT verified (no MitID session)', 'completed');
      return;
    }
    $this->log('MitID validation failed: ' . $context . ' (demo mode off)', [], 'warning');
    $this->setTokenValue($this->configuration['result_token'], FALSE);
    $this->setResultStatus('failed');
    $this->recordStep('MitID Identity Validation', 'No valid MitID session - identity could not be verified', 'failed');
  }

  /**
   * Writes an explicit scalar status companion token for downstream gating.
   *
   * The boolean result_token is kept for backward compatibility, but ECA
   * conditions and the audit action read this `<result_token>_status` scalar
   * (`verified` or `failed`) because comparing a rendered boolean token is
   * unreliable.
   *
   * @param string $status
   *   Either 'verified' or 'failed'.
   */
  protected function setResultStatus(string $status): void {
    $resultToken = (string) ($this->configuration['result_token'] ?? '');
    if ($resultToken !== '') {
      $this->setTokenValue($resultToken . '_status', $status);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'workflow_id_token' => 'workflow_id',
      'result_token' => 'mitid_valid',
      'session_data_token' => 'mitid_session',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['workflow_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow ID token'),
      '#description' => $this->t('Token containing the workflow instance ID.'),
      '#default_value' => $this->configuration['workflow_id_token'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Token to store validation result (TRUE/FALSE).'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];

    $form['session_data_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Session data token name'),
      '#description' => $this->t('Token to store session data if valid.'),
      '#default_value' => $this->configuration['session_data_token'],
      '#required' => TRUE,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['workflow_id_token'] = $form_state->getValue('workflow_id_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    $this->configuration['session_data_token'] = $form_state->getValue('session_data_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $workflowId = $this->resolveWorkflowId();

    if (empty($workflowId)) {
      $this->recordNoSession('no workflow id in context');
      return;
    }

    try {
      // Get session data.
      $sessionData = $this->sessionManager->getSession($workflowId);

      if (empty($sessionData)) {
        $this->recordNoSession('no session for workflow ' . $workflowId);
        return;
      }

      // Check if session has expired.
      $expiresAt = $sessionData['expires_at'] ?? 0;
      if ($expiresAt < time()) {
        $this->log('MitID validation failed: Session expired for workflow {workflow_id}', [
          'workflow_id' => $workflowId,
        ], 'warning');
        $this->setTokenValue($this->configuration['result_token'], FALSE);
        $this->setResultStatus('failed');
        $this->recordStep('MitID Identity Validation', 'Session expired - re-authentication required', 'failed');
        return;
      }

      // Session is valid.
      $this->log('MitID session validated successfully for workflow {workflow_id}', [
        'workflow_id' => $workflowId,
      ]);

      $this->setTokenValue($this->configuration['result_token'], TRUE);
      $this->setResultStatus('verified');
      $this->setTokenValue($this->configuration['session_data_token'], $sessionData);
      $this->recordStep('MitID Identity Validation', 'Citizen identity verified via NemID/MitID national eID');

    }
    catch (\Exception $e) {
      $this->handleError($e, 'MitID Identity Validation');
      $this->setTokenValue($this->configuration['result_token'], FALSE);
      $this->setResultStatus('failed');
    }
  }

}
