<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_queue\Service;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\advancedqueue\Job;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Enqueues a Digital Post send onto the self-draining resilience queue.
 *
 * The citizen's request returns as soon as the job is enqueued; the
 * cron-processed `digital_post` queue does the actual send later, with retry
 * and dead-letter (see DigitalPostSendJob). A CPR recipient is encrypted onto
 * the queue payload (via CprAccess) so no plaintext CPR is ever at rest in the
 * queue table; DigitalPostSendJob reveals it only at send time.
 */
class DigitalPostQueueDispatcher {

  /**
   * The advancedqueue queue id this dispatcher writes to.
   */
  public const QUEUE_ID = 'digital_post';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CprAccess $cprAccess,
  ) {
  }

  /**
   * Enqueues a Digital Post for asynchronous, retryable delivery.
   *
   * @param \Drupal\aabenforms_digital_post\DigitalPost\DigitalPost $post
   *   The Digital Post to send (recipient already resolved/decrypted).
   * @param string $transactionId
   *   The stable transaction id (idempotency key). Derive it from the case /
   *   submission so a re-fired flow reuses it.
   * @param string|null $caseId
   *   The case to stamp when the job succeeds, or NULL.
   *
   * @return string
   *   The enqueued job id.
   */
  public function enqueue(DigitalPost $post, string $transactionId, ?string $caseId = NULL): string {
    $recipientRaw = $post->recipient->identifier;
    // Encrypt a CPR recipient for at-rest safety in the queue payload; CVR is
    // not personal data and travels as-is.
    $payloadRecipient = $post->recipient->type === Recipient::TYPE_CPR
      ? $this->cprAccess->protect($recipientRaw)
      : $recipientRaw;

    $job = Job::create('aabenforms_digital_post_send', [
      'transaction_id' => $transactionId,
      'recipient_type' => $post->recipient->type,
      'recipient' => $payloadRecipient,
      'sender_cvr' => $post->sender->cvr,
      'subject' => $post->subject,
      'body' => $post->body,
      'type' => $post->type,
      'case_id' => $caseId,
    ]);

    /** @var \Drupal\advancedqueue\Entity\QueueInterface $queue */
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(self::QUEUE_ID);
    $queue->enqueueJob($job);
    return $job->getId();
  }

}
