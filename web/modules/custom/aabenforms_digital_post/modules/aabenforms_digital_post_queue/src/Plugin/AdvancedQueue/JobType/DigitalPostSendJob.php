<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_queue\Plugin\AdvancedQueue\JobType;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface;
use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes one queued Digital Post send.
 *
 * The engine of the resilience queue: it calls the SAME idempotent transport
 * the synchronous path uses (aabenforms_digital_post.sender), then maps the
 * typed Result onto the advancedqueue contract so the queue advances itself:
 *
 * - success / pending (a live 2xx = accepted) -> JobResult::success. The send is
 *   done; if it carries a case, the case is stamped so the async Beskedfordeler
 *   receipt can reconcile delivery later - honouring the async digital landscape.
 * - transient failure (REASON_TRANSPORT / 5xx / timeout) -> JobResult::failure
 *   WITH retries, so advancedqueue re-leases the job after the backoff delay.
 * - permanent failure (validation, unknown recipient) -> JobResult::failure with
 *   0 retries, so it dead-letters for a caseworker instead of looping.
 *
 * Idempotency is inherited from the transport (the transaction_id unique key +
 * pre-send lookup), so a retry never re-sends an official letter. GDPR: a CPR
 * recipient travels through the queue ENCRYPTED and is only revealed here.
 */
#[AdvancedQueueJobType(
  id: 'aabenforms_digital_post_send',
  label: new TranslatableMarkup('Digital Post send'),
  max_retries: 5,
  retry_delay: 60,
)]
class DigitalPostSendJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly DigitalPostSenderInterface $sender,
    private readonly CprAccess $cprAccess,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    /** @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory */
    $loggerFactory = $container->get('logger.factory');
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aabenforms_digital_post.sender'),
      $container->get('aabenforms_core.cpr_access'),
      $container->get('entity_type.manager'),
      $loggerFactory->get('aabenforms_digital_post_queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $p = $job->getPayload();
    try {
      $recipientType = (string) ($p['recipient_type'] ?? Recipient::TYPE_CPR);
      $raw = (string) ($p['recipient'] ?? '');
      // A CPR recipient rides the queue encrypted; reveal it only here.
      if ($recipientType === Recipient::TYPE_CPR && $raw !== '') {
        $raw = $this->cprAccess->reveal($raw);
      }
      if ($raw === '') {
        return JobResult::failure('Recipient could not be resolved.', 0, 0);
      }

      $recipient = $recipientType === Recipient::TYPE_CVR
        ? Recipient::cvr($raw)
        : Recipient::cpr($raw);
      $sender = ($p['sender_cvr'] ?? '') !== ''
        ? new Sender(cvr: (string) $p['sender_cvr'])
        : Sender::fromConfig(\Drupal::configFactory());

      $post = new DigitalPost(
        recipient: $recipient,
        sender: $sender,
        subject: (string) ($p['subject'] ?? 'Digital Post'),
        body: (string) ($p['body'] ?? ''),
        type: (string) ($p['type'] ?? DigitalPost::TYPE_DIGITAL_POST),
        meta: ['transaction_id' => (string) ($p['transaction_id'] ?? '')],
      );

      $result = $this->sender->send($post);

      if ($result->isSuccess() || $result->isPending()) {
        $this->stampCase($p['case_id'] ?? NULL, $result->transactionId);
        return JobResult::success(sprintf('Digital Post %s (tx %s).', $result->status, $result->transactionId));
      }

      // Failure: a transport error is retryable, everything else is terminal.
      if ($result->reasonCode === Result::REASON_TRANSPORT) {
        return JobResult::failure('Transient transport failure: ' . $result->message);
      }
      return JobResult::failure('Permanent failure (' . $result->reasonCode . '): ' . $result->message, 0, 0);
    }
    catch (\Throwable $e) {
      // Unknown errors are treated as transient so the job retries rather than
      // dead-lettering on a transient glitch.
      $this->logger->error('Digital Post job error: @msg', ['@msg' => $e->getMessage()]);
      return JobResult::failure('Job error (retrying): ' . $e->getMessage());
    }
  }

  /**
   * Stamps the case so the async Beskedfordeler receipt can reconcile it.
   *
   * Idempotent: only sets the transaction reference when the case does not
   * already carry one.
   *
   * @param mixed $caseId
   *   The case id, or NULL when the send is not tied to a case.
   * @param string $transactionId
   *   The send transaction id.
   */
  private function stampCase($caseId, string $transactionId): void {
    if (empty($caseId) || $transactionId === '') {
      return;
    }
    try {
      $case = $this->entityTypeManager->getStorage('aabenforms_case')->load($caseId);
      if ($case === NULL || !$case->hasField('digital_post_tx')) {
        return;
      }
      if ((string) $case->get('digital_post_tx')->value !== '') {
        return;
      }
      $case->set('digital_post_tx', $transactionId);
      $case->set('digital_post_receipt_status', 'pending');
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage('Digital Post afsendt via kø (afventer kvittering).');
      $case->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not stamp case @id with Digital Post tx: @msg', [
        '@id' => $caseId,
        '@msg' => $e->getMessage(),
      ]);
    }
  }

}
