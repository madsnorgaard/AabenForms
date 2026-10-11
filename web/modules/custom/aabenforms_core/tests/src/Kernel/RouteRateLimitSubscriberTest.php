<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the generic per-route rate limit (#142).
 *
 * @coversDefaultClass \Drupal\aabenforms_core\EventSubscriber\RouteRateLimitSubscriber
 * @group aabenforms_core
 */
class RouteRateLimitSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'aabenforms_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('aabenforms_core.rate_limits')->setData([
      'enabled' => TRUE,
      'routes' => [
        ['route' => 'aabenforms_mitid.login', 'limit' => 3, 'window' => 60],
        ['route' => 'jsonapi.*', 'limit' => 2, 'window' => 120],
      ],
    ])->save();
  }

  /**
   * Dispatches the REQUEST event for a routed request and returns its answer.
   *
   * @param string $route
   *   The matched route name.
   * @param string $ip
   *   The client IP.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   The short-circuit response, or NULL when the request may proceed.
   */
  protected function hit(string $route, string $ip = '203.0.113.7'): ?Response {
    $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    $request->attributes->set('_route', $route);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    // The router (priority 32) has already set _route; call the listener
    // directly rather than re-running routing in a kernel test.
    $this->container->get('aabenforms_core.route_rate_limit_subscriber')->onRequest($event);
    return $event->getResponse();
  }

  /**
   * The listener is registered on REQUEST right after the router.
   */
  public function testRegisteredAfterRouting(): void {
    $dispatcher = $this->container->get('event_dispatcher');
    $subscriber = $this->container->get('aabenforms_core.route_rate_limit_subscriber');
    $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$subscriber, 'onRequest']);
    $this->assertSame(30, $priority);
  }

  /**
   * A configured route gets a 429 with Retry-After after its limit.
   */
  public function testConfiguredRouteIsLimited(): void {
    for ($i = 0; $i < 3; $i++) {
      $this->assertNull($this->hit('aabenforms_mitid.login'), "Request $i is allowed.");
    }
    $response = $this->hit('aabenforms_mitid.login');
    $this->assertNotNull($response);
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('60', $response->headers->get('Retry-After'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame(['error' => 'Too many requests, please try again later'], json_decode((string) $response->getContent(), TRUE));

    // The bucket is per client IP.
    $this->assertNull($this->hit('aabenforms_mitid.login', '198.51.100.1'));
  }

  /**
   * A route without an entry is never limited.
   */
  public function testUnconfiguredRouteIsUntouched(): void {
    for ($i = 0; $i < 10; $i++) {
      $this->assertNull($this->hit('system.401'));
    }
  }

  /**
   * A prefix entry matches every route under it with one shared bucket.
   */
  public function testPrefixMatch(): void {
    $this->assertNull($this->hit('jsonapi.webform--webform.collection'));
    $this->assertNull($this->hit('jsonapi.node--page.individual'));
    $response = $this->hit('jsonapi.user--user.collection');
    $this->assertNotNull($response);
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('120', $response->headers->get('Retry-After'));
    // 'jsonapi' itself (no dot) is not under the 'jsonapi.' prefix.
    $this->assertNull($this->hit('jsonapi'));
  }

  /**
   * The master switch disables every limit.
   */
  public function testDisabled(): void {
    $this->config('aabenforms_core.rate_limits')->set('enabled', FALSE)->save();
    for ($i = 0; $i < 10; $i++) {
      $this->assertNull($this->hit('aabenforms_mitid.login'));
    }
  }

  /**
   * The shipped defaults validate against the config schema.
   */
  public function testShippedDefaultsMatchSchema(): void {
    $this->config('aabenforms_core.rate_limits')->delete();
    // Strict schema checking in kernel tests fails the install on a mismatch.
    $this->installConfig(['aabenforms_core']);
    $routes = $this->config('aabenforms_core.rate_limits')->get('routes');
    $this->assertNotEmpty($routes);
    $this->assertContains('jsonapi.*', array_column($routes, 'route'));
  }

}
