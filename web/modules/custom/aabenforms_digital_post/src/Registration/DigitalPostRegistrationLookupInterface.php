<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Registration;

use Drupal\aabenforms_digital_post\DigitalPost\Recipient;

/**
 * Looks up whether a recipient is registered for or exempt from Digital Post.
 *
 * The real source is the Digital Post kontaktregister (NgDP / SF1601
 * "er modtager tilmeldt Digital Post"), which needs Serviceplatformen
 * certificates (issue #76). Implementations must never throw for an
 * undecidable recipient: they return RegistrationStatus::UNKNOWN, which the
 * sender treats as "send so the letter reaches the citizen either way".
 *
 * @api
 */
interface DigitalPostRegistrationLookupInterface {

  /**
   * Looks up the recipient's Digital Post registration status.
   *
   * @param \Drupal\aabenforms_digital_post\DigitalPost\Recipient $recipient
   *   The recipient. CVR recipients are always registered (businesses are
   *   obliged to have Digital Post and cannot be exempted).
   *
   * @return \Drupal\aabenforms_digital_post\Registration\RegistrationStatus
   *   The status, never NULL. UNKNOWN when it cannot be decided.
   */
  public function lookup(Recipient $recipient): RegistrationStatus;

  /**
   * The mode label of this implementation: "demo" or "live".
   */
  public function mode(): string;

}
