<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\DigitalPost;

/**
 * Immutable result of a Digital Post send attempt.
 *
 * One of three outcomes: success, pending, or failure with a typed reason
 * code. `pending` exists for the live transport: a 2xx from
 * kombiPostAfsend() means the message was ACCEPTED for delivery, not that it
 * was delivered - the real delivery/failure arrives asynchronously as a
 * Beskedfordeler receipt. A live send therefore never returns success.
 */
final class Result {

  public const SUCCESS = 'success';
  public const PENDING = 'pending';
  public const FAILURE = 'failure';

  // Reason codes (failures only).
  public const REASON_CERT_INVALID = 'CERT_INVALID';
  public const REASON_RECIPIENT_UNKNOWN = 'RECIPIENT_UNKNOWN';
  public const REASON_RECIPIENT_NOT_REACHABLE = 'RECIPIENT_NOT_REACHABLE';
  public const REASON_QUOTA = 'QUOTA';
  public const REASON_TRANSPORT = 'TRANSPORT';
  public const REASON_VALIDATION = 'VALIDATION';
  public const REASON_UNKNOWN = 'UNKNOWN';
  // The physical print channel is not integrated in this mode (live/live_test):
  // the letter must be posted manually. Permanent, never retried.
  public const REASON_PRINT_UNAVAILABLE = 'PRINT_UNAVAILABLE';

  private function __construct(
    public readonly string $status,
    public readonly string $transactionId,
    public readonly ?string $reasonCode,
    public readonly string $message,
    public readonly ?string $rawResponse,
    public readonly string $deliveryChannel = DeliveryChannel::DIGITAL,
    public readonly string $registrationStatus = '',
    public readonly bool $manualFollowUp = FALSE,
  ) {
  }

  /**
   * Returns a copy carrying the delivery channel actually used.
   *
   * @param string $channel
   *   One of the DeliveryChannel constants.
   * @param string $registrationStatus
   *   The registration status the channel was chosen from (registered,
   *   exempt, unknown), or '' when no lookup applied.
   * @param bool $manualFollowUp
   *   TRUE when a caseworker must follow up (unknown status, or a physical
   *   leg that could not be queued).
   */
  public function withDelivery(string $channel, string $registrationStatus, bool $manualFollowUp): self {
    if (!in_array($channel, DeliveryChannel::ALL, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Invalid delivery channel "%s".', $channel));
    }
    return new self(
      status: $this->status,
      transactionId: $this->transactionId,
      reasonCode: $this->reasonCode,
      message: $this->message,
      rawResponse: $this->rawResponse,
      deliveryChannel: $channel,
      registrationStatus: $registrationStatus,
      manualFollowUp: $manualFollowUp,
    );
  }

  /**
   * Builds a success Result.
   */
  public static function success(string $transactionId, string $message = '', ?string $rawResponse = NULL): self {
    return new self(
      status: self::SUCCESS,
      transactionId: $transactionId,
      reasonCode: NULL,
      message: $message,
      rawResponse: $rawResponse,
    );
  }

  /**
   * Builds a pending Result: accepted for delivery, outcome not yet known.
   *
   * The live transport returns this on a 2xx from Serviceplatformen. The final
   * delivered/failed state is reconciled later from the asynchronous receipt.
   */
  public static function pending(string $transactionId, string $message = '', ?string $rawResponse = NULL): self {
    return new self(
      status: self::PENDING,
      transactionId: $transactionId,
      reasonCode: NULL,
      message: $message,
      rawResponse: $rawResponse,
    );
  }

  /**
   * Builds a failure Result with a typed reason code.
   */
  public static function failure(string $transactionId, string $reasonCode, string $message, ?string $rawResponse = NULL): self {
    return new self(
      status: self::FAILURE,
      transactionId: $transactionId,
      reasonCode: $reasonCode,
      message: $message,
      rawResponse: $rawResponse,
    );
  }

  /**
   * Whether this Result is a success.
   */
  public function isSuccess(): bool {
    return $this->status === self::SUCCESS;
  }

  /**
   * Whether this Result is pending (accepted, awaiting an async receipt).
   */
  public function isPending(): bool {
    return $this->status === self::PENDING;
  }

  /**
   * Audit-log-safe context array.
   *
   * Does NOT include the full rawResponse (which may carry PII in the
   * MeMo envelope); callers that want it can read it explicitly.
   */
  public function auditContext(): array {
    return [
      'status' => $this->status,
      'transaction_id' => $this->transactionId,
      'reason_code' => $this->reasonCode,
      'message' => $this->message,
      'delivery_channel' => $this->deliveryChannel,
      'registration_status' => $this->registrationStatus,
      'manual_followup' => $this->manualFollowUp,
    ];
  }

}
