<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\TestMode;

use Drupal\aabenforms_digital_post\Certificate\CertificateLocatorInterface;
use Drupal\aabenforms_digital_post\Certificate\VendorCertificateLocatorAdapter;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Memo\MemoBuilder;
use Drupal\aabenforms_digital_post\Service\Sf1601ClientInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use ItkDev\Serviceplatformen\Service\SF1601\SF1601;
use Psr\Log\LoggerInterface;

/**
 * Live SF1601 Digital Post transport (Serviceplatformen exttest / prod).
 *
 * Reuses MemoBuilder to construct the MeMo Message and passes it straight to
 * the itk-dev SF1601 service's kombiPostAfsend(). The crucial contract: a 2xx
 * from Serviceplatformen means the message was ACCEPTED, not delivered, so this
 * client returns Result::pending() and writes a `pending` log row - never
 * `success`. The real delivered/failed outcome arrives asynchronously as a
 * Beskedfordeler receipt (handled by the beskedfordeler submodule). Like every
 * transport it NEVER throws; all failure modes become Result::failure().
 *
 * Idempotent: the transaction_id unique key plus a pre-send lookup mean a retry
 * with the same id does not re-send an official letter.
 */
final class LiveSf1601Client implements Sf1601ClientInterface {

  /**
   * Constructs the live client.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\aabenforms_digital_post\Memo\MemoBuilder $memoBuilder
   *   The MeMo builder.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\aabenforms_digital_post\Certificate\CertificateLocatorInterface $certificateLocator
   *   The module certificate locator.
   * @param \Closure|null $serviceFactory
   *   Optional (string $cvr, bool $testMode): SF1601 factory. Only tests set
   *   it, to inject a stubbed service and bypass the real cert + network.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MemoBuilder $memoBuilder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CertificateLocatorInterface $certificateLocator,
    private readonly ?\Closure $serviceFactory = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function send(DigitalPost $post, string $transactionId): Result {
    // Idempotency: if this transaction was already accepted (pending) or
    // recorded as sent, never re-post the letter - just report it pending.
    $existing = $this->existingStatus($transactionId);
    if ($existing === Result::PENDING || $existing === Result::SUCCESS) {
      return Result::pending($transactionId, 'Already submitted (idempotent replay).');
    }

    $config = $this->configFactory->get('aabenforms_digital_post.settings');
    $authorityCvr = (string) ($config->get('authority_cvr') ?: $config->get('sender_cvr'));
    if ($authorityCvr === '') {
      return Result::failure(
        $transactionId,
        Result::REASON_VALIDATION,
        'No authority_cvr / sender_cvr configured for the live SF1601 client.',
      );
    }
    $testMode = $this->modeLabel() !== 'live';

    $previous = error_reporting();
    error_reporting($previous & ~E_DEPRECATED);
    try {
      $message = $this->memoBuilder->buildMessage($post);
      $service = $this->buildService($authorityCvr, $testMode);
      $response = $service->kombiPostAfsend($transactionId, SF1601::TYPE_DIGITAL_POST, $message);
      $status = $response->getStatusCode();
      // getContent(FALSE) never throws on a 4xx/5xx, so we can inspect the body.
      $body = $response->getContent(FALSE);
      $result = $this->mapResponse($status, $body, $transactionId);
    }
    catch (\Throwable $e) {
      $this->logger->error('Live Digital Post send failed: tx=@tx @msg', [
        '@tx' => $transactionId,
        '@msg' => $e->getMessage(),
      ]);
      $result = Result::failure(
        $transactionId,
        Result::REASON_TRANSPORT,
        'Live send failed: ' . $e->getMessage(),
      );
    }
    finally {
      error_reporting($previous);
    }

    $this->recordAttempt($post, $result);
    return $result;
  }

  /**
   * Maps a Serviceplatformen HTTP status + body onto a Result.
   *
   * Pure and side-effect-free so it can be unit-tested directly. A 2xx is
   * pending (accepted, not delivered); a 4xx is a permanent validation/recipient
   * problem; a 5xx (or anything else) is a transient transport failure.
   *
   * @param int $status
   *   The HTTP status code from kombiPostAfsend().
   * @param string $body
   *   The response body (for the audit trail; may be empty).
   * @param string $transactionId
   *   The transaction id.
   *
   * @return \Drupal\aabenforms_digital_post\DigitalPost\Result
   *   The mapped result.
   */
  public function mapResponse(int $status, string $body, string $transactionId): Result {
    if ($status >= 200 && $status < 300) {
      return Result::pending($transactionId, sprintf('Accepted by Serviceplatformen (HTTP %d).', $status), $body);
    }
    if ($status >= 400 && $status < 500) {
      return Result::failure(
        $transactionId,
        Result::REASON_VALIDATION,
        sprintf('Serviceplatformen rejected the message (HTTP %d).', $status),
        $body,
      );
    }
    return Result::failure(
      $transactionId,
      Result::REASON_TRANSPORT,
      sprintf('Serviceplatformen transport error (HTTP %d).', $status),
      $body,
    );
  }

  /**
   * Constructs the vendor SF1601 service.
   *
   * Encapsulates ALL vendor construction - locating the certificate and
   * wrapping it in the vendor adapter - so the injected $serviceFactory (tests
   * only) can supply a stubbed service without any real certificate.
   *
   * @param string $authorityCvr
   *   The sending authority CVR.
   * @param bool $testMode
   *   TRUE for the exttest endpoint, FALSE for production.
   *
   * @return \ItkDev\Serviceplatformen\Service\SF1601\SF1601
   *   The configured service.
   */
  private function buildService(string $authorityCvr, bool $testMode): SF1601 {
    if ($this->serviceFactory !== NULL) {
      return ($this->serviceFactory)($authorityCvr, $testMode);
    }
    $adapter = new VendorCertificateLocatorAdapter($this->certificateLocator->locate());
    return new SF1601([
      'authority_cvr' => $authorityCvr,
      'certificate_locator' => $adapter,
      'test_mode' => $testMode,
    ]);
  }

  /**
   * Returns the current recorded status for a transaction id, or NULL.
   */
  private function existingStatus(string $transactionId): ?string {
    try {
      $status = $this->database->select('aabenforms_digital_post_log', 'l')
        ->fields('l', ['status'])
        ->condition('transaction_id', $transactionId)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      return $status === FALSE ? NULL : (string) $status;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Writes the attempt to the audit log (idempotent on transaction_id).
   */
  private function recordAttempt(DigitalPost $post, Result $result): void {
    $payload = [
      'recipient' => [
        'type' => $post->recipient->type,
        'identifier_hash' => $post->recipient->identifierHash(),
      ],
      'sender' => ['cvr' => $post->sender->cvr, 'name' => $post->sender->name],
      'subject' => $post->subject,
      'type' => $post->type,
      'meta' => $post->meta,
    ];
    try {
      $this->database->insert('aabenforms_digital_post_log')
        ->fields([
          'transaction_id' => $result->transactionId,
          'mode' => $this->modeLabel(),
          'recipient_type' => $post->recipient->type,
          'recipient_identifier_hash' => $post->recipient->identifierHash(),
          'sender_cvr' => $post->sender->cvr,
          'subject' => mb_substr($post->subject, 0, 255),
          'status' => $result->status,
          'reason_code' => $result->reasonCode,
          'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
          'response' => $result->rawResponse !== NULL ? mb_substr($result->rawResponse, 0, 65535) : NULL,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      Cache::invalidateTags(['aabenforms_dashboard:activity']);
    }
    catch (\Throwable $e) {
      // A duplicate transaction_id (unique key) means the attempt is already
      // recorded - safe to ignore. Any other write error is logged, never
      // surfaced: the send itself already happened.
      $this->logger->warning('Live Digital Post log write skipped for tx=@tx: @msg', [
        '@tx' => $result->transactionId,
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function modeLabel(): string {
    $mode = (string) $this->configFactory->get('aabenforms_digital_post.settings')->get('test_mode');
    return $mode === 'live' ? 'live' : 'live_test';
  }

}
