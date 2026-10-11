<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Per-route, per-IP rate limiting for the public API surface (#142).
 *
 * Runs right after routing and looks the matched route up in
 * aabenforms_core.rate_limits. An entry names an exact route or a prefix
 * ending in '*' (jsonapi.*); a prefix entry shares one bucket across every
 * route it matches. Over the limit the request is answered with a 429 before
 * any controller runs. Controllers with their own flood checks (submit,
 * prefill, revoke, logout) keep them; this is the generic outer layer.
 */
class RouteRateLimitSubscriber implements EventSubscriberInterface {

  /**
   * The flood event name all route buckets are registered under.
   */
  public const FLOOD_EVENT = 'aabenforms_core.route_rate_limit';

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // The router listener runs at 32; the route name is known from here on.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Rejects the request with a 429 when its route bucket is exhausted.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if (!is_string($route) || $route === '') {
      return;
    }
    $config = $this->configFactory->get('aabenforms_core.rate_limits');
    if (!$config->get('enabled')) {
      return;
    }
    $rule = $this->matchRule($route, (array) $config->get('routes'));
    if ($rule === NULL) {
      return;
    }

    $identifier = $rule['route'] . ':' . $request->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $rule['limit'], $rule['window'], $identifier)) {
      $event->setResponse(new JsonResponse(
        ['error' => 'Too many requests, please try again later'],
        429,
        [
          'Retry-After' => (string) $rule['window'],
          'Cache-Control' => 'no-store',
        ],
      ));
      return;
    }
    $this->flood->register(self::FLOOD_EVENT, $rule['window'], $identifier);
  }

  /**
   * Finds the rule for a route: an exact entry wins, then the longest prefix.
   *
   * @param string $route
   *   The matched route name.
   * @param array $rules
   *   The configured rules.
   *
   * @return array|null
   *   The rule with integer limit and window, or NULL when none applies.
   */
  protected function matchRule(string $route, array $rules): ?array {
    $best = NULL;
    $best_length = -1;
    foreach ($rules as $rule) {
      $pattern = (string) ($rule['route'] ?? '');
      $limit = (int) ($rule['limit'] ?? 0);
      $window = (int) ($rule['window'] ?? 0);
      if ($pattern === '' || $limit < 1 || $window < 1) {
        continue;
      }
      $normalized = ['route' => $pattern, 'limit' => $limit, 'window' => $window];
      if ($pattern === $route) {
        return $normalized;
      }
      if (str_ends_with($pattern, '*')) {
        $prefix = substr($pattern, 0, -1);
        if (str_starts_with($route, $prefix) && strlen($prefix) > $best_length) {
          $best = $normalized;
          $best_length = strlen($prefix);
        }
      }
    }
    return $best;
  }

}
