<?php

declare(strict_types=1);

namespace Drupal\aabenforms_mitid\Service;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Refuses mock MitID settings anywhere but development and the demo site.
 *
 * The settings shipped in config/sync point at the local mock IdP (plain
 * http endpoints and a placeholder client secret). That is right for DDEV
 * and for the public demo site, but a kommune deployment that forgot to set
 * its real NemLog-in / broker settings must not quietly authenticate
 * citizens against them. Outside DDEV such settings are allowed only when
 * the site is explicitly marked as a demo (AABENFORMS_DEMO_SITE=1).
 */
class MitIdConfigGuard {

  /**
   * The placeholder secret shipped for the local mock IdP.
   */
  public const PLACEHOLDER_SECRET = 'aabenforms-backend-secret-change-in-production';

  /**
   * Endpoint settings that must use https in production.
   */
  private const ENDPOINTS = ['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'issuer'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected DemoEnvironment $demoEnvironment,
  ) {
  }

  /**
   * Machine codes for every mock indicator in the active settings.
   *
   * @return string[]
   *   Empty when the settings look like a real deployment.
   */
  public function problems(): array {
    $config = $this->configFactory->get('aabenforms_mitid.settings');
    $problems = [];
    $secret = (string) $config->get('client_secret');
    if ($secret === '' || $secret === self::PLACEHOLDER_SECRET) {
      $problems[] = 'placeholder_client_secret';
    }
    foreach (self::ENDPOINTS as $key) {
      $value = (string) $config->get($key);
      if ($value !== '' && !str_starts_with(strtolower($value), 'https://')) {
        $problems[] = 'insecure_' . $key;
      }
    }
    return $problems;
  }

  /**
   * Whether MitID login may run with the current settings here.
   */
  public function isAllowed(): bool {
    return $this->problems() === [] || $this->demoEnvironment->isDemoAllowed();
  }

}
