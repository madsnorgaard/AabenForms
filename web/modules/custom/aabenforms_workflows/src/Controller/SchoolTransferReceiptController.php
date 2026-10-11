<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Controller;

use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\aabenforms_core\Http\SessionBearer;
use Drupal\aabenforms_workflows\Service\SchoolTransferCaseView;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The co-signing parent's receipt for a school transfer.
 *
 * Authorised only by the caller's own MitID session, sent as
 * "Authorization: Bearer <session>" (never in the URL). The case is found by
 * comparing the session's CPR server-side with the decrypted parent2_cpr of
 * the submission; the response carries no CPR numbers.
 */
final class SchoolTransferReceiptController extends ControllerBase {

  public function __construct(
    private readonly MitIdSessionManager $sessionManager,
    private readonly SchoolTransferCaseView $caseView,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aabenforms_mitid.session_manager'),
      $container->get('aabenforms_workflows.school_transfer_case_view'),
    );
  }

  /**
   * GET /api/skoleskift/medunderskrift.
   */
  public function receipt(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'no-store, private'];
    $sessionId = SessionBearer::fromRequest($request);
    $cpr = $sessionId !== '' ? (string) ($this->sessionManager->getCprFromSession($sessionId) ?? '') : '';
    if ($cpr === '') {
      return new JsonResponse(['error' => 'unauthenticated'], 401, $headers);
    }
    $submission = $this->caseView->findCoSignedBy($cpr);
    if ($submission === NULL) {
      return new JsonResponse(['error' => 'not_found'], 404, $headers);
    }
    return new JsonResponse(['data' => $this->caseView->receipt($submission)], 200, $headers);
  }

}
