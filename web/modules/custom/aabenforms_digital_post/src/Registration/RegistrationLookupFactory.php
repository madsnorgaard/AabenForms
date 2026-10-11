<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Registration;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Picks the registration lookup implementation from config.
 *
 * Config key aabenforms_digital_post.settings:registration_lookup, "demo"
 * (default) or "live". A missing key means "demo" (sites updated before the
 * key existed). Any other value selects the live stub, which answers UNKNOWN
 * - the fail-safe path - rather than throwing and taking every send down.
 */
final class RegistrationLookupFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DemoRegistrationLookup $demo,
    private readonly LiveRegistrationLookup $live,
  ) {
  }

  /**
   * Returns the configured lookup.
   */
  public function create(): DigitalPostRegistrationLookupInterface {
    $mode = $this->configFactory->get('aabenforms_digital_post.settings')->get('registration_lookup');
    if ($mode === NULL || $mode === RegistrationStatus::MODE_DEMO) {
      return $this->demo;
    }
    return $this->live;
  }

}
