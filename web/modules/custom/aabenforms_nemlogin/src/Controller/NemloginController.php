<?php

namespace Drupal\aabenforms_nemlogin\Controller;

use Drupal\aabenforms_nemlogin\Service\NemloginSamlAuthenticator;
use Drupal\aabenforms_nemlogin\Service\NemloginSettingsBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Settings as SamlSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NemLog-in OIOSAML 3 Service Provider endpoints.
 *
 * Mirrors MitIdController's security posture on the SAML rail: the workflow_id
 * bearer handle is always server-minted, the post-login return target is
 * origin-validated, and the RelayState only ever carries an opaque lookup token
 * (never the return URL directly). Both rails terminate in the same session
 * store, so the frontend consumes the result identically.
 */
class NemloginController extends ControllerBase {

  /**
   * The SAML authenticator.
   *
   * @var \Drupal\aabenforms_nemlogin\Service\NemloginSamlAuthenticator
   */
  protected NemloginSamlAuthenticator $authenticator;

  /**
   * The php-saml settings builder.
   *
   * @var \Drupal\aabenforms_nemlogin\Service\NemloginSettingsBuilder
   */
  protected NemloginSettingsBuilder $settingsBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->authenticator = $container->get('aabenforms_nemlogin.authenticator');
    $instance->settingsBuilder = $container->get('aabenforms_nemlogin.settings_builder');
    return $instance;
  }

  /**
   * Whether the NemLog-in rail is configured and enabled.
   *
   * @return bool
   *   TRUE when enabled.
   */
  protected function enabled(): bool {
    return (bool) $this->config('aabenforms_nemlogin.settings')->get('enabled');
  }

  /**
   * Initiates a NemLog-in login (builds a signed AuthnRequest).
   *
   * Route: /nemlogin/login.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect to NemLog-in, or a 503 when the rail is not enabled.
   */
  public function login(Request $request): Response {
    if (!$this->enabled()) {
      return new JsonResponse(['error' => 'NemLog-in is not enabled'], 503);
    }

    // Server-mint the bearer handle; a client must never choose the session key.
    $workflowId = 'wf_' . bin2hex(random_bytes(16));
    $returnUrl = $this->safeReturnUrl($request);

    // RelayState is an opaque token; the sensitive values live server-side in
    // tempstore keyed by it, so nothing meaningful travels via the IdP.
    $relayState = bin2hex(random_bytes(16));
    $this->tempStore()->set('relay_' . $relayState, [
      'workflow_id' => $workflowId,
      'return_url' => $returnUrl,
      'created' => time(),
    ]);

    try {
      $auth = new SamlAuth($this->settingsBuilder->buildSettings());
      // $stay = TRUE returns the redirect URL instead of exiting.
      $ssoUrl = $auth->login($relayState, [], FALSE, FALSE, TRUE);
      return new TrustedRedirectResponse($ssoUrl);
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_nemlogin')->error('NemLog-in AuthnRequest failed: @e', ['@e' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not start NemLog-in login'], 500);
    }
  }

  /**
   * Assertion Consumer Service: validates the assertion and mints a session.
   *
   * Route: /nemlogin/acs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect to the return target with the session handle appended.
   */
  public function acs(Request $request): Response {
    if (!$this->enabled()) {
      return new JsonResponse(['error' => 'NemLog-in is not enabled'], 503);
    }

    $relayState = (string) $request->request->get('RelayState', '');
    $stateData = $relayState !== '' ? $this->tempStore()->get('relay_' . $relayState) : NULL;
    if (!$stateData) {
      $this->getLogger('aabenforms_nemlogin')->error('NemLog-in ACS: unknown or missing RelayState.');
      return new JsonResponse(['error' => 'Invalid or expired login state'], 400);
    }
    $this->tempStore()->delete('relay_' . $relayState);

    $samlResponse = (string) $request->request->get('SAMLResponse', '');
    if ($samlResponse === '') {
      return new JsonResponse(['error' => 'Missing SAMLResponse'], 400);
    }

    try {
      $this->authenticator->authenticateResponse($samlResponse, $stateData['workflow_id']);
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_nemlogin')->warning('NemLog-in ACS rejected: @e', ['@e' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Login could not be completed.'));
      return new RedirectResponse('/');
    }

    // Bind the handle to the browser session (same-origin path) and hand it to
    // the frontend on the return URL, exactly as the MitID rail does.
    $request->getSession()->set('mitid_workflow_id', $stateData['workflow_id']);
    $request->getSession()->set('mitid_authenticated', TRUE);

    $returnUrl = $stateData['return_url'];
    $separator = str_contains($returnUrl, '?') ? '&' : '?';
    $redirectUrl = $returnUrl . $separator . 'session=' . urlencode($stateData['workflow_id']);
    if (preg_match('#^https?://#i', $redirectUrl)) {
      return new TrustedRedirectResponse($redirectUrl);
    }
    return new RedirectResponse($redirectUrl);
  }

  /**
   * Single Logout Service endpoint.
   *
   * Route: /nemlogin/slo.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect to the homepage.
   */
  public function slo(Request $request): Response {
    $request->getSession()->remove('mitid_workflow_id');
    $request->getSession()->remove('mitid_authenticated');
    if ($this->enabled()) {
      try {
        $auth = new SamlAuth($this->settingsBuilder->buildSettings());
        $auth->processSLO();
      }
      catch (\Exception $e) {
        $this->getLogger('aabenforms_nemlogin')->warning('NemLog-in SLO: @e', ['@e' => $e->getMessage()]);
      }
    }
    return new RedirectResponse('/');
  }

  /**
   * Publishes the SP metadata (for the NemLog-in registration exchange).
   *
   * Route: /nemlogin/metadata.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The SP metadata XML, or a 500 when it cannot be built or is invalid.
   */
  public function metadata(): Response {
    try {
      $settings = new SamlSettings($this->settingsBuilder->buildSettings(), TRUE);
      $metadata = $settings->getSPMetadata();
      $errors = $settings->validateMetadata($metadata);
      if (!empty($errors)) {
        $this->getLogger('aabenforms_nemlogin')->error('Invalid SP metadata: @e', ['@e' => implode(', ', $errors)]);
        return new JsonResponse(['error' => 'Invalid SP metadata'], 500);
      }
      return new Response($metadata, 200, ['Content-Type' => 'text/xml']);
    }
    catch (\Exception $e) {
      $this->getLogger('aabenforms_nemlogin')->error('SP metadata failed: @e', ['@e' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not build SP metadata'], 500);
    }
  }

  /**
   * Returns the module's private tempstore.
   *
   * @return \Drupal\Core\TempStore\PrivateTempStore
   *   The tempstore.
   */
  protected function tempStore() {
    return \Drupal::service('tempstore.private')->get('aabenforms_nemlogin');
  }

  /**
   * Validates the return_url against open-redirect, matching the MitID guard.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string
   *   A safe local path or trusted-origin URL; defaults to '/'.
   */
  protected function safeReturnUrl(Request $request): string {
    $returnUrl = (string) ($request->query->get('return_url') ?? '/');
    // Browsers normalise backslashes to '//', so a "/\evil.com" would escape.
    if (str_contains($returnUrl, '\\')) {
      return '/';
    }
    if (preg_match('#^https?://#i', $returnUrl)) {
      $currentHost = $request->getSchemeAndHttpHost();
      $host = parse_url($returnUrl, PHP_URL_SCHEME) . '://' . parse_url($returnUrl, PHP_URL_HOST);
      $port = parse_url($returnUrl, PHP_URL_PORT);
      $hostWithPort = $port ? $host . ':' . $port : $host;
      $allowed = array_filter([$currentHost, getenv('CORS_ALLOW_ORIGIN') ?: '']);
      if (!in_array($host, $allowed, TRUE) && !in_array($hostWithPort, $allowed, TRUE)) {
        return '/';
      }
      return $returnUrl;
    }
    if (str_starts_with($returnUrl, '//')) {
      return '/';
    }
    if (!str_starts_with($returnUrl, '/')) {
      $returnUrl = '/' . $returnUrl;
    }
    return $returnUrl;
  }

}
