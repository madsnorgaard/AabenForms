<?php

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aabenforms_core\Exception\ServiceplatformenException;
use Drupal\aabenforms_core\Service\ServiceplatformenCircuitBreaker;
use Drupal\aabenforms_core\Service\ServiceplatformenClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * An upstream outage fails fast instead of holding PHP-FPM workers (#72).
 *
 * @group aabenforms_core
 */
class ServiceplatformenCircuitBreakerTest extends KernelTestBase {

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
  ];

  /**
   * The mock HTTP handler; an unexpected call finds the queue empty.
   */
  protected MockHandler $mockHandler;

  /**
   * The client under test, wired to the real breaker.
   */
  protected ServiceplatformenClient $client;

  /**
   * The breaker, backed by the real expirable key-value store.
   */
  protected ServiceplatformenCircuitBreaker $breaker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('aabenforms_core.settings')
      ->set('serviceplatformen.urls', [
        'SF1520' => 'https://test.serviceplatformen.dk/sf1520',
        'SF1530' => 'https://test.serviceplatformen.dk/sf1530',
        'SF1601' => 'https://test.serviceplatformen.dk/sf1601',
      ])
      ->save();

    $this->mockHandler = new MockHandler();
    $this->breaker = $this->container->get('aabenforms_core.serviceplatformen_circuit_breaker');
    $this->client = new ServiceplatformenClient(
      $this->container->get('config.factory'),
      $this->container->get('cache.default'),
      $this->container->get('logger.factory'),
      new Client(['handler' => HandlerStack::create($this->mockHandler)]),
      $this->breaker,
    );
  }

  /**
   * The container wires the breaker into the shared client.
   */
  public function testServiceIsWired(): void {
    $client = $this->container->get('aabenforms_core.serviceplatformen_client');
    $property = new \ReflectionProperty($client, 'circuitBreaker');
    $this->assertInstanceOf(ServiceplatformenCircuitBreaker::class, $property->getValue($client));
  }

  /**
   * After the threshold the circuit opens and no HTTP call goes out.
   */
  public function testOpensAfterRepeatedTimeoutsAndFailsFast(): void {
    for ($i = 0; $i < ServiceplatformenCircuitBreaker::FAILURE_THRESHOLD; $i++) {
      // One response per call proves timeouts are not retried.
      $this->mockHandler->append($this->timeout('sf1520'));
      $this->assertLookupFails('SF1520');
    }
    $this->assertCount(0, $this->mockHandler);
    $this->assertFalse($this->breaker->isAvailable('SF1520'));

    // Nothing is queued: a request would throw OutOfBoundsException, which
    // the client reports as "Unexpected error". Fast-fail says circuit open.
    $e = $this->assertLookupFails('SF1520');
    $this->assertStringContainsString('circuit open', $e->getMessage());
    $this->assertSame(503, $e->getCode());
    $this->assertFalse($e->isRetryable());

    // Each service has its own circuit.
    $this->assertTrue($this->breaker->isAvailable('SF1530'));
  }

  /**
   * A SOAP fault means the service answered, so it never opens the circuit.
   */
  public function testSoapFaultsDoNotCount(): void {
    $fault = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>Invalid CPR</faultstring></soap:Fault></soap:Body></soap:Envelope>';
    for ($i = 0; $i < ServiceplatformenCircuitBreaker::FAILURE_THRESHOLD + 1; $i++) {
      $this->mockHandler->append(new Response(200, [], $fault));
      $this->assertLookupFails('SF1520');
    }
    $this->assertTrue($this->breaker->isAvailable('SF1520'));
  }

  /**
   * A success clears the failure run.
   */
  public function testSuccessResetsTheCount(): void {
    for ($i = 0; $i < ServiceplatformenCircuitBreaker::FAILURE_THRESHOLD - 1; $i++) {
      $this->mockHandler->append($this->timeout('sf1520'));
      $this->assertLookupFails('SF1520');
    }
    $this->mockHandler->append(new Response(200, [], $this->personResponse()));
    $this->client->request('SF1520', 'PersonLookup', ['cpr' => '0101701234'], ['no_cache' => TRUE]);

    $this->mockHandler->append($this->timeout('sf1520'));
    $this->assertLookupFails('SF1520');
    $this->assertTrue($this->breaker->isAvailable('SF1520'));
  }

  /**
   * On probation after a cool-down, one failure reopens at once.
   */
  public function testProbationReopensOnFirstFailure(): void {
    for ($i = 0; $i < ServiceplatformenCircuitBreaker::FAILURE_THRESHOLD; $i++) {
      $this->breaker->recordFailure('SF1520');
    }
    // The cool-down ends.
    $this->container->get('keyvalue.expirable')
      ->get('aabenforms_core.serviceplatformen_circuit')
      ->delete('open:SF1520');
    $this->assertTrue($this->breaker->isAvailable('SF1520'));

    $this->assertTrue($this->breaker->recordFailure('SF1520'));
    $this->assertFalse($this->breaker->isAvailable('SF1520'));
  }

  /**
   * A Digital Post send is never retried: it could deliver the letter twice.
   */
  public function testDigitalPostIsNotRetried(): void {
    $this->mockHandler->append(
      new ConnectException('Connection refused', new Request('POST', 'https://test.serviceplatformen.dk/sf1601')),
      new Response(200, [], '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><SendMessageResponse><Status>OK</Status></SendMessageResponse></soap:Body></soap:Envelope>'),
    );
    $this->assertLookupFails('SF1601');
    $this->assertCount(1, $this->mockHandler);
  }

  /**
   * A refused connection on a lookup is still retried once.
   */
  public function testRefusedLookupIsRetried(): void {
    $this->mockHandler->append(
      new ConnectException('Connection refused', new Request('POST', 'https://test.serviceplatformen.dk/sf1520')),
      new Response(200, [], $this->personResponse()),
    );
    $result = $this->client->request('SF1520', 'PersonLookup', ['cpr' => '0101701234'], ['no_cache' => TRUE]);
    $this->assertSame('Jane', $result['person']['first_name']);
  }

  /**
   * Runs a call that must fail and returns its exception.
   */
  protected function assertLookupFails(string $service): ServiceplatformenException {
    try {
      $this->client->request($service, 'Op', ['cpr' => '0101701234'], ['no_cache' => TRUE]);
    }
    catch (ServiceplatformenException $e) {
      return $e;
    }
    $this->fail("$service call unexpectedly succeeded");
  }

  /**
   * A cURL operation timeout as Guzzle reports it.
   */
  protected function timeout(string $path): ConnectException {
    return new ConnectException(
      'cURL error 28: Operation timed out',
      new Request('POST', "https://test.serviceplatformen.dk/$path"),
      NULL,
      ['errno' => 28],
    );
  }

  /**
   * A minimal SF1520 answer.
   */
  protected function personResponse(): string {
    return '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><PersonLookupResponse><CPR>0101701234</CPR><FirstName>Jane</FirstName><LastName>Doe</LastName></PersonLookupResponse></soap:Body></soap:Envelope>';
  }

}
