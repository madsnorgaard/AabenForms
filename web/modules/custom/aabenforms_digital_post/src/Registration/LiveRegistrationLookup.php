<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Registration;

use Drupal\aabenforms_digital_post\DigitalPost\Recipient;

/**
 * Live kontaktregister lookup - NOT IMPLEMENTED (stub).
 *
 * TODO(#76): call the NgDP / SF1601 kontaktregister ("er modtager tilmeldt
 * Digital Post") over Serviceplatformen once the kommune's certificate and
 * serviceaftale are in place. Until then every CPR answers UNKNOWN, which the
 * sender handles fail-safe (digital AND physical, flagged for manual
 * follow-up). This class deliberately never returns REGISTERED or EXEMPT.
 */
final class LiveRegistrationLookup implements DigitalPostRegistrationLookupInterface {

  /**
   * {@inheritdoc}
   */
  public function lookup(Recipient $recipient): RegistrationStatus {
    if ($recipient->type === Recipient::TYPE_CVR) {
      // Businesses are obliged to receive Digital Post; no exemption exists.
      return new RegistrationStatus(RegistrationStatus::REGISTERED, RegistrationStatus::MODE_LIVE, 'cvr_always_registered');
    }
    return RegistrationStatus::unknown(RegistrationStatus::MODE_LIVE, 'live_not_integrated');
  }

  /**
   * {@inheritdoc}
   */
  public function mode(): string {
    return RegistrationStatus::MODE_LIVE;
  }

}
