<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\TestMode;

use Drupal\aabenforms_digital_post\DigitalPost\DeliveryChannel;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Service\Sf1601ClientInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * DEMO physical-post transport: records a print job, prints nothing.
 *
 * For a recipient who is fritaget for Digital Post the letter must go out as
 * a physical letter. NgDP offers this through its print channel; that
 * integration does not exist yet. This transport only records the would-be
 * print job in {aabenforms_digital_post_log} with channel "physical" and
 * status "queued_print", so the demo, the evidence trail and the citizen's
 * session inbox can show "sendt som fysisk brev". Nothing is printed or
 * posted.
 *
 * Fail closed: in a live Digital Post mode (live_test / live) this transport
 * refuses with REASON_PRINT_UNAVAILABLE and records the refusal. Reporting a
 * DEMO print job as a success there would tell a real case that a real
 * citizen was notified when nobody was - the case would close and the
 * klagefrist would start on a letter that never left the building.
 *
 * Idempotent on transaction_id: a re-fired flow finds the existing print job
 * and does not queue a second letter.
 */
final class PhysicalPostTransport implements Sf1601ClientInterface {

  /**
   * Log-table status of a recorded (DEMO) print job.
   */
  public const STATUS_QUEUED_PRINT = 'queued_print';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function send(DigitalPost $post, string $transactionId): Result {
    $testMode = (string) $this->configFactory->get('aabenforms_digital_post.settings')->get('test_mode');
    if (in_array($testMode, ['live', 'live_test'], TRUE)) {
      $result = Result::failure(
        transactionId: $transactionId,
        reasonCode: Result::REASON_PRINT_UNAVAILABLE,
        message: 'Physical post (print channel) is not integrated; the letter must be posted manually.',
      );
      $this->record($post, $transactionId, Result::FAILURE, $result->reasonCode, 'print_unavailable');
      $this->logger->warning('Physical post refused in @mode: no print integration (tx=@tx). Manual posting required.', [
        '@mode' => $testMode,
        '@tx' => $transactionId,
      ]);
      return $result;
    }

    if ($this->exists($transactionId)) {
      return Result::success(
        transactionId: $transactionId,
        message: 'DEMO: print job already recorded (idempotent re-send).',
        rawResponse: 'physical_demo:existing',
      );
    }

    if (!$this->record($post, $transactionId, self::STATUS_QUEUED_PRINT, NULL, 'physical_demo:queued_print')) {
      return Result::failure(
        transactionId: $transactionId,
        reasonCode: Result::REASON_TRANSPORT,
        message: 'DEMO: print job could not be recorded.',
      );
    }
    $this->logger->info('DEMO physical post: print job recorded (tx=@tx).', ['@tx' => $transactionId]);
    return Result::success(
      transactionId: $transactionId,
      message: 'DEMO: print job recorded as a physical letter (no real print integration).',
      rawResponse: 'physical_demo:queued_print',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function modeLabel(): string {
    return 'physical_demo';
  }

  /**
   * Whether a log row already exists for the transaction id.
   */
  private function exists(string $transactionId): bool {
    try {
      return (bool) $this->database->select('aabenforms_digital_post_log', 'l')
        ->fields('l', ['id'])
        ->condition('transaction_id', $transactionId)
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Writes the print job (or refusal) to the log. Returns FALSE on failure.
   */
  private function record(DigitalPost $post, string $transactionId, string $status, ?string $reasonCode, string $response): bool {
    $payload = [
      'recipient' => [
        'type' => $post->recipient->type,
        'identifier_hash' => $post->recipient->identifierHash(),
      ],
      'sender' => ['cvr' => $post->sender->cvr, 'name' => $post->sender->name],
      'subject' => $post->subject,
      'body' => $post->body,
      'type' => DigitalPost::TYPE_FYSISK_POST,
      'requested_type' => $post->type,
      'meta' => $post->meta,
      'demo' => TRUE,
      'note' => 'DEMO: no print integration. A real deployment hands this to the NgDP print channel.',
    ];
    try {
      $this->database->insert('aabenforms_digital_post_log')
        ->fields([
          'transaction_id' => $transactionId,
          'mode' => $this->modeLabel(),
          'channel' => DeliveryChannel::PHYSICAL,
          'recipient_type' => $post->recipient->type,
          'recipient_identifier_hash' => $post->recipient->identifierHash(),
          'sender_cvr' => $post->sender->cvr,
          'subject' => mb_substr($post->subject, 0, 255),
          'status' => $status,
          'reason_code' => $reasonCode,
          'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
          'response' => $response,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      Cache::invalidateTags(['aabenforms_dashboard:activity']);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Physical post log write failed (tx=@tx): @msg', [
        '@tx' => $transactionId,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
