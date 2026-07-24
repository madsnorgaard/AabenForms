<?php

namespace Drupal\aabenforms_mitid\Controller;

use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_mitid\Service\MitIdOidcClient;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for MitID OIDC authentication endpoints.
 */
class MitIdController extends ControllerBase {

  /**
   * The MitID OIDC client.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdOidcClient
   */
  protected MitIdOidcClient $oidcClient;

  /**
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $sessionManager;

  /**
   * The audit logger.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger
   */
  protected AuditLogger $auditLogger;

  /**
   * Constructs a MitIdController.
   *
   * @param \Drupal\aabenforms_mitid\Service\MitIdOidcClient $oidc_client
   *   The OIDC client service.
   * @param \Drupal\aabenforms_mitid\Service\MitIdSessionManager $session_manager
   *   The MitID session manager.
   * @param \Drupal\aabenforms_core\Service\AuditLogger $audit_logger
   *   The audit logger.
   */
  public function __construct(MitIdOidcClient $oidc_client, MitIdSessionManager $session_manager, AuditLogger $audit_logger) {
    $this->oidcClient = $oidc_client;
    $this->sessionManager = $session_manager;
    $this->auditLogger = $audit_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('aabenforms_mitid.oidc_client'),
      $container->get('aabenforms_mitid.session_manager'),
      $container->get('aabenforms_core.audit_logger')
    );
  }

  /**
   * Initiates MitID login flow.
   *
   * Route: /mitid/login.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   Redirect to MitID authorization.
   */
  public function login(Request $request): TrustedRedirectResponse {
    // Always server-mint the workflow ID. It is the bearer capability for the
    // session payload (the session is stored under it), so a client must never
    // be allowed to choose it - honoring a ?workflow_id= would let a caller fix
    // the session key and later read another user's session / CPR. 32 hex chars
    // = 128 bits of entropy.
    $workflowId = 'wf_' . bin2hex(random_bytes(16));

    // Get redirect URL (where to return after auth).
    // Validate that it's an internal path to prevent open redirect attacks.
    $returnUrl = $request->query->get('return_url') ?? '/';

    // Reject backslashes outright: browsers normalise "/\" and "\/" to "//"
    // (protocol-relative), so a value like "/\evil.com" that looks local here
    // would redirect off-site after the session id is appended in callback().
    if (str_contains($returnUrl, '\\')) {
      $this->getLogger('aabenforms_mitid')->warning('Rejected return_url with backslash: @url', ['@url' => $returnUrl]);
      $returnUrl = '/';
    }

    // Strip any external URLs - only allow internal paths or trusted frontend origins.
    if (preg_match('#^https?://#i', $returnUrl)) {
      $currentHost = $request->getSchemeAndHttpHost();
      $returnHost = parse_url($returnUrl, PHP_URL_SCHEME) . '://' . parse_url($returnUrl, PHP_URL_HOST);
      $returnHostWithPort = $returnHost;
      $port = parse_url($returnUrl, PHP_URL_PORT);
      if ($port) {
        $returnHostWithPort = $returnHost . ':' . $port;
      }

      // Allow the current site and configured CORS origins (frontend).
      $corsOrigin = getenv('CORS_ALLOW_ORIGIN') ?: '';
      $allowedOrigins = array_filter([$currentHost, $corsOrigin]);

      if (!in_array($returnHost, $allowedOrigins, TRUE) && !in_array($returnHostWithPort, $allowedOrigins, TRUE)) {
        $this->getLogger('aabenforms_mitid')->warning('Rejected external return_url: @url', ['@url' => $returnUrl]);
        $returnUrl = '/';
      }
    }
    elseif (str_starts_with($returnUrl, '//')) {
      // Reject protocol-relative URLs (e.g., //evil.com).
      $this->getLogger('aabenforms_mitid')->warning('Rejected protocol-relative return_url: @url', ['@url' => $returnUrl]);
      $returnUrl = '/';
    }
    elseif (!str_starts_with($returnUrl, '/')) {
      // Ensure path starts with / for relative URLs.
      $returnUrl = '/' . $returnUrl;
    }

    // Generate authorization URL.
    $result = $this->oidcClient->getAuthorizationUrl([
      'redirect_uri' => Url::fromRoute('aabenforms_mitid.callback', [], ['absolute' => TRUE])->toString(),
    ]);

    // Store state and workflow ID in session for CSRF protection.
    $tempStore = \Drupal::service('tempstore.private')->get('aabenforms_mitid');
    $tempStore->set('oauth_state_' . $result['state'], [
      'workflow_id' => $workflowId,
      'return_url' => $returnUrl,
      'created' => time(),
      'code_verifier' => $result['code_verifier'] ?? '',
      'nonce' => $result['nonce'] ?? '',
    ]);

    // Redirect to MitID (external URL - use TrustedRedirectResponse).
    return new TrustedRedirectResponse($result['url']);
  }

  /**
   * Handles MitID OIDC callback.
   *
   * Route: /mitid/callback.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   Redirect to return URL or JSON response on error.
   */
  public function callback(Request $request): RedirectResponse|JsonResponse {
    $code = $request->query->get('code');
    $state = $request->query->get('state');
    $error = $request->query->get('error');

    // Handle errors from MitID.
    if ($error) {
      $errorDescription = $request->query->get('error_description', 'Unknown error');
      $this->getLogger('aabenforms_mitid')->error('MitID authentication error: {error}', [
        'error' => $error,
        'description' => $errorDescription,
      ]);

      $this->messenger()->addError($this->t('Authentication failed: @error', ['@error' => $errorDescription]));
      return new RedirectResponse('/');
    }

    // Validate required parameters.
    if (!$code || !$state) {
      return new JsonResponse(['error' => 'Missing required parameters'], 400);
    }

    // Verify state (CSRF protection).
    $tempStore = \Drupal::service('tempstore.private')->get('aabenforms_mitid');
    $stateData = $tempStore->get('oauth_state_' . $state);

    if (!$stateData) {
      $this->getLogger('aabenforms_mitid')->error('Invalid OAuth state parameter');
      return new JsonResponse(['error' => 'Invalid state parameter'], 400);
    }

    // Extract stored data.
    $workflowId = $stateData['workflow_id'];
    $returnUrl = $stateData['return_url'];

    // Clean up state.
    $tempStore->delete('oauth_state_' . $state);

    try {
      // Complete OIDC flow. Pass code_verifier + nonce stashed alongside
      // the state record so PKCE-enabled providers accept the token
      // exchange and id_token replay protection works.
      $sessionData = $this->oidcClient->completeFlow($code, $workflowId, [
        'redirect_uri' => Url::fromRoute('aabenforms_mitid.callback', [], ['absolute' => TRUE])->toString(),
        'code_verifier' => $stateData['code_verifier'] ?? '',
        'nonce' => $stateData['nonce'] ?? '',
      ]);

      // Store session ID in user's session for frontend access.
      $request->getSession()->set('mitid_workflow_id', $workflowId);
      $request->getSession()->set('mitid_authenticated', TRUE);
      $request->getSession()->set('mitid_cpr', $sessionData['cpr'] ?? NULL);

      $this->messenger()->addStatus($this->t('Successfully authenticated with MitID'));

      // Append session ID to return URL so frontend can retrieve session data.
      $separator = str_contains($returnUrl, '?') ? '&' : '?';
      $redirectUrl = $returnUrl . $separator . 'session=' . urlencode($workflowId);

      // Use TrustedRedirectResponse when the return URL is external - the
      // open-redirect guard in login() already validated the origin against
      // CORS_ALLOW_ORIGIN, so this is safe.
      if (preg_match('#^https?://#i', $redirectUrl)) {
        return new TrustedRedirectResponse($redirectUrl);
      }
      return new RedirectResponse($redirectUrl);

    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_mitid')->error('MitID callback failed: {error}', [
        'error' => $e->getMessage(),
      ]);

      $this->messenger()->addError($this->t('Authentication failed. Please try again.'));
      return new RedirectResponse('/');
    }
  }

  /**
   * Logout endpoint.
   *
   * Route: /mitid/logout.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to homepage.
   */
  public function logout(Request $request): RedirectResponse {
    // Clear MitID session data.
    $request->getSession()->remove('mitid_workflow_id');
    $request->getSession()->remove('mitid_authenticated');
    $request->getSession()->remove('mitid_cpr');

    $this->messenger()->addStatus($this->t('You have been logged out.'));

    return new RedirectResponse('/');
  }

  /**
   * Returns session data as JSON for frontend consumption.
   *
   * Route: /mitid/session/{session_id}.
   *
   * @param string $session_id
   *   The workflow/session ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Session data or error.
   */
  public function getSession(string $session_id): JsonResponse {
    $session = $this->sessionManager->getSession($session_id);

    if (!$session) {
      // Audit the miss too - probing for valid session ids is exactly what an
      // attacker would do against a bearer-capability endpoint.
      $this->auditLogger->logWorkflowAccess($session_id, 'session_data_read', 'not_found', []);
      return new JsonResponse(['error' => 'Session not found or expired'], 404);
    }

    // This endpoint is reachable by anyone presenting the (unguessable, 15-min)
    // workflow_id - the capability the citizen received at login. Record every
    // PII read so disclosure is auditable, and never put the full CPR on the
    // wire: the SPA only needs it for display (masked), and citizen flows read
    // the real CPR server-side from this same MitID session, not from the
    // response. mitid_uuid/auth_time/issuer/tokens stay server-side only.
    $cpr = (string) ($session['cpr'] ?? '');
    $this->auditLogger->logWorkflowAccess($session_id, 'session_data_read', 'success', [
      'assurance_level' => $session['assurance_level'] ?? 'unknown',
    ]);

    $address = $this->sessionManager->getAddressFromSession($session_id);

    return new JsonResponse([
      'data' => [
        'type' => 'mitid-session',
        'id' => $session_id,
        'attributes' => [
          'name' => $session['name'] ?? $session['given_name'] ?? '',
          'cpr' => $this->maskCpr($cpr),
          'email' => $session['email'] ?? '',
          'expiry' => $session['expires_at'] ?? '',
          'given_name' => $session['given_name'] ?? '',
          'family_name' => $session['family_name'] ?? '',
          'birthdate' => $session['birthdate'] ?? '',
          'address' => $address,
          'assurance_level' => $session['assurance_level'] ?? '',
        ],
      ],
    ]);
  }

  /**
   * Masks a CPR to DDMMYY-XXXX for display, never exposing the serial.
   *
   * @param string $cpr
   *   The full CPR, or empty.
   *
   * @return string
   *   The masked CPR, or empty when none was present.
   */
  protected function maskCpr(string $cpr): string {
    $digits = preg_replace('/\D/', '', $cpr);
    if (strlen($digits) < 6) {
      return '';
    }
    return substr($digits, 0, 6) . '-XXXX';
  }

}
