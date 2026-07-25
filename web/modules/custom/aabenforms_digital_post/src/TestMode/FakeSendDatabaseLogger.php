<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\TestMode;

use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Memo\MemoBuilder;
use Drupal\aabenforms_digital_post\Service\Sf1601ClientInterface;
use Drupal\aabenforms_digital_post\Service\TransactionIdGenerator;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Default Digital Post transport.
 *
 * Serialises the DigitalPost DTO to JSON (attachments referenced by
 * name+size+mime, not inlined - we never want to put megabytes into the
 * log table) and records one row in {aabenforms_digital_post_log}. Returns
 * a synthetic success Result immediately.
 *
 * This is the install default because it works on any Drupal 11 with
 * ZERO external configuration: no cert, no CVR, no endpoint. An admin
 * can install the module, submit a form, and see the would-be payload
 * land in the log table. That is the "plug-and-play on bare Drupal"
 * contract the plan demands.
 */
final class FakeSendDatabaseLogger implements Sf1601ClientInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TransactionIdGenerator $transactionIdGenerator,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MemoBuilder $memoBuilder,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function send(DigitalPost $post, string $transactionId): Result {
    $payload = [
      'recipient' => [
        'type' => $post->recipient->type,
        'identifier_hash' => $post->recipient->identifierHash(),
      ],
      'sender' => [
        'cvr' => $post->sender->cvr,
        'name' => $post->sender->name,
      ],
      'subject' => $post->subject,
      'body' => $post->body,
      'type' => $post->type,
      'attachments' => array_map(static fn ($a) => [
        'filename' => $a->filename,
        'mime' => $a->mimeType,
        'size' => $a->sizeBytes,
      ], $post->attachments),
      'total_attachment_bytes' => $post->totalAttachmentBytes(),
      'meta' => $post->meta,
      // The real SF1601 MeMo wire payload (kombi_request XML). This is what a
      // live send would post; storing it makes the evidence dashboard show
      // genuine message construction rather than the JSON summary above.
      'memo_xml' => $this->buildMemoXml($post),
    ];
    try {
      $this->database->insert('aabenforms_digital_post_log')
        ->fields([
          'transaction_id' => $transactionId,
          'mode' => $this->modeLabel(),
          'recipient_type' => $post->recipient->type,
          'recipient_identifier_hash' => $post->recipient->identifierHash(),
          'sender_cvr' => $post->sender->cvr,
          'subject' => mb_substr($post->subject, 0, 255),
          'status' => Result::SUCCESS,
          'reason_code' => NULL,
          'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
          'response' => 'fake_db:synthetic-receipt',
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      $this->logger->info('Digital Post fake-db send: tx=@tx subject=@subject', [
        '@tx' => $transactionId,
        '@subject' => $post->subject,
      ]);
      // Refresh the AabenForms admin dashboard's recent-activity panel.
      Cache::invalidateTags(['aabenforms_dashboard:activity']);
      return Result::success(
        transactionId: $transactionId,
        message: 'fake_db: payload recorded in aabenforms_digital_post_log',
        rawResponse: 'fake_db:synthetic-receipt',
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Digital Post fake-db send failed: @msg', ['@msg' => $e->getMessage()]);
      return Result::failure(
        transactionId: $transactionId,
        reasonCode: Result::REASON_TRANSPORT,
        message: 'fake_db write failed: ' . $e->getMessage(),
      );
    }
  }

  /**
   * Builds the MeMo kombi_request XML, tolerating build failures.
   *
   * The evidence is nice-to-have: a build failure must never fail the send
   * (the case must still reach lukket), so it logs a warning and returns NULL.
   * The vendored MeMo classes emit PHP 8.4 implicit-nullable deprecations at
   * load time; those are silenced around the build to keep the log clean.
   */
  private function buildMemoXml(DigitalPost $post): ?string {
    $previous = error_reporting();
    error_reporting($previous & ~E_DEPRECATED);
    try {
      return $this->memoBuilder->buildKombiRequestXml($post);
    }
    catch (\Throwable $e) {
      $this->logger->warning('MeMo build failed (logging JSON summary only): @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
    finally {
      error_reporting($previous);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function modeLabel(): string {
    return 'fake_db';
  }

}
