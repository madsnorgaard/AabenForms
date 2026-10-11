<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Service;

use Drupal\aabenforms_digital_post\Audit\AuditEmitterInterface;
use Drupal\aabenforms_digital_post\DigitalPost\DeliveryChannel;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Registration\DigitalPostRegistrationLookupInterface;
use Drupal\aabenforms_digital_post\Registration\RegistrationStatus;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The public entry point of aabenforms_digital_post.
 *
 * Callers build a DigitalPost DTO and call ->send(). The orchestrator
 * generates a transaction id, hands the DTO to the configured transport,
 * and emits an audit event with the typed Result.
 *
 * This class intentionally knows nothing about MeMo XML, certificates,
 * or SOAP. Those concerns live below the Sf1601ClientInterface boundary.
 *
 * Channel selection (Digital Post fritagelse, issue #242): before sending to
 * a CPR recipient the sender asks the registration lookup whether the citizen
 * is tilmeldt or fritaget, then routes through the digital transport, the
 * physical (print) transport, or both when the status is unknown - see
 * DeliveryChannel::select(). Callers and flows do not change; the channel
 * actually used rides back on Result::$deliveryChannel.
 */
final class DigitalPostSender implements DigitalPostSenderInterface {

  public function __construct(
    private readonly Sf1601ClientInterface $client,
    private readonly TransactionIdGenerator $transactionIdGenerator,
    private readonly AuditEmitterInterface $audit,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly DigitalPostRegistrationLookupInterface $registrationLookup,
    private readonly Sf1601ClientInterface $physicalTransport,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function send(DigitalPost $post): Result {
    $transactionId = $post->meta['transaction_id'] ?? $this->transactionIdGenerator->generate();
    if (!is_string($transactionId) || $transactionId === '') {
      $transactionId = $this->transactionIdGenerator->generate();
    }
    $this->logger->debug('Digital Post send starting: tx=@tx mode=@m', [
      '@tx' => $transactionId,
      '@m' => $this->client->modeLabel(),
    ]);
    $registration = $this->lookupRegistration($post);
    $channel = DeliveryChannel::select($post, $registration);
    $result = $this->dispatch($post, $transactionId, $channel, $registration);
    $transportLabel = $this->transportLabel($channel);
    // Three-valued audit outcome. A live 2xx is `pending` (accepted, not
    // delivered) - it must NOT be audited as a failure, or the trace/dashboard
    // shows every accepted live send as failed and the flow looks stuck.
    $outcome = $result->isSuccess() ? 'success' : ($result->isPending() ? 'pending' : 'failure');
    $eventTypes = [
      'success' => 'digital_post_sent',
      'pending' => 'digital_post_pending',
      'failure' => 'digital_post_failed',
    ];
    $labels = [
      'success' => 'Digital Post sent',
      'pending' => 'Digital Post accepted (awaiting receipt)',
      'failure' => 'Digital Post failed',
    ];
    $this->audit->emit(
      eventType: $eventTypes[$outcome],
      identifier: $post->recipient->identifierHash(),
      message: sprintf(
        '%s via %s: %s',
        $labels[$outcome],
        $transportLabel,
        $result->message,
      ),
      status: $outcome,
      context: array_merge($result->auditContext(), [
        'subject' => $post->subject,
        'recipient_type' => $post->recipient->type,
        'sender_cvr' => $post->sender->cvr,
        'attachment_count' => count($post->attachments),
        'mode' => $transportLabel,
        'registration_mode' => $registration->mode,
        'registration_reason' => $registration->reason,
      ]),
    );
    if ($result->manualFollowUp) {
      // A separate, filterable audit event so a caseworker queue / DPO report
      // can find every letter that needs a human to confirm delivery.
      $this->audit->emit(
        eventType: 'digital_post_manual_followup',
        identifier: $post->recipient->identifierHash(),
        message: sprintf(
          'Manual follow-up required: registration %s (%s), channel %s, outcome %s.',
          $registration->status,
          $registration->reason,
          $result->deliveryChannel,
          $outcome,
        ),
        status: 'warning',
        context: [
          'transaction_id' => $result->transactionId,
          'delivery_channel' => $result->deliveryChannel,
          'registration_status' => $registration->status,
          'registration_mode' => $registration->mode,
          'reason_code' => $result->reasonCode,
        ],
      );
    }
    return $result;
  }

  /**
   * Asks the registration lookup, treating any error as UNKNOWN.
   *
   * CVR recipients and NemSMS skip the lookup: neither has a fritagelse.
   */
  private function lookupRegistration(DigitalPost $post): RegistrationStatus {
    $mode = $this->registrationLookup->mode();
    if ($post->type === DigitalPost::TYPE_NEM_SMS) {
      return new RegistrationStatus(RegistrationStatus::REGISTERED, $mode, 'not_applicable_nemsms');
    }
    try {
      return $this->registrationLookup->lookup($post->recipient);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Digital Post registration lookup failed; treating as unknown: @msg', ['@msg' => $e->getMessage()]);
      return RegistrationStatus::unknown($mode, 'lookup_error');
    }
  }

  /**
   * Sends through the transport(s) for the chosen channel.
   *
   * For digital_and_physical the physical leg is decisive: it is the only
   * channel guaranteed to reach a citizen whatever their registration, so the
   * combined result is a success only when the print job was queued. A
   * physical-leg failure fails the whole send (the flow keeps the case open
   * and does not start the klagefrist) even if the digital leg went out.
   */
  private function dispatch(DigitalPost $post, string $transactionId, string $channel, RegistrationStatus $registration): Result {
    $unknown = $registration->isUnknown() && $channel === DeliveryChannel::DIGITAL_AND_PHYSICAL;
    if ($channel === DeliveryChannel::DIGITAL) {
      return $this->client->send($post, $transactionId)
        ->withDelivery($channel, $registration->status, FALSE);
    }
    if ($channel === DeliveryChannel::PHYSICAL) {
      $physical = $this->physicalTransport->send($post, $transactionId);
      return $physical->withDelivery($channel, $registration->status, !$physical->isSuccess() && !$physical->isPending());
    }

    $digital = $this->client->send($post, $transactionId);
    $physical = $this->physicalTransport->send($post, $this->physicalLegTransactionId($transactionId));
    $message = sprintf(
      'Registration unknown: sent digitally (%s) and as a physical letter (%s). %s',
      $digital->status,
      $physical->status,
      $physical->message,
    );
    if ($physical->isSuccess() || $physical->isPending()) {
      $combined = $physical->isSuccess()
        ? Result::success($transactionId, $message, $digital->rawResponse)
        : Result::pending($transactionId, $message, $digital->rawResponse);
    }
    else {
      $combined = Result::failure(
        $transactionId,
        $physical->reasonCode ?? Result::REASON_UNKNOWN,
        $message,
        $digital->rawResponse,
      );
    }
    return $combined->withDelivery($channel, $registration->status, $unknown);
  }

  /**
   * The physical leg's own transaction id (the log keys are unique).
   *
   * Deterministic, so a re-fired flow hits the same print job (idempotent).
   */
  private function physicalLegTransactionId(string $transactionId): string {
    $candidate = $transactionId . '-print';
    return strlen($candidate) <= 64 ? $candidate : 'print_' . substr(hash('sha256', $transactionId), 0, 40);
  }

  /**
   * Transport label for audit/logging, per channel.
   */
  private function transportLabel(string $channel): string {
    return match ($channel) {
      DeliveryChannel::PHYSICAL => $this->physicalTransport->modeLabel(),
      DeliveryChannel::DIGITAL_AND_PHYSICAL => $this->client->modeLabel() . '+' . $this->physicalTransport->modeLabel(),
      default => $this->client->modeLabel(),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function testMode(): string {
    return $this->client->modeLabel();
  }

}
