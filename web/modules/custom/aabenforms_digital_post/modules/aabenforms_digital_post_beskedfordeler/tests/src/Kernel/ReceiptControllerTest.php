<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post_beskedfordeler\Kernel;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_digital_post_beskedfordeler\Controller\ReceiptController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the hardened Beskedfordeler receipt intake endpoint.
 *
 * @group aabenforms_digital_post
 */
class ReceiptControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'node',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'domain_access',
    'modeler_api',
    'eca',
    'webform',
    'aabenforms_core',
    'aabenforms_case',
    'aabenforms_digital_post',
    'aabenforms_digital_post_beskedfordeler',
  ];

  /**
   * The controller under test.
   */
  protected ReceiptController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aabenforms_case');
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log', 'aabenforms_trace']);
    $this->installConfig(['aabenforms_digital_post_beskedfordeler']);
    \Drupal::moduleHandler()->loadInclude('aabenforms_digital_post_beskedfordeler', 'install');
    _aabenforms_digital_post_beskedfordeler_install_log_columns();

    $this->controller = ReceiptController::create($this->container);
  }

  /**
   * With no secret configured the endpoint rejects everything (fail-closed).
   */
  public function testFailsClosedWithoutSecret(): void {
    $response = $this->controller->receive($this->request(['transaction_id' => 't', 'status' => 'delivered'], 'anything'));
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * A wrong secret is rejected.
   */
  public function testWrongSecretRejected(): void {
    $this->configureSecret('right-secret');
    $response = $this->controller->receive($this->request(['transaction_id' => 't', 'status' => 'delivered'], 'wrong-secret'));
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * A malformed payload with the right secret is a 400.
   */
  public function testMalformedPayload(): void {
    $this->configureSecret('s');
    $request = $this->request(['nope' => TRUE], 's');
    $this->assertSame(400, $this->controller->receive($request)->getStatusCode());
  }

  /**
   * A valid, authenticated receipt reconciles and returns 200 with the state.
   */
  public function testValidReceiptReconciles(): void {
    $this->configureSecret('s');
    $this->seedCase('tx-ok');
    $response = $this->controller->receive($this->request([
      'transaction_id' => 'tx-ok',
      'status' => 'delivered',
    ], 's'));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('delivered', (string) $response->getContent());
  }

  /**
   * An unknown transaction (right secret) is a 404.
   */
  public function testUnknownTransactionIs404(): void {
    $this->configureSecret('s');
    $response = $this->controller->receive($this->request([
      'transaction_id' => 'tx-missing',
      'status' => 'delivered',
    ], 's'));
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Configures the shared secret in a key entry and points config at it.
   */
  private function configureSecret(string $secret): void {
    Key::create([
      'id' => 'beskedfordeler_secret',
      'label' => 'Test secret',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => $secret],
    ])->save();
    $this->config('aabenforms_digital_post_beskedfordeler.settings')
      ->set('receipt_secret_key', 'beskedfordeler_secret')
      ->save();
  }

  /**
   * Builds a POST request with a JSON body and the secret header.
   */
  private function request(array $body, string $secret): Request {
    $request = Request::create('/api/digital-post/receipt', 'POST', [], [], [], [], json_encode($body));
    $request->headers->set('X-Beskedfordeler-Secret', $secret);
    return $request;
  }

  /**
   * Seeds a case correlated to a transaction id.
   */
  private function seedCase(string $transactionId): void {
    AabenformsCase::create([
      'case_type' => 'merudgifter',
      'status' => 'afgoerelse',
      'digital_post_tx' => $transactionId,
      'digital_post_receipt_status' => 'pending',
    ])->save();
  }

}
