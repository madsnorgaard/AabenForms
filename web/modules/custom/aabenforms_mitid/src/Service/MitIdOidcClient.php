<?php

namespace Drupal\aabenforms_mitid\Service;

use Drupal\aabenforms_core\Identity\IdentityProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Service for MitID OpenID Connect (OIDC) authentication.
 *
 * Implements the OIDC Authorization Code Flow with optional PKCE S256:
 * 1. Generate authorization URL (code_challenge + nonce when pkce_enabled)
 * 2. Handle callback with authorization code
 * 3. Exchange code for access + ID tokens (with code_verifier when pkce)
 * 4. Validate and extract user data; reject id_token nonce mismatch.
 *
 * PKCE is opt-in via aabenforms_mitid.settings.pkce_enabled (default
 * TRUE). Real Digitaliseringsstyrelsen test gateway requires it; the
 * Keycloak mock supports it; turning it off only makes sense for
 * legacy IdPs that pre-date RFC 7636.
 */
class MitIdOidcClient implements IdentityProviderInterface {

  /**
   * The stable provider id for the MitID OIDC rail.
   */
  public const PROVIDER_ID = 'mitid_oidc';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The CPR extractor service.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdCprExtractor
   */
  protected MitIdCprExtractor $cprExtractor;

  /**
   * The session manager service.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $sessionManager;

  /**
   * The id_token signature verifier.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdTokenVerifier
   */
  protected MitIdTokenVerifier $tokenVerifier;

  /**
   * Constructs a MitIdOidcClient.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\aabenforms_mitid\Service\MitIdCprExtractor $cpr_extractor
   *   The CPR extractor.
   * @param \Drupal\aabenforms_mitid\Service\MitIdSessionManager $session_manager
   *   The session manager.
   * @param \Drupal\aabenforms_mitid\Service\MitIdTokenVerifier $token_verifier
   *   The id_token signature verifier.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    MitIdCprExtractor $cpr_extractor,
    MitIdSessionManager $session_manager,
    MitIdTokenVerifier $token_verifier,
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('aabenforms_mitid');
    $this->cprExtractor = $cpr_extractor;
    $this->sessionManager = $session_manager;
    $this->tokenVerifier = $token_verifier;
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return self::PROVIDER_ID;
  }

  /**
   * Generates MitID authorization URL.
   *
   * @param array $options
   *   Options for authorization:
   *   - redirect_uri: The callback URL.
   *   - state: Optional state parameter for CSRF protection.
   *   - scope: Space-separated scopes (default from config, falls back to
   *     'openid ssn' if config is empty).
   *   - acr_values: Assurance level (default from config, falls back to
   *     'substantial').
   *
   * @return array
   *   Array with keys: 'url', 'state', 'code_verifier' (PKCE - empty when
   *   pkce_enabled is false), 'nonce'. Callers must persist code_verifier
   *   + nonce keyed by state and pass them back via completeFlow().
   */
  public function getAuthorizationUrl(array $options = []): array {
    $config = $this->configFactory->get('aabenforms_mitid.settings');

    $clientId = $config->get('client_id');
    $authorizationEndpoint = $config->get('authorization_endpoint')
        ?? 'https://gateway.test.mitid.dk/authorize';

    $redirectUri = $options['redirect_uri']
        ?? $config->get('redirect_uri')
        ?? '';

    $state = $options['state'] ?? $this->generateState();
    $scope = $options['scope'] ?? $this->resolveScopes();
    $acrValues = $options['acr_values']
        ?? (string) ($config->get('security.required_assurance_level') ?: 'substantial');

    // Always generate a fresh nonce. Bound to the state record by the
    // caller and validated against the id_token's nonce claim later.
    $nonce = $this->generateState();

    $params = [
      'client_id' => $clientId,
      'redirect_uri' => $redirectUri,
      'response_type' => 'code',
      'scope' => $scope,
      'state' => $state,
      'nonce' => $nonce,
      'acr_values' => $acrValues,
      'response_mode' => 'query',
    ];

    $codeVerifier = '';
    if ((bool) ($config->get('pkce_enabled') ?? TRUE)) {
      $codeVerifier = $this->generateCodeVerifier();
      $params['code_challenge'] = $this->codeChallenge($codeVerifier);
      $params['code_challenge_method'] = 'S256';
    }

    $authUrl = $authorizationEndpoint . '?' . http_build_query($params);

    $this->logger->info(
          'Generated MitID authorization URL for client: {client_id}', [
            'client_id' => $clientId,
            'scope' => $scope,
            'acr_values' => $acrValues,
            'pkce' => $codeVerifier !== '',
          ]
      );

    return [
      'url' => $authUrl,
      'state' => $state,
      'code_verifier' => $codeVerifier,
      'nonce' => $nonce,
    ];
  }

  /**
   * Reads the configured scope list and renders it as a space-separated string.
   */
  protected function resolveScopes(): string {
    $config = $this->configFactory->get('aabenforms_mitid.settings');
    $scopes = $config->get('scopes');
    if (is_array($scopes) && $scopes !== []) {
      $scopes = array_filter(array_map('trim', $scopes), 'strlen');
      if ($scopes !== []) {
        return implode(' ', $scopes);
      }
    }
    return 'openid ssn';
  }

  /**
   * Generates an RFC 7636 code_verifier (URL-safe, 43..128 chars).
   */
  protected function generateCodeVerifier(): string {
    return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
  }

  /**
   * Computes the S256 code_challenge for a verifier.
   */
  protected function codeChallenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
  }

  /**
   * Exchanges authorization code for tokens.
   *
   * @param string $code
   *   The authorization code from MitID callback.
   * @param string $redirect_uri
   *   The redirect URI used in authorization.
   * @param string $code_verifier
   *   PKCE verifier paired with the code_challenge sent during auth.
   *   Empty when PKCE is disabled.
   *
   * @return array
   *   Token response with 'access_token', 'id_token', 'expires_in'.
   *
   * @throws \RuntimeException
   *   If token exchange fails.
   */
  public function exchangeCode(string $code, string $redirect_uri = '', string $code_verifier = ''): array {
    $config = $this->configFactory->get('aabenforms_mitid.settings');

    $clientId = $config->get('client_id');
    $clientSecret = $config->get('client_secret');
    $tokenEndpoint = $config->get('token_endpoint')
        ?? 'https://gateway.test.mitid.dk/token';

    if (empty($redirect_uri)) {
      $redirect_uri = $config->get('redirect_uri');
    }

    // Build token request. PKCE: include code_verifier when supplied.
    $params = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $redirect_uri,
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
    ];
    if ($code_verifier !== '') {
      $params['code_verifier'] = $code_verifier;
    }

    try {
      $response = $this->httpClient->post(
            $tokenEndpoint, [
              'form_params' => $params,
              'headers' => [
                'Accept' => 'application/json',
              ],
            ]
        );

      $body = (string) $response->getBody();
      $tokens = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('Invalid JSON response from token endpoint: ' . json_last_error_msg());
      }

      if (!isset($tokens['access_token']) || !isset($tokens['id_token'])) {
        throw new \RuntimeException('Token response missing required tokens');
      }

      $this->logger->info('Successfully exchanged authorization code for tokens');

      return $tokens;

    }
    catch (RequestException $e) {
      $this->logger->error(
            'Token exchange failed: {error}', [
              'error' => $e->getMessage(),
            ]
        );
      throw new \RuntimeException('Failed to exchange authorization code: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Validates ID token and extracts claims.
   *
   * @param string $id_token
   *   The ID token (JWT).
   *
   * @return array
   *   The validated claims.
   *
   * @throws \RuntimeException
   *   If validation fails.
   */
  public function validateIdToken(string $id_token): array {
    // Cryptographically verify the id_token before trusting ANY claim: RS256
    // signature against the issuer JWKS, plus iss/aud/exp. Fail closed - a
    // forged or tampered token must never reach claim extraction.
    $this->tokenVerifier->verify($id_token);

    // Enforce the eIDAS assurance level (NSIS LoA): a signature-valid token at
    // a level below the flow's requirement must be rejected, not merely logged.
    $this->enforceAssuranceLevel($id_token);

    return $this->cprExtractor->extractPersonData($id_token);
  }

  /**
   * Rejects a token whose assurance level is below the configured requirement.
   *
   * Ranks unknown < low < substantial < high; the municipal norm is
   * Betydelig/Substantial. Fails closed - an unrecognised acr maps to
   * 'unknown' and never satisfies a substantial/high requirement.
   *
   * @param string $id_token
   *   The (already signature-verified) ID token.
   *
   * @throws \RuntimeException
   *   When the asserted level is below the requirement.
   */
  private function enforceAssuranceLevel(string $id_token): void {
    $ranks = ['unknown' => 0, 'low' => 1, 'substantial' => 2, 'high' => 3];
    $required = (string) ($this->configFactory->get('aabenforms_mitid.settings')
      ->get('security.required_assurance_level') ?: 'substantial');
    $requiredRank = $ranks[$required] ?? 2;
    $actual = $this->cprExtractor->getAssuranceLevel($id_token);
    $actualRank = $ranks[$actual] ?? 0;
    if ($actualRank < $requiredRank) {
      $this->logger->warning('MitID assurance level too low: got {actual}, required {required}.', [
        'actual' => $actual,
        'required' => $required,
      ]);
      throw new \RuntimeException(sprintf('MitID assurance level "%s" is below the required "%s".', $actual, $required));
    }
  }

  /**
   * Completes OIDC flow: exchanges code, validates token, creates session.
   *
   * @param string $code
   *   The authorization code.
   * @param string $workflow_id
   *   The workflow instance ID.
   * @param array $options
   *   Additional options.
   *
   * @return array
   *   Session data with CPR, person data, and tokens.
   *
   * @throws \RuntimeException
   *   If flow fails.
   */
  public function completeFlow(string $code, string $workflow_id, array $options = []): array {
    // Exchange code for tokens. options.code_verifier comes from the
    // tempstore record the login() controller stashed alongside state.
    $tokens = $this->exchangeCode(
      $code,
      $options['redirect_uri'] ?? '',
      (string) ($options['code_verifier'] ?? '')
    );

    // Validate and extract person data. If a nonce was issued, the
    // id_token's nonce claim must match - protects against replay.
    $personData = $this->validateIdToken($tokens['id_token']);
    if (!empty($options['nonce'])) {
      $tokenNonce = $this->cprExtractor->extractClaim($tokens['id_token'], 'nonce');
      if ($tokenNonce === NULL || !hash_equals((string) $options['nonce'], (string) $tokenNonce)) {
        throw new \RuntimeException('id_token nonce mismatch');
      }
    }

    // Create session with flat structure (MitIdSessionManager expects
    // person fields at top level, not nested under 'person' key).
    $sessionData = array_merge(
          $personData, [
            'access_token' => $tokens['access_token'],
            'id_token' => $tokens['id_token'],
            'expires_in' => $tokens['expires_in'] ?? 3600,
            'authenticated_at' => time(),
          ]
      );

    $this->sessionManager->storeSession($workflow_id, $sessionData);

    $this->logger->info(
          'MitID OIDC flow completed for workflow: {workflow_id}', [
            'workflow_id' => $workflow_id,
            'cpr_masked' => substr($personData['cpr'] ?? '', 0, 6) . 'XXXX',
          ]
      );

    return $sessionData;
  }

  /**
   * Generates cryptographically secure state parameter.
   *
   * @return string
   *   The state parameter.
   */
  protected function generateState(): string {
    return bin2hex(random_bytes(16));
  }

  /**
   * Gets user info from MitID using access token.
   *
   * @param string $access_token
   *   The access token.
   *
   * @return array
   *   User info from userinfo endpoint.
   *
   * @throws \RuntimeException
   *   If request fails.
   */
  public function getUserInfo(string $access_token): array {
    $config = $this->configFactory->get('aabenforms_mitid.settings');
    $userinfoEndpoint = $config->get('userinfo_endpoint')
        ?? 'https://gateway.test.mitid.dk/userinfo';

    try {
      $response = $this->httpClient->get(
            $userinfoEndpoint, [
              'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
              ],
            ]
        );

      $body = (string) $response->getBody();
      $userInfo = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('Invalid JSON response from userinfo endpoint');
      }

      return $userInfo;

    }
    catch (RequestException $e) {
      $this->logger->error(
            'UserInfo request failed: {error}', [
              'error' => $e->getMessage(),
            ]
        );
      throw new \RuntimeException('Failed to get user info: ' . $e->getMessage(), 0, $e);
    }
  }

}
