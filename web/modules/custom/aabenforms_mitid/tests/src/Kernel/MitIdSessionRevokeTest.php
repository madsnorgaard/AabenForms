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
    $body = MitIdController::create($this->container)->getSession($id)->getContent();
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

}
