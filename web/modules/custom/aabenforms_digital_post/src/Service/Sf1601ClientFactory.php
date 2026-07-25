<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Service;

use Drupal\aabenforms_digital_post\Exception\DigitalPostException;
use Drupal\aabenforms_digital_post\TestMode\FakeSendDatabaseLogger;
use Drupal\aabenforms_digital_post\TestMode\LiveSf1601Client;
use Drupal\aabenforms_digital_post\TestMode\WireMockSoapClient;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Picks the Sf1601 transport implementation based on config.test_mode.
 *
 * The fake_db and wiremock modes are the offline/mock rails; live_test and live
 * route to the real Serviceplatformen exttest/prod endpoints via the same
 * LiveSf1601Client (it reads test_mode to pick the endpoint).
 */
final class Sf1601ClientFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FakeSendDatabaseLogger $fakeDbClient,
    private readonly WireMockSoapClient $wireMockClient,
    private readonly LiveSf1601Client $liveClient,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function create(): Sf1601ClientInterface {
    $mode = (string) $this->configFactory
      ->get('aabenforms_digital_post.settings')
      ->get('test_mode');
    return match ($mode) {
      // Fail closed on empty/unset: a wiped or missing config must NEVER
      // silently fall back to the fake transport (that would turn undelivered
      // official letters into fake successes). An explicit mode is required.
      '' => throw new DigitalPostException('Digital Post test_mode is not configured; refusing to send. Set it explicitly (fake_db in dev).'),
      'fake_db' => $this->fakeDbClient,
      'wiremock' => $this->wireMockClient,
      'live_test', 'live' => $this->liveClient,
      default => throw new DigitalPostException(sprintf('Unknown test_mode "%s".', $mode)),
    };
  }

}
