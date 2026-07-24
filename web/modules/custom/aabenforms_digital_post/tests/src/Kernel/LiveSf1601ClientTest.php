<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Kernel;

use Drupal\aabenforms_digital_post\Certificate\CertificateLocatorInterface;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\TestMode\LiveSf1601Client;
use Drupal\KernelTests\KernelTestBase;
use ItkDev\Serviceplatformen\Service\SF1601\SF1601;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests the live SF1601 client's result mapping, logging and idempotency.
 *
 * The vendor SF1601 service is stubbed (no cert, no network) so the test
 * exercises the client's own logic: a 2xx is pending (never success), errors
 * fail with typed reasons, and a re-send with the same transaction id is a
 * no-op.
 *
 * @group aabenforms_digital_post
 */
class LiveSf1601ClientTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'domain_access',
    'aabenforms_core',
    'aabenforms_digital_post',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['aabenforms_digital_post']);
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
    $this->config('aabenforms_digital_post.settings')
      ->set('test_mode', 'live_test')
      ->set('sender_cvr', '12345678')
      ->save();
  }

  /**
   * A 2xx response yields a pending Result and a pending log row - not success.
   */
  public function testAcceptedIsPendingNeverSuccess(): void {
    $client = $this->clientReturning($this->responseWithStatus(200, '<receipt>ok</receipt>'));
    $result = $client->send($this->samplePost(), 'tx-accepted');

    $this->assertTrue($result->isPending());
    $this->assertFalse($result->isSuccess());
    $this->assertSame('pending', $this->logStatus('tx-accepted'));
  }

  /**
   * A 5xx response is a transient transport failure.
   */
  public function testServerErrorIsTransportFailure(): void {
    $client = $this->clientReturning($this->responseWithStatus(503, 'busy'));
    $result = $client->send($this->samplePost(), 'tx-5xx');

    $this->assertFalse($result->isPending());
    $this->assertSame(Result::FAILURE, $result->status);
    $this->assertSame(Result::REASON_TRANSPORT, $result->reasonCode);
    $this->assertSame('failure', $this->logStatus('tx-5xx'));
  }

  /**
   * A 4xx response is a permanent validation failure.
   */
  public function testClientErrorIsValidationFailure(): void {
    $client = $this->clientReturning($this->responseWithStatus(400, 'bad memo'));
    $result = $client->send($this->samplePost(), 'tx-4xx');

    $this->assertSame(Result::REASON_VALIDATION, $result->reasonCode);
  }

  /**
   * A thrown transport exception never escapes - it becomes a failure Result.
   */
  public function testExceptionBecomesFailure(): void {
    $service = $this->createMock(SF1601::class);
    $service->method('kombiPostAfsend')->willThrowException(new \RuntimeException('boom'));
    $client = $this->client($service);

    $result = $client->send($this->samplePost(), 'tx-throw');
    $this->assertSame(Result::FAILURE, $result->status);
    $this->assertSame(Result::REASON_TRANSPORT, $result->reasonCode);
  }

  /**
   * Re-sending the same transaction id does not re-post and writes one row.
   */
  public function testIdempotentReplay(): void {
    $client = $this->clientReturning($this->responseWithStatus(200, 'ok'));
    $client->send($this->samplePost(), 'tx-dupe');
    $second = $client->send($this->samplePost(), 'tx-dupe');

    $this->assertTrue($second->isPending());
    $count = (int) $this->container->get('database')
      ->select('aabenforms_digital_post_log', 'l')
      ->condition('transaction_id', 'tx-dupe')
      ->countQuery()->execute()->fetchField();
    $this->assertSame(1, $count);
  }

  /**
   * Builds a client whose stubbed SF1601 returns the given response.
   */
  private function clientReturning(ResponseInterface $response): LiveSf1601Client {
    $service = $this->createMock(SF1601::class);
    $service->method('kombiPostAfsend')->willReturn($response);
    return $this->client($service);
  }

  /**
   * Builds the client wired to the container with a stubbed service factory.
   */
  private function client(SF1601 $service): LiveSf1601Client {
    return new LiveSf1601Client(
      $this->container->get('database'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.aabenforms_digital_post'),
      $this->container->get('aabenforms_digital_post.memo_builder'),
      $this->container->get('config.factory'),
      $this->createMock(CertificateLocatorInterface::class),
      static fn (string $cvr, bool $testMode): SF1601 => $service,
    );
  }

  /**
   * A ResponseInterface stub with the given status and body.
   */
  private function responseWithStatus(int $status, string $body): ResponseInterface {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn($status);
    $response->method('getContent')->willReturn($body);
    return $response;
  }

  /**
   * The recorded status for a transaction id, or NULL.
   */
  private function logStatus(string $transactionId): ?string {
    $value = $this->container->get('database')
      ->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['status'])
      ->condition('transaction_id', $transactionId)
      ->execute()->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

  /**
   * A minimal valid DigitalPost.
   */
  private function samplePost(): DigitalPost {
    return new DigitalPost(
      Recipient::cpr('2512489996'),
      new Sender('12345678', 'Test Kommune'),
      'Afgørelse i din sag',
      '<p>Kommunen har truffet afgørelse.</p>',
    );
  }

}
