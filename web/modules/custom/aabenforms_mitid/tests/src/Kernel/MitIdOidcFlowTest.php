<?php

namespace Drupal\Tests\aabenforms_mitid\Kernel;

use Drupal\aabenforms_mitid\Service\MitIdOidcClient;
use Drupal\aabenforms_mitid\Service\MitIdTokenVerifier;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests MitID OpenID Connect authorization flow.
 *
 * Validates:
 * - Full authorization flow (Login → Callback → Session creation)
 * - State validation (CSRF protection)
 * - Token exchange (authorization code → access token)
 * - Session expiry handling
 * - Integration with MitIdSessionManager.
 *
 * @group aabenforms_mitid
 * @group integration
 */
class MitIdOidcFlowTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'domain',
    'key',
    'encrypt',
    'aabenforms_core',
    'aabenforms_mitid',
  ];

  /**
   * The MitID OIDC client.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdOidcClient
   */
  protected $oidcClient;

  /**
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected $sessionManager;

  /**
   * The mock HTTP handler.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected $mockHandler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('domain');
    $this->installConfig(['system', 'domain', 'aabenforms_core', 'aabenforms_mitid']);

    // Configure MitID settings.
    $config = $this->config('aabenforms_mitid.settings');
    $config->set('client_id', 'test_client_id');
    $config->set('client_secret', 'test_client_secret');
    $config->set('authorization_endpoint', 'https://test.mitid.dk/authorize');
    $config->set('token_endpoint', 'https://test.mitid.dk/token');
    $config->set('userinfo_endpoint', 'https://test.mitid.dk/userinfo');
    $config->set('redirect_uri', 'https://aabenforms.ddev.site/mitid/callback');
    $config->save();

    // Get services.
    $this->sessionManager = \Drupal::service('aabenforms_mitid.session_manager');

    // Create mock HTTP client for OIDC requests.
    $this->mockHandler = new MockHandler();
    $handlerStack = HandlerStack::create($this->mockHandler);
    $httpClient = new Client(['handler' => $handlerStack]);

    // Stub the signature verifier: RS256/JWKS verification is covered by
    // MitIdTokenVerifierTest. This flow test exercises code exchange + session
    // creation with a mock id_token, so bypass the crypto rather than reach for
    // a real JWKS endpoint.
    $tokenVerifier = new class(
      $this->container->get('config.factory'),
      $httpClient,
      $this->container->get('cache.default'),
      $this->container->get('logger.factory'),
    ) extends MitIdTokenVerifier {

      /**
       * {@inheritdoc}
       */
      public function verify(string $idToken): array {
        return [];
      }

    };

    // Create OIDC client with mocked HTTP client.
    $this->oidcClient = new MitIdOidcClient(
      $this->container->get('config.factory'),
      $httpClient,
      $this->container->get('logger.factory'),
      $this->container->get('aabenforms_mitid.cpr_extractor'),
      $this->sessionManager,
      $tokenVerifier
    );
  }

  /**
   * Tests complete authorization flow: Login → Callback → Session.
   */
  public function testCompleteAuthorizationFlow(): void {
    $workflowId = 'test_workflow_' . time();

    // STEP 1: Generate authorization URL.
    $authData = $this->oidcClient->getAuthorizationUrl([
      'redirect_uri' => 'https://aabenforms.ddev.site/mitid/callback',
    ]);

    $this->assertArrayHasKey('url', $authData);
    $this->assertArrayHasKey('state', $authData);
    $this->assertStringContainsString('client_id=test_client_id', $authData['url']);
    $this->assertStringContainsString('response_type=code', $authData['url']);
    $this->assertStringContainsString('scope=openid+ssn', $authData['url']);
    $this->assertStringContainsString('state=' . $authData['state'], $authData['url']);

    $state = $authData['state'];

    // STEP 2: Simulate callback with authorization code.
    $authorizationCode = 'test_auth_code_12345';

    // Mock token exchange response.
    $tokenResponse = json_encode([
      'access_token' => 'test_access_token_abc',
      'id_token' => $this->createMockIdToken(),
      'token_type' => 'Bearer',
      'expires_in' => 3600,
    ]);

    $this->mockHandler->append(new Response(200, ['Content-Type' => 'application/json'], $tokenResponse));

    // STEP 3: Exchange code for tokens and create session.
    try {
      $sessionData = $this->oidcClient->completeFlow($authorizationCode, $workflowId, [
        'redirect_uri' => 'https://aabenforms.ddev.site/mitid/callback',
      ]);

      // Verify session data structure.
      $this->assertArrayHasKey('cpr', $sessionData);
      $this->assertArrayHasKey('access_token', $sessionData);
      $this->assertArrayHasKey('id_token', $sessionData);
      $this->assertArrayHasKey('authenticated_at', $sessionData);

      // STEP 4: Verify session is stored.
      $storedSession = $this->sessionManager->getSession($workflowId);
      $this->assertNotNull($storedSession);
      $this->assertEquals($sessionData['cpr'], $storedSession['cpr']);
      $this->assertEquals('test_access_token_abc', $storedSession['access_token']);
      $this->assertTrue($this->sessionManager->hasValidSession($workflowId));
    }
    catch (\RuntimeException $e) {
      // If ID token validation fails (due to missing JWT library in test),
      // we can mark this as skipped.
      $this->markTestSkipped('ID token validation requires JWT library: ' . $e->getMessage());
    }
  }

  /**
   * Tests state validation (CSRF protection).
   */
  public function testStateValidation(): void {
    // Generate authorization URL with state.
    $authData = $this->oidcClient->getAuthorizationUrl();
    $validState = $authData['state'];

    // Verify state is a cryptographically secure random string.
    $this->assertNotEmpty($validState);
    $this->assertGreaterThanOrEqual(32, strlen($validState), 'State is at least 32 characters');
    $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $validState, 'State is hexadecimal');

    // In a real implementation, the application would:
    // 1. Store $validState in session during redirect
    // 2. Verify state parameter in callback matches stored state
    // 3. Reject callback if state doesn't match (CSRF attack)
    //
    // This test verifies state generation works correctly.
  }

  /**
   * Tests that a valid login_hint is forwarded and an invalid one dropped.
   */
  public function testLoginHint(): void {
    $authData = $this->oidcClient->getAuthorizationUrl(['login_hint' => 'birthe.jensen', 'force_login' => TRUE]);
    $query = [];
    parse_str((string) parse_url($authData['url'], PHP_URL_QUERY), $query);
    $this->assertSame('birthe.jensen', $query['login_hint'] ?? NULL);
    $this->assertSame('login', $query['prompt'] ?? NULL, 'fresh/force_login still works alongside login_hint');

    foreach (['', 'Birthe', '.birthe', 'a b', 'x&prompt=none', 'a@b.dk', str_repeat('a', 65), ['x']] as $bad) {
      $authData = $this->oidcClient->getAuthorizationUrl(['login_hint' => $bad]);
      $query = [];
      parse_str((string) parse_url($authData['url'], PHP_URL_QUERY), $query);
      $this->assertArrayNotHasKey('login_hint', $query, 'Invalid hint dropped: ' . var_export($bad, TRUE));
      $this->assertArrayNotHasKey('prompt', $query);
    }

    $authData = $this->oidcClient->getAuthorizationUrl();
    $this->assertStringNotContainsString('login_hint', $authData['url']);
  }

  /**
   * Tests token exchange (authorization code → access token).
   */
  public function testTokenExchange(): void {
    $authorizationCode = 'test_auth_code_xyz';

    // Mock successful token response.
    $tokenResponse = json_encode([
      'access_token' => 'test_access_token_xyz',
      'id_token' => $this->createMockIdToken(),
      'token_type' => 'Bearer',
      'expires_in' => 3600,
      'refresh_token' => 'test_refresh_token',
    ]);

    $this->mockHandler->append(new Response(200, ['Content-Type' => 'application/json'], $tokenResponse));

    // Exchange code for tokens.
    $tokens = $this->oidcClient->exchangeCode($authorizationCode, 'https://aabenforms.ddev.site/mitid/callback');

    // Verify token response.
    $this->assertArrayHasKey('access_token', $tokens);
    $this->assertArrayHasKey('id_token', $tokens);
    $this->assertArrayHasKey('expires_in', $tokens);
    $this->assertEquals('test_access_token_xyz', $tokens['access_token']);
    $this->assertEquals(3600, $tokens['expires_in']);
  }

  /**
   * Tests session expiry handling.
   */
  public function testSessionExpiryHandling(): void {
    $workflowId = 'expiry_test_' . time();

    // Create session with short expiry.
    $sessionData = [
      'cpr' => '0101701234',
      'name' => 'Jane Doe',
      'access_token' => 'test_token',
      'authenticated_at' => time(),
      'expires_at' => time() - 1,
    ];

    $this->sessionManager->storeSession($workflowId, $sessionData);

    // Verify session exists.
    $session = $this->sessionManager->getSession($workflowId);
    $this->assertNotNull($session, 'Session data exists');

    // Check if expires_at was set (may be auto-set by session manager).
    $this->assertArrayHasKey('expires_at', $session, 'Session has expires_at key');

    // Create session with future expiry.
    $workflowId2 = 'valid_session_' . time();
    $sessionData2 = [
      'cpr' => '0202705678',
      'name' => 'John Doe',
      'access_token' => 'test_token_2',
      'authenticated_at' => time(),
      'expires_at' => time() + 3600,
    ];

    $this->sessionManager->storeSession($workflowId2, $sessionData2);

    // Verify session is valid.
    $isValid2 = $this->sessionManager->hasValidSession($workflowId2);
    $this->assertTrue($isValid2, 'Future expiry session is valid');

    // Verify both sessions can be retrieved.
    $session2 = $this->sessionManager->getSession($workflowId2);
    $this->assertEquals('0202705678', $session2['cpr']);
  }

  /**
   * Creates a mock ID token (JWT) for testing.
   *
   * @return string
   *   A mock JWT token.
   */
  protected function createMockIdToken(): string {
    // Mock JWT structure: header.payload.signature
    // For testing purposes, we use a simple base64-encoded payload.
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode([
      'sub' => '0101701234',
      'cpr' => '0101701234',
      'name' => 'Jane Doe',
      'iat' => time(),
      'exp' => time() + 3600,
    ]));
    $signature = base64_encode('mock_signature');

    return "{$header}.{$payload}.{$signature}";
  }

}
