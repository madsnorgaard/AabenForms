<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\DigitalPost;

use Drupal\aabenforms_digital_post\Registration\RegistrationStatus;

/**
 * Delivery channel vocabulary and the channel-selection rule.
 *
 * - digital: MeMo / SF1601 to the citizen's Digital Post mailbox.
 * - physical: a printed letter (NgDP print channel; DEMO here).
 * - digital_and_physical: both, used when the registration status is unknown
 *   so a decision reaches the citizen whatever their status is.
 *
 * Pure and stateless so the rule is unit-testable on its own.
 */
final class DeliveryChannel {

  public const DIGITAL = 'digital';
  public const PHYSICAL = 'physical';
  public const DIGITAL_AND_PHYSICAL = 'digital_and_physical';

  public const ALL = [
    self::DIGITAL,
    self::PHYSICAL,
    self::DIGITAL_AND_PHYSICAL,
  ];

  /**
   * Chooses the channel for a post given the recipient's registration status.
   *
   * Rules, in order:
   * 1. CVR recipients: digital (businesses cannot be exempted).
   * 2. An explicit "Fysisk Post" type: physical.
   * 3. NemSMS: digital (not a letter; registration does not apply).
   * 4. Registered: digital. Exempt: physical - this deliberately overrides a
   *    "Digital Post" type, because an exempt citizen has no mailbox and the
   *    letter would otherwise silently not arrive.
   * 5. Unknown: digital AND physical (fail-safe: the letter must arrive).
   */
  public static function select(DigitalPost $post, RegistrationStatus $registration): string {
    if ($post->recipient->type === Recipient::TYPE_CVR) {
      return self::DIGITAL;
    }
    if ($post->type === DigitalPost::TYPE_FYSISK_POST) {
      return self::PHYSICAL;
    }
    if ($post->type === DigitalPost::TYPE_NEM_SMS) {
      return self::DIGITAL;
    }
    return match ($registration->status) {
      RegistrationStatus::REGISTERED => self::DIGITAL,
      RegistrationStatus::EXEMPT => self::PHYSICAL,
      default => self::DIGITAL_AND_PHYSICAL,
    };
  }

  /**
   * TRUE when the channel includes a physical letter.
   */
  public static function includesPhysical(string $channel): bool {
    return $channel === self::PHYSICAL || $channel === self::DIGITAL_AND_PHYSICAL;
  }

}
