<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Registration;

/**
 * Immutable answer from a Digital Post registration (kontaktregister) lookup.
 *
 * Three-valued on purpose. "unknown" is a first-class answer, not an error:
 * a lookup that could not decide (no live integration yet, a timeout, a
 * recipient outside the demo fixture) must never be read as "registered",
 * because that would silently send an exempt citizen's decision to a
 * mailbox they do not have.
 */
final class RegistrationStatus {

  public const REGISTERED = 'registered';
  public const EXEMPT = 'exempt';
  public const UNKNOWN = 'unknown';

  public const MODE_DEMO = 'demo';
  public const MODE_LIVE = 'live';

  /**
   * Constructs a RegistrationStatus.
   *
   * @param string $status
   *   One of REGISTERED, EXEMPT, UNKNOWN.
   * @param string $mode
   *   MODE_DEMO when the answer came from the demo fixture, MODE_LIVE when it
   *   came from (or was attempted against) a real kontaktregister.
   * @param string $reason
   *   A short machine-readable reason, e.g. "demo_fixture", "not_in_fixture",
   *   "live_not_integrated", "lookup_error". Never contains the CPR.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $mode,
    public readonly string $reason = '',
  ) {
    if (!in_array($status, [self::REGISTERED, self::EXEMPT, self::UNKNOWN], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Invalid registration status "%s".', $status));
    }
    if (!in_array($mode, [self::MODE_DEMO, self::MODE_LIVE], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Invalid registration lookup mode "%s".', $mode));
    }
  }

  /**
   * Builds an "unknown" answer (the fail-safe default).
   */
  public static function unknown(string $mode, string $reason): self {
    return new self(self::UNKNOWN, $mode, $reason);
  }

  /**
   * TRUE only when the register positively says the recipient is exempt.
   */
  public function isExempt(): bool {
    return $this->status === self::EXEMPT;
  }

  /**
   * TRUE only when the register positively says the recipient is registered.
   */
  public function isRegistered(): bool {
    return $this->status === self::REGISTERED;
  }

  /**
   * TRUE when the status could not be determined.
   */
  public function isUnknown(): bool {
    return $this->status === self::UNKNOWN;
  }

}
