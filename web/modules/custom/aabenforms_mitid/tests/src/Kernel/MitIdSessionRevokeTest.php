<?php

namespace Drupal\Tests\aabenforms_mitid\Kernel;

use Drupal\aabenforms_mitid\Controller\MitIdController;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests MitID session revocation and RP-initiated logout.
 *
 * @group aabenforms_mitid
 */
class MitIdSessionRevokeTest extends KernelTestBase {

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
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected $sessionManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);
    $this->installConfig(['system', 'aabenforms_core', 'aabenforms_mitid']);
    $this->config('aabenforms_mitid.settings')
      ->set('client_id', 'aabenforms-backend')
      ->set('authorization_endpoint', 'http://localhost:8080/realms/r/protocol/openid-connect/auth')
      ->save();
    $this->sessionManager = $this->container->get('aabenforms_mitid.session_manager');
  }

  /**
   * Stores a session carrying tokens and returns its workflow id.
   */
  protected function seed(): string {
    $id = 'wf_' . bin2hex(random_bytes(16));
    $this->sessionManager->storeSession($id, [
      'cpr' => '0101001234',
      'name' => 'Test Testesen',
      'access_token' => 'ACCESS.TOKEN.SECRET',
      'id_token' => 'ID.TOKEN.SECRET',
    ]);
    return $id;
  }

  /**
   * Builds a JSON revoke request.
   */
  protected function revokeRequest(mixed $sessionId): Request {
    return Request::create('/api/mitid/session/revoke', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['session_id' => $sessionId]));
  }

  /**
   * Counts audit rows for a purpose.
   */
  protected function auditCount(string $purpose, string $id): int {
    return (int) $this->container->get('database')->select('aabenforms_audit_log', 'a')
      ->condition('purpose', $purpose)
      ->condition('identifier_hash', hash('sha256', $id))
      ->countQuery()->execute()->fetchField();
  }

  /**
   * Revoke returns 204, deletes the session and writes an audit row.
   */
  public function testRevokeDeletesSession(): void {
    $id = $this->seed();
    $controller = MitIdController::create($this->container);

    $response = $controller->revoke($this->revokeRequest($id));
    $this->assertSame(204, $response->getStatusCode());
    $this->assertNull($this->sessionManager->getSession($id));
    $this->assertSame(1, $this->auditCount('mitid_session_revoked', $id));

    // Unknown (but well-formed) id: same 204, no oracle.
    $unknown = 'wf_' . bin2hex(random_bytes(16));
    $this->assertSame(204, $controller->revoke($this->revokeRequest($unknown))->getStatusCode());
    $this->assertSame(1, $this->auditCount('mitid_session_revoked', $unknown));
  }

  /**
   * Malformed ids are rejected with 400 and nothing is revoked.
   */
  public function testRevokeRejectsMalformedId(): void {
    $controller = MitIdController::create($this->container);
    $seeded = $this->seed();
    foreach (['wf_xyz', 'wf_' . str_repeat('A', 32), $seeded . 'x', '../' . $seeded, 123, NULL] as $bad) {
      $this->assertSame(400, $controller->revoke($this->revokeRequest($bad))->getStatusCode());
    }
    $empty = Request::create('/api/mitid/session/revoke', 'POST', [], [], [], [], 'not json');
    $this->assertSame(400, $controller->revoke($empty)->getStatusCode());
    $this->assertNotNull($this->sessionManager->getSession($seeded));
  }

  /**
   * Tokens never appear in the public session view.
   */
  public function testPublicAttributesNeverExposeTokens(): void {
    $id = $this->seed();
    $this->assertSame('ID.TOKEN.SECRET', $this->sessionManager->getSession($id)['id_token']);
    $public = $this->sessionManager->getPublicAttributes($id);
    $this->assertArrayNotHasKey('id_token', $public);
    $this->assertArrayNotHasKey('access_token', $public);
    $encoded = json_encode($public);
    $this->assertStringNotContainsString('ID.TOKEN.SECRET', $encoded);
    $this->assertStringNotContainsString('ACCESS.TOKEN.SECRET', $encoded);

    // The session endpoint response, too.
    $request = Request::create('/api/mitid/session');
    $request->headers->set('Authorization', 'Bearer ' . $id);
    $body = MitIdController::create($this->container)->currentSession($request)->getContent();
    $this->assertStringNotContainsString('ID.TOKEN.SECRET', $body);
    $this->assertStringNotContainsString('id_token', $body);
  }

  /**
   * Logout revokes the Drupal-session-bound session and hints the IdP.
   */
  public function testLogoutUsesDrupalSessionBinding(): void {
    $id = $this->seed();
    $other = $this->seed();
    // A workflow id in the URL must be ignored.
    $request = Request::create('/mitid/logout', 'GET', [
      'return_url' => '/demo/klage',
      'session' => $other,
      'workflow_id' => $other,
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('mitid_workflow_id', $id);
    $session->set('mitid_authenticated', TRUE);
    $request->setSession($session);

    $response = MitIdController::create($this->container)->logout($request);
    $location = $response->headers->get('Location');

    $this->assertStringStartsWith('http://localhost:8080/realms/r/protocol/openid-connect/logout?', $location);
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    $this->assertSame('ID.TOKEN.SECRET', $query['id_token_hint']);
    $this->assertSame('aabenforms-backend', $query['client_id']);
    $this->assertSame('http://localhost/demo/klage', $query['post_logout_redirect_uri']);
    $this->assertNull($this->sessionManager->getSession($id));
    $this->assertNotNull($this->sessionManager->getSession($other));
    $this->assertFalse($session->has('mitid_workflow_id'));
    // The parked hint is single use.
    $this->assertNull($this->sessionManager->takeLogoutIdToken($id));
  }

  /**
   * After a body revoke, logout still gets the hint; open redirects collapse.
   */
  public function testLogoutAfterRevokeAndOpenRedirect(): void {
    $id = $this->seed();
    $controller = MitIdController::create($this->container);
    $controller->revoke($this->revokeRequest($id));

    $request = Request::create('/mitid/logout', 'GET', ['return_url' => 'https://evil.example/']);
    $session = new Session(new MockArraySessionStorage());
    $session->set('mitid_workflow_id', $id);
    $request->setSession($session);
    parse_str(parse_url($controller->logout($request)->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->assertSame('ID.TOKEN.SECRET', $query['id_token_hint']);
    $this->assertSame('http://localhost/', $query['post_logout_redirect_uri']);

    // No Drupal session binding: client_id only, no hint.
    $bare = Request::create('/mitid/logout', 'GET', ['return_url' => '/']);
    parse_str(parse_url($controller->logout($bare)->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->assertArrayNotHasKey('id_token_hint', $query);
    $this->assertSame('aabenforms-backend', $query['client_id']);
  }

  /**
   * A POST from the SPA names its own session in the body (never the URL).
   *
   * The co-sign case: /parent-approval consumed the Drupal-session binding,
   * so only the SPA's own id still leads to the id_token_hint.
   */
  public function testLogoutPostUsesBodySessionId(): void {
    $id = $this->seed();
    $other = $this->seed();
    $controller = MitIdController::create($this->container);

    $request = Request::create('/mitid/logout?return_url=/demo/skoleskift', 'POST', ['session_id' => $id]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    parse_str(parse_url($controller->logout($request)->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->assertSame('ID.TOKEN.SECRET', $query['id_token_hint']);
    $this->assertSame('http://localhost/demo/skoleskift', $query['post_logout_redirect_uri']);
    $this->assertNull($this->sessionManager->getSession($id));

    // An id in the query string is ignored, for GET and POST alike.
    foreach (['GET', 'POST'] as $method) {
      $request = Request::create('/mitid/logout?session_id=' . $other, $method, $method === 'POST' ? ['return_url' => '/'] : []);
      $request->setSession(new Session(new MockArraySessionStorage()));
      parse_str(parse_url($controller->logout($request)->headers->get('Location'), PHP_URL_QUERY), $query);
      $this->assertArrayNotHasKey('id_token_hint', $query, $method);
    }
    $this->assertNotNull($this->sessionManager->getSession($other));

    // A malformed body id is ignored.
    $request = Request::create('/mitid/logout', 'POST', ['session_id' => 'not-a-session']);
    parse_str(parse_url($controller->logout($request)->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->assertArrayNotHasKey('id_token_hint', $query);
  }

  /**
   * The SPA gets its session through a one-time code, never a URL id.
   */
  public function testCodeExchangeAndCurrentSession(): void {
    $id = $this->seed();
    $handoff = $this->container->get('aabenforms_core.login_handoff');
    $verifier = str_repeat('v', 43) . 'erifier';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
    $controller = MitIdController::create($this->container);
    $exchange = fn (array $body) => $controller->exchange(Request::create('/api/mitid/session/exchange', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body)));

    // Right code, wrong verifier: refused, and the code is gone.
    $code = $handoff->issue($id, $challenge);
    $this->assertSame(400, $exchange(['code' => $code, 'code_verifier' => str_repeat('x', 50)])->getStatusCode());
    $this->assertSame(400, $exchange(['code' => $code, 'code_verifier' => $verifier])->getStatusCode(), 'A code is single use, even after a failed attempt');

    // Right code and verifier: the session, with the CPR masked.
    $code = $handoff->issue($id, $challenge);
    $response = $exchange(['code' => $code, 'code_verifier' => $verifier]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $data = json_decode((string) $response->getContent(), TRUE)['data'];
    $this->assertSame($id, $data['id']);
    $this->assertStringNotContainsString('0101001234', (string) $response->getContent());
    $this->assertStringNotContainsString('ID.TOKEN.SECRET', (string) $response->getContent());
    // Replay of a used code fails.
    $this->assertSame(400, $exchange(['code' => $code, 'code_verifier' => $verifier])->getStatusCode());
    // Garbage is a plain 400.
    $this->assertSame(400, $exchange(['code' => 'nope'])->getStatusCode());

    // The current session is read only through the Authorization header.
    $bearer = Request::create('/api/mitid/session');
    $bearer->headers->set('Authorization', 'Bearer ' . $id);
    $this->assertSame(200, $controller->currentSession($bearer)->getStatusCode());
    $this->assertSame(401, $controller->currentSession(Request::create('/api/mitid/session', 'GET', ['session' => $id]))->getStatusCode());
  }

  /**
   * A login returning to the SPA needs a challenge; backend pages do not.
   */
  public function testLoginRequiresChallengeForTheSpa(): void {
    putenv('CORS_ALLOW_ORIGIN=https://frontend.example');
    $controller = MitIdController::create($this->container);
    $login = function (array $query) use ($controller) {
      $request = Request::create('/mitid/login', 'GET', $query);
      $request->setSession(new Session(new MockArraySessionStorage()));
      $this->container->get('request_stack')->push($request);
      try {
        return $controller->login($request);
      }
      finally {
        $this->container->get('request_stack')->pop();
      }
    };
    $spa = $login(['return_url' => 'https://frontend.example/demo']);
    $this->assertSame(400, $spa->getStatusCode());
    $this->assertSame(400, $login(['return_url' => 'https://frontend.example/demo', 'client_challenge' => 'short'])->getStatusCode());
    $ok = $login(['return_url' => 'https://frontend.example/demo', 'client_challenge' => str_repeat('A', 43)]);
    $this->assertSame(302, $ok->getStatusCode());
    // A backend page on this origin uses the Drupal session: no challenge.
    $this->assertSame(302, $login(['return_url' => '/parent-approval/1/2/x'])->getStatusCode());
    putenv('CORS_ALLOW_ORIGIN');
  }

}
