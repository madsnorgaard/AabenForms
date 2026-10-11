<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_session_inbox\Controller;

use Drupal\aabenforms_core\Http\SessionBearer;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns recent Digital Post log rows for a MitID session's recipient.
 *
 * Capability model: the workflow session id (15-min tempstore) is the only
 * thing accepted, and only as "Authorization: Bearer <session>" - never in
 * the URL, where it would land in access logs, history and Referer headers.
 * The controller resolves the session's CPR server-side, hashes it, and
 * queries the log by hash. The CPR never appears in request or response.
 * When the session is absent or expired the response is `[]` with HTTP 200 -
 * the demo frontend renders an empty inbox cleanly without a 404 spike in
 * the browser console.
 *
 * Each row carries `channel` (digital|physical) so a letter sent to a citizen
 * fritaget for Digital Post can be shown as "sendt som fysisk brev".
 */
final class SessionInboxController extends ControllerBase {

  private const DEFAULT_LIMIT = 5;
  private const MAX_LIMIT = 20;

  /**
   * Constructs a SessionInboxController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\aabenforms_mitid\Service\MitIdSessionManager $sessionManager
   *   The MitID session manager - resolves CPR from the workflow_id.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly MitIdSessionManager $sessionManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('aabenforms_mitid.session_manager'),
    );
  }

  /**
   * GET /api/digital-post/recent?limit=N with a Bearer MitID session.
   */
  public function getRecent(Request $request): JsonResponse {
    $sessionId = SessionBearer::fromRequest($request);
    $limit = (int) $request->query->get('limit', self::DEFAULT_LIMIT);
    if ($limit < 1) {
      $limit = self::DEFAULT_LIMIT;
    }
    if ($limit > self::MAX_LIMIT) {
      $limit = self::MAX_LIMIT;
    }

    // Letters are personal: never let a proxy or the browser cache them.
    $headers = ['Cache-Control' => 'no-store, private'];
    if ($sessionId === '') {
      return new JsonResponse(['data' => []], 200, $headers);
    }

    $cpr = $this->sessionManager->getCprFromSession($sessionId);
    if (!$cpr) {
      return new JsonResponse(['data' => []], 200, $headers);
    }

    // Match Recipient::identifierHash() in aabenforms_digital_post: it
    // hashes "{type}:{digits}" not just the digits. Stay aligned with the
    // log writer or the join misses every row.
    $hash = hash('sha256', 'cpr:' . preg_replace('/\D+/', '', $cpr));

    $fields = ['transaction_id', 'subject', 'status', 'payload', 'created'];
    // The channel column arrives with aabenforms_digital_post_update_11001;
    // keep serving the inbox on a site where updb has not run yet.
    $hasChannel = $this->database->schema()->fieldExists('aabenforms_digital_post_log', 'channel');
    if ($hasChannel) {
      $fields[] = 'channel';
    }
    $rows = $this->database->select('aabenforms_digital_post_log', 'l')
      ->fields('l', $fields)
      ->condition('l.recipient_type', 'cpr')
      ->condition('l.recipient_identifier_hash', $hash)
      ->orderBy('l.created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
      $payload = json_decode((string) ($row['payload'] ?? '{}'), TRUE) ?: [];
      $bodyRaw = is_string($payload['body'] ?? NULL) ? $payload['body'] : '';
      $data[] = [
        'transaction_id' => $row['transaction_id'] ?? '',
        'subject' => $row['subject'] ?? '',
        'body_html' => Xss::filterAdmin($bodyRaw),
        'status' => $row['status'] ?? '',
        // Channel is digital or physical. A physical row is a (DEMO) print job:
        // the frontend shows it as "sendt som fysisk brev".
        'channel' => ($row['channel'] ?? '') === 'physical' ? 'physical' : 'digital',
        'created' => (int) ($row['created'] ?? 0),
        'created_iso' => gmdate('c', (int) ($row['created'] ?? 0)),
      ];
    }

    return new JsonResponse(['data' => $data], 200, $headers);
  }

}
