<?php

namespace Drupal\aabenforms_mitid\Controller;

use Drupal\aabenforms_core\Http\SessionBearer;
use Drupal\aabenforms_core\Identity\LoginHandoff;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_mitid\Service\MitIdOidcClient;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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
   * The flood service (rate limits the revoke endpoint).
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * One-time code handoff to the SPA (#156).
   */
  protected LoginHandoff $loginHandoff;

  /**
   * Shape of a server-minted workflow id (see login()).
   */
  public const WORKFLOW_ID_PATTERN = '/^wf_[a-f0-9]{32}$/';

  /**
   * Flood event name and limits for the revoke endpoint (prefill envelope).
   */
  protected const REVOKE_FLOOD_EVENT = 'aabenforms_mitid.revoke';
  protected const REVOKE_FLOOD_LIMIT = 30;
  protected const REVOKE_FLOOD_WINDOW = 60;

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
    $instance = new static(
      $container->get('aabenforms_mitid.oidc_client'),
      $container->get('aabenforms_mitid.session_manager'),
      $container->get('aabenforms_core.audit_logger')
    );
    $instance->flood = $container->get('flood');
    $instance->loginHandoff = $container->get('aabenforms_core.login_handoff');
    return $instance;
  }

  /**
   * Initiates MitID login flow.
   *
   * Route: /mitid/login.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Redirect to MitID authorization, or 400 when an SPA login lacks a
   *   valid client_challenge.
   */
  public function login(Request $request): Response {
    // Always server-mint the workflow ID. It is the bearer capability for the
    // session payload (the session is stored under it), so a client must never
    // be allowed to choose it - honoring a ?workflow_id= would let a caller fix
    // the session key and later read another user's session / CPR. 32 hex chars
    // = 128 bits of entropy.
    $workflowId = 'wf_' . bin2hex(random_bytes(16));

    // Get redirect URL (where to return after auth).
    // Validate that it's an internal path to prevent open redirect attacks.
    $returnUrl = $this->sanitizeReturnUrl($request, (string) ($request->query->get('return_url') ?? '/'));

    // A return to another origin (the SPA) gets the session through a
    // one-time code, redeemable only with the secret behind this challenge
    // (PKCE pattern, see LoginHandoff). Backend pages on this origin use the
    // Drupal session instead and get no code at all.
    $challenge = '';
    if (!$this->isOwnOrigin($request, $returnUrl)) {
      $challenge = $request->query->get('client_challenge');
      if (!LoginHandoff::isValidChallenge($challenge)) {
        return new JsonResponse(['error' => 'client_challenge required'], 400);
      }
    }

    // ?login_hint= is only a username suggestion forwarded to the IdP as the
    // standard OIDC login_hint; the person still has to authenticate there.
    // It grants no identity or session capability. Strictly validated.
    $rawHint = $request->query->get('login_hint');
    $loginHint = MitIdOidcClient::sanitizeLoginHint($rawHint);
    if ($rawHint !== NULL && $loginHint === NULL) {
      $this->getLogger('aabenforms_mitid')->warning('Ignored malformed login_hint (length @len).', ['@len' => strlen((string) $rawHint)]);
    }

    // Generate authorization URL.
    // ?fresh=1 forces the IdP to re-authenticate instead of reusing its SSO
    // session (prompt=login). It can only make login stricter, never looser;
    // used when a second person signs on the same device (co-signing parent).
    $result = $this->oidcClient->getAuthorizationUrl([
      'redirect_uri' => Url::fromRoute('aabenforms_mitid.callback', [], ['absolute' => TRUE])->toString(),
      'force_login' => $request->query->get('fresh') === '1',
      'login_hint' => $loginHint,
    ]);

    // Store state and workflow ID in session for CSRF protection.
    $tempStore = \Drupal::service('tempstore.private')->get('aabenforms_mitid');
    $tempStore->set('oauth_state_' . $result['state'], [
      'workflow_id' => $workflowId,
      'return_url' => $returnUrl,
      'created' => time(),
      'code_verifier' => $result['code_verifier'] ?? '',
      'nonce' => $result['nonce'] ?? '',
      'client_challenge' => $challenge,
    ]);

    // Redirect to MitID (external URL - use TrustedRedirectResponse).
    return new TrustedRedirectResponse($result['url']);
  }

  /**
   * Validates a return_url: internal path or allowed (CORS) origin only.
   *
   * Shared by login() and logout() so both apply the exact same open-redirect
   * guard. Anything rejected collapses to '/'.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $returnUrl
   *   The candidate return URL.
   *
   * @return string
   *   A safe return URL.
   */
  protected function sanitizeReturnUrl(Request $request, string $returnUrl): string {
    // Reject backslashes outright: browsers normalise "/\" and "\/" to "//"
    // (protocol-relative), so a value like "/\evil.com" that looks local here
    // would redirect off-site after the session id is appended in callback().
    if (str_contains($returnUrl, '\\')) {
      $this->getLogger('aabenforms_mitid')->warning('Rejected return_url with backslash: @url', ['@url' => $returnUrl]);
      return '/';
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
        return '/';
      }
    }
    elseif (str_starts_with($returnUrl, '//')) {
      // Reject protocol-relative URLs (e.g., //evil.com).
      $this->getLogger('aabenforms_mitid')->warning('Rejected protocol-relative return_url: @url', ['@url' => $returnUrl]);
      return '/';
    }
    elseif (!str_starts_with($returnUrl, '/')) {
      // Ensure path starts with / for relative URLs.
      $returnUrl = '/' . $returnUrl;
    }

    return $returnUrl;
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

      // completeFlow() keeps the raw id_token in the server-side session
      // payload; it is only ever used as id_token_hint by logout() and is
      // never part of getPublicAttributes().
      // Store session ID in user's session for frontend access.
      $request->getSession()->set('mitid_workflow_id', $workflowId);
      $request->getSession()->set('mitid_authenticated', TRUE);
      $request->getSession()->set('mitid_cpr', $sessionData['cpr'] ?? NULL);

      $this->messenger()->addStatus($this->t('Successfully authenticated with MitID'));

      // Never put the session id in the URL (#156). The SPA gets a one-time
      // code it can only redeem with its own verifier; same-origin backend
      // pages already hold the session in the Drupal session above.
      $challenge = (string) ($stateData['client_challenge'] ?? '');
      $redirectUrl = $challenge !== ''
        ? LoginHandoff::appendCode($returnUrl, $this->loginHandoff->issue($workflowId, $challenge))
        : $returnUrl;

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
   * RP-initiated logout.
   *
   * Route: GET|POST /mitid/logout?return_url=...
   *
   * Revokes the MitID session and sends the browser to the IdP end_session
   * endpoint so the IdP session ends too (on a shared device the next citizen
   * must not be offered the previous one). return_url gets the same guard as
   * login().
   *
   * Which session: a top-level POST from the SPA carries its own session id
   * in the form body (session_id) - like revoke, never in the URL. Otherwise
   * the one bound to THIS browser's Drupal session (key mitid_workflow_id).
   * The SPA's own id matters: a co-sign on /parent-approval consumes the
   * Drupal-session binding, and without an id_token_hint Keycloak stops on
   * its own "Do you want to log out?" page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the IdP logout, or to return_url when no IdP logout is
   *   configured.
   */
  public function logout(Request $request): RedirectResponse {
    $returnUrl = $this->sanitizeReturnUrl($request, (string) ($request->query->get('return_url') ?? '/'));
    $absoluteReturn = preg_match('#^https?://#i', $returnUrl)
      ? $returnUrl
      : $request->getSchemeAndHttpHost() . $returnUrl;

    $idToken = NULL;
    $session = $request->hasSession() ? $request->getSession() : NULL;
    $workflowIds = [];
    if ($request->isMethod('POST')) {
      // Same envelope as revoke(): a body id is a capability guess otherwise.
      $floodId = (string) $request->getClientIp();
      if ($this->flood->isAllowed(self::REVOKE_FLOOD_EVENT, self::REVOKE_FLOOD_LIMIT, self::REVOKE_FLOOD_WINDOW, $floodId)) {
        $this->flood->register(self::REVOKE_FLOOD_EVENT, self::REVOKE_FLOOD_WINDOW, $floodId);
        $workflowIds[] = $request->request->get('session_id');
      }
    }
    $workflowIds[] = $session ? $session->get('mitid_workflow_id') : NULL;
    foreach (array_unique(array_filter($workflowIds, 'is_string')) as $workflowId) {
      if (!preg_match(self::WORKFLOW_ID_PATTERN, $workflowId)) {
        continue;
      }
      $this->sessionManager->revokeSession($workflowId);
      $idToken ??= $this->sessionManager->takeLogoutIdToken($workflowId);
    }

    // Clear MitID session data.
    if ($session) {
      $session->remove('mitid_workflow_id');
      $session->remove('mitid_authenticated');
      $session->remove('mitid_cpr');
    }

    $endSessionUrl = $this->oidcClient->getEndSessionUrl($idToken, $absoluteReturn);
    if ($endSessionUrl !== NULL) {
      return new TrustedRedirectResponse($endSessionUrl);
    }
    return preg_match('#^https?://#i', $returnUrl)
      ? new TrustedRedirectResponse($returnUrl)
      : new RedirectResponse($returnUrl);
  }

  /**
   * Revokes a MitID session (citizen logout from the SPA).
   *
   * Route: POST /api/mitid/session/revoke, body {"session_id": "wf_..."}.
   *
   * The workflow id is the bearer capability, so it travels in the body, never
   * the URL (no access-log / Referer leak). Always 204 for a well-formed id,
   * whether or not the session existed, so this is not an existence oracle.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   204 on success, 400 on a malformed id, 429 when rate limited.
   */
  public function revoke(Request $request): Response {
    $floodId = (string) $request->getClientIp();
    if (!$this->flood->isAllowed(self::REVOKE_FLOOD_EVENT, self::REVOKE_FLOOD_LIMIT, self::REVOKE_FLOOD_WINDOW, $floodId)) {
      return new JsonResponse(['error' => 'Too many requests, please try again later'], 429);
    }
    $this->flood->register(self::REVOKE_FLOOD_EVENT, self::REVOKE_FLOOD_WINDOW, $floodId);

    $body = json_decode((string) $request->getContent(), TRUE);
    $workflowId = is_array($body) ? ($body['session_id'] ?? NULL) : NULL;
    if (!is_string($workflowId) || !preg_match(self::WORKFLOW_ID_PATTERN, $workflowId)) {
      return new JsonResponse(['error' => 'Invalid session_id'], 400);
    }

    $this->sessionManager->revokeSession($workflowId);

    // Same browser? Drop the Drupal-session binding too.
    if ($request->hasSession() && $request->getSession()->get('mitid_workflow_id') === $workflowId) {
      $request->getSession()->remove('mitid_authenticated');
      $request->getSession()->remove('mitid_cpr');
    }

    return new Response('', 204);
  }

  /**
   * Redeems the one-time login code for the session (#156).
   *
   * Route: POST /api/mitid/session/exchange, body
   * {"code": "...", "code_verifier": "..."}. The code comes from the return
   * URL; the verifier never left the browser that started the login. Any
   * failure is a generic 400, so this is no oracle for codes or sessions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The session id and its public attributes, or 400.
   */
  public function exchange(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'no-store, private'];
    $body = json_decode((string) $request->getContent(), TRUE);
    $workflowId = is_array($body)
      ? $this->loginHandoff->redeem($body['code'] ?? NULL, $body['code_verifier'] ?? NULL)
      : NULL;
    $attributes = $workflowId !== NULL ? $this->sessionManager->getPublicAttributes($workflowId) : NULL;
    if ($workflowId === NULL || $attributes === NULL) {
      $this->auditLogger->logWorkflowAccess('-', 'session_exchange', 'failure', []);
      return new JsonResponse(['error' => 'Invalid or expired login code'], 400, $headers);
    }
    $this->auditLogger->logWorkflowAccess($workflowId, 'session_exchange', 'success', []);
    return new JsonResponse([
      'data' => [
        'type' => 'mitid-session',
        'id' => $workflowId,
        'attributes' => $attributes,
      ],
    ], 200, $headers);
  }

  /**
   * Returns the caller's own session (public attributes only).
   *
   * Route: GET /api/mitid/session with "Authorization: Bearer <session>".
   * Replaces /mitid/session/{id}, which carried the capability in the URL.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Session data, or 401.
   */
  public function currentSession(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'no-store, private'];
    $sessionId = SessionBearer::fromRequest($request);
    $attributes = $sessionId !== '' ? $this->sessionManager->getPublicAttributes($sessionId) : NULL;
    if ($attributes === NULL) {
      // Audit the miss too: probing a bearer-capability endpoint is what an
      // attacker would do.
      $this->auditLogger->logWorkflowAccess($sessionId !== '' ? $sessionId : '-', 'session_data_read', 'not_found', []);
      return new JsonResponse(['error' => 'Session not found or expired'], 401, $headers);
    }
    // Every PII read is auditable. The full CPR never goes on the wire: the
    // public attributes carry it masked, and flows read the real CPR
    // server-side from the session.
    $this->auditLogger->logWorkflowAccess($sessionId, 'session_data_read', 'success', [
      'assurance_level' => $attributes['assurance_level'] ?? 'unknown',
    ]);
    return new JsonResponse([
      'data' => [
        'type' => 'mitid-session',
        'id' => $sessionId,
        'attributes' => $attributes,
      ],
    ], 200, $headers);
  }

  /**
   * Whether a (sanitized) return URL points back to this backend.
   */
  protected function isOwnOrigin(Request $request, string $returnUrl): bool {
    if (!preg_match('#^https?://#i', $returnUrl)) {
      return TRUE;
    }
    $parts = parse_url($returnUrl);
    $origin = strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    return $origin === strtolower($request->getSchemeAndHttpHost());
  }

}
