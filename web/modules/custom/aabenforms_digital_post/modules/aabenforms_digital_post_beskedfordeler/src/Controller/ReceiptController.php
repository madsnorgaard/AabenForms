<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_beskedfordeler\Controller;

use Drupal\aabenforms_digital_post_beskedfordeler\Service\DigitalPostReceiptHandler;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives asynchronous Beskedfordeler delivery receipts.
 *
 * The route is open (_access: 'TRUE') because the caller is an external system,
 * so this controller carries its own hardening, mirroring the webform submit
 * API: a shared-secret check (fail-closed - no secret configured means no
 * intake), flood control, and strict body validation. A valid receipt is handed
 * to DigitalPostReceiptHandler, which correlates it by transaction id.
 */
class ReceiptController extends ControllerBase {

  /**
   * The receipt handler.
   *
   * @var \Drupal\aabenforms_digital_post_beskedfordeler\Service\DigitalPostReceiptHandler
   */
  protected DigitalPostReceiptHandler $receiptHandler;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->receiptHandler = $container->get('aabenforms_digital_post_beskedfordeler.receipt_handler');
    $instance->keyRepository = $container->get('key.repository');
    $instance->flood = $container->get('flood');
    return $instance;
  }

  /**
   * Handles a POSTed delivery receipt.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The intake result.
   */
  public function receive(Request $request): JsonResponse {
    // Shared-secret gate, fail-closed: an unconfigured secret rejects everything.
    if (!$this->secretMatches($request)) {
      return new JsonResponse(['error' => 'Forbidden'], 403);
    }

    // Flood control: a rolling one-minute window per client IP.
    $floodId = $request->getClientIp() ?? 'unknown';
    if (!$this->flood->isAllowed('aabenforms_digital_post_beskedfordeler.receipt', 60, 60, $floodId)) {
      return new JsonResponse(['error' => 'Too many requests'], 429);
    }
    $this->flood->register('aabenforms_digital_post_beskedfordeler.receipt', 60, $floodId);

    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data) || !isset($data['transaction_id'], $data['status'])) {
      return new JsonResponse(['error' => 'Invalid receipt payload'], 400);
    }
    $transactionId = (string) $data['transaction_id'];
    $status = (string) $data['status'];
    if (!in_array($status, [DigitalPostReceiptHandler::OUTCOME_DELIVERED, DigitalPostReceiptHandler::OUTCOME_FAILED], TRUE)) {
      return new JsonResponse(['error' => 'Unknown status'], 400);
    }
    $transient = (bool) ($data['transient'] ?? FALSE);
    $reason = (string) ($data['reason'] ?? '');

    $state = $this->receiptHandler->handle($transactionId, $status, $transient, $reason);
    if ($state === DigitalPostReceiptHandler::STATE_UNKNOWN) {
      return new JsonResponse(['error' => 'Unknown transaction'], 404);
    }
    return new JsonResponse(['state' => $state], 200);
  }

  /**
   * Verifies the shared secret header against the configured key entry.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE when the secret is configured and matches (constant-time).
   */
  protected function secretMatches(Request $request): bool {
    $keyName = (string) $this->config('aabenforms_digital_post_beskedfordeler.settings')->get('receipt_secret_key');
    if ($keyName === '') {
      return FALSE;
    }
    $key = $this->keyRepository->getKey($keyName);
    $expected = $key ? (string) $key->getKeyValue() : '';
    if ($expected === '') {
      return FALSE;
    }
    $provided = (string) $request->headers->get('X-Beskedfordeler-Secret', '');
    return $provided !== '' && hash_equals($expected, $provided);
  }

}
