<?php

namespace Drupal\aabenforms_core\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Circuit breaker for Serviceplatformen services (#72).
 *
 * During an upstream outage every lookup would otherwise wait out its own
 * timeouts and retries, holding a PHP-FPM worker each, until the pool is
 * exhausted and the whole site stops answering. After a run of availability
 * failures the circuit opens and calls fail at once for a cool-down period.
 * When it ends, calls flow again on probation: the first failure reopens the
 * circuit straight away, the first success closes it.
 *
 * State lives in the expirable key-value store, so it is shared by every
 * worker and survives cache clears; expiry does the bookkeeping. Each service
 * has its own circuit, so a CVR outage never blocks CPR lookups.
 */
class ServiceplatformenCircuitBreaker {

  /**
   * Availability failures in a row that open the circuit.
   */
  public const FAILURE_THRESHOLD = 5;

  /**
   * Seconds a failure run is remembered; a quiet minute starts a new count.
   */
  public const FAILURE_WINDOW = 60;

  /**
   * Seconds an open circuit fails fast before letting calls through again.
   */
  public const COOLDOWN = 30;

  /**
   * Seconds after reopening during which one failure reopens the circuit.
   */
  public const PROBATION = 300;

  /**
   * The breaker state.
   */
  protected KeyValueStoreExpirableInterface $store;

  public function __construct(KeyValueExpirableFactoryInterface $key_value_expirable) {
    $this->store = $key_value_expirable->get('aabenforms_core.serviceplatformen_circuit');
  }

  /**
   * Whether calls to the service may go out now.
   */
  public function isAvailable(string $service): bool {
    return !$this->store->has("open:{$service}");
  }

  /**
   * Records a failure that says the service is down or overloaded.
   *
   * SOAP faults and client errors mean the service answered, so callers
   * record only connection errors, timeouts and 408/503/504.
   *
   * @return bool
   *   TRUE when this failure opened the circuit.
   */
  public function recordFailure(string $service): bool {
    if ($this->store->has("probation:{$service}")) {
      $this->open($service);
      return TRUE;
    }
    $failures = (int) $this->store->get("failures:{$service}", 0) + 1;
    if ($failures >= self::FAILURE_THRESHOLD) {
      $this->open($service);
      return TRUE;
    }
    $this->store->setWithExpire("failures:{$service}", $failures, self::FAILURE_WINDOW);
    return FALSE;
  }

  /**
   * Records an answer from the service, which closes the circuit.
   */
  public function recordSuccess(string $service): void {
    $this->store->deleteMultiple(["failures:{$service}", "probation:{$service}"]);
  }

  /**
   * Opens the circuit and puts the service on probation after the cool-down.
   */
  protected function open(string $service): void {
    $this->store->setWithExpire("open:{$service}", TRUE, self::COOLDOWN);
    $this->store->setWithExpire("probation:{$service}", TRUE, self::COOLDOWN + self::PROBATION);
    $this->store->delete("failures:{$service}");
  }

}
