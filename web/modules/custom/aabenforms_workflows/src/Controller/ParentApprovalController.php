<?php

namespace Drupal\aabenforms_workflows\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\aabenforms_workflows\Service\ParentCprVerifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;

/**
 * Controller for parent approval pages.
 *
 * Handles the approval workflow:
 * 1. Validates token and submission
 * 2. Checks if already approved
 * 3. Shows MitID login button
 * 4. After MitID auth: displays form with data
 * 5. Processes approval/rejection.
 */
class ParentApprovalController extends ControllerBase {

  /**
   * The approval token service.
   *
   * @var \Drupal\aabenforms_workflows\Service\ApprovalTokenService
   */
  protected ApprovalTokenService $tokenService;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $mitidSessionManager;

  /**
   * The parent-approval CPR verifier.
   *
   * @var \Drupal\aabenforms_workflows\Service\ParentCprVerifier
   */
  protected ParentCprVerifier $cprVerifier;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * Constructs a ParentApprovalController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\aabenforms_workflows\Service\ApprovalTokenService $token_service
   *   The approval token service.
   * @param \Drupal\aabenforms_mitid\Service\MitIdSessionManager $mitid_session_manager
   *   The MitID session manager (for verifying OIDC handoff).
   * @param \Drupal\aabenforms_workflows\Service\ParentCprVerifier $cpr_verifier
   *   The parent-approval CPR verifier (security gate).
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer (for returning HTML responses with non-200 status codes).
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ApprovalTokenService $token_service,
    MitIdSessionManager $mitid_session_manager,
    ParentCprVerifier $cpr_verifier,
    RendererInterface $renderer,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tokenService = $token_service;
    $this->mitidSessionManager = $mitid_session_manager;
    $this->cprVerifier = $cpr_verifier;
    $this->renderer = $renderer;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('aabenforms_workflows.approval_token'),
      $container->get('aabenforms_mitid.session_manager'),
      $container->get('aabenforms_workflows.parent_cpr_verifier'),
      $container->get('renderer'),
      $container->get('logger.factory')->get('aabenforms_workflows')
    );
  }

  /**
   * Displays the parent approval page.
   *
   * @param int $parent_number
   *   The parent number (1 or 2).
   * @param int $submission_id
   *   The webform submission ID.
   * @param string $token
   *   The security token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function approvalPage(int $parent_number, int $submission_id, string $token, Request $request): array {
    // Validate token, then branch on outcome so the citizen sees an
    // accurate message rather than a generic "denied". Three outcomes:
    // expired (well-formed, past) → "ask for a new link"; malformed
    // (URL corrupted) → "check the URL"; tampered (HMAC mismatch) →
    // 403 (real security signal).
    if (!$this->tokenService->validateToken($submission_id, $parent_number, $token)) {
      $this->logger->warning('Invalid or expired token for submission @sid, parent @parent', [
        '@sid' => $submission_id,
        '@parent' => $parent_number,
      ]);

      if ($this->tokenService->isTokenExpired($token)) {
        return [
          '#theme' => 'status_messages',
          '#message_list' => [
            'error' => [$this->t('This approval link has expired. Please contact the case worker for a new link.')],
          ],
          '#status_headings' => [
            'error' => $this->t('Expired Link'),
          ],
        ];
      }

      if ($this->tokenService->isTokenMalformed($token)) {
        return [
          '#theme' => 'status_messages',
          '#message_list' => [
            'error' => [
              $this->t('This approval link is invalid. The URL may have been truncated by your email client - please copy and paste the full link, or contact the case worker for a fresh one.'),
            ],
          ],
          '#status_headings' => [
            'error' => $this->t('Invalid Link'),
          ],
        ];
      }

      throw new AccessDeniedHttpException('Invalid approval token.');
    }

    // Load submission.
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $submission = $storage->load($submission_id);

    if (!$submission) {
      $this->logger->error('Submission @sid not found', ['@sid' => $submission_id]);
      throw new NotFoundHttpException('Submission not found.');
    }

    // Check if already approved/rejected.
    $status_field = "parent{$parent_number}_status";
    $current_status = $submission->getElementData($status_field);

    if (in_array($current_status, ['complete', 'rejected'])) {
      $this->logger->info('Parent @parent already processed submission @sid with status: @status', [
        '@parent' => $parent_number,
        '@sid' => $submission_id,
        '@status' => $current_status,
      ]);

      return [
        '#theme' => 'status_messages',
        '#message_list' => [
          'status' => [
            $this->t('You have already @action this request.', [
              '@action' => $current_status === 'complete' ? 'approved' : 'rejected',
            ]),
          ],
        ],
        '#status_headings' => [
          'status' => $this->t('Already Processed'),
        ],
      ];
    }

    // Check if MitID authenticated. The flag is scoped to BOTH the parent
    // number AND the submission id, so a MitID login for one family's request
    // cannot satisfy the gate for a different submission held in the same
    // browser session.
    $session = $request->getSession();
    $mitid_authenticated = $session->get("mitid_authenticated_parent{$parent_number}_{$submission_id}");

    if (!$mitid_authenticated) {
      // Show MitID login page.
      return $this->buildMitIdLoginPage($submission, $parent_number, $token);
    }

    // Show approval form.
    return $this->buildApprovalForm($submission, $parent_number, $token);
  }

  /**
   * Builds the MitID login page.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   * @param int $parent_number
   *   The parent number.
   * @param string $token
   *   The security token.
   *
   * @return array
   *   A render array.
   */
  protected function buildMitIdLoginPage($submission, int $parent_number, string $token): array {
    $child_name = $submission->getElementData('child_name');
    // Forms other than parent_request_form (e.g. school_transfer) carry the
    // free-text summary under another key, so fall back and never pass NULL.
    $request_details = (string) ($submission->getElementData('request_details') ?? $submission->getElementData('reason') ?? $submission->getElementData('details') ?? '');

    return [
      '#theme' => 'parent_approval_login',
      '#child_name' => $child_name,
      '#parent_number' => $parent_number,
      '#request_summary' => $this->truncateText($request_details, 200),
      '#mitid_login_url' => Url::fromRoute('aabenforms_workflows.parent_approval_mitid', [
        'parent_number' => $parent_number,
        'submission_id' => $submission->id(),
        'token' => $token,
      ])->toString(),
      '#attached' => [
        'library' => [
          'aabenforms_workflows/parent-approval',
        ],
      ],
    ];
  }

  /**
   * Builds the approval form.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   * @param int $parent_number
   *   The parent number.
   * @param string $token
   *   The security token.
   *
   * @return array
   *   A render array.
   */
  protected function buildApprovalForm($submission, int $parent_number, string $token): array {
    $form_builder = \Drupal::formBuilder();

    // Determine data visibility based on parents_together field.
    $parents_together = $submission->getElementData('parents_together') === 'together';

    return [
      '#theme' => 'parent_approval_page',
      '#form' => $form_builder->getForm(
        'Drupal\aabenforms_workflows\Form\ParentApprovalForm',
        $submission,
        $parent_number,
        $token,
        $parents_together
      ),
      '#attached' => [
        'library' => [
          'aabenforms_workflows/parent-approval',
        ],
      ],
    ];
  }

  /**
   * Truncates text to specified length.
   *
   * @param string $text
   *   The text to truncate.
   * @param int $length
   *   Maximum length.
   *
   * @return string
   *   Truncated text.
   */
  protected function truncateText(string $text, int $length): string {
    if (mb_strlen($text) <= $length) {
      return $text;
    }
    return mb_substr($text, 0, $length) . '...';
  }

  /**
   * Initiates the parent's MitID OIDC login.
   *
   * Validates the token (same gating as approvalPage so a tampered or
   * malformed link can't bypass the MitID requirement by navigating
   * directly to this route), then redirects to the aabenforms_mitid
   * OIDC login route with a workflow_id scoped to this parent and a
   * return_url pointing at our matching `mitid/complete` endpoint.
   *
   * After the OIDC round-trip the parent lands at mitidComplete()
   * below, which verifies a real authenticated MitID session is in
   * tempstore for this workflow_id before flipping the session flag.
   *
   * The workflow_id namespace `parent_approval_<sid>_p<N>` keeps each
   * parent's MitID session isolated so two parents reviewing the same
   * submission from different browsers don't collide.
   *
   * @param int $parent_number
   *   The parent number (1 or 2).
   * @param int $submission_id
   *   The webform submission ID.
   * @param string $token
   *   The security token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to /mitid/login with the parent context attached.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  public function mitidLogin(int $parent_number, int $submission_id, string $token, Request $request): RedirectResponse {
    if (!$this->tokenService->validateToken($submission_id, $parent_number, $token)) {
      $this->logger->warning('Invalid token at MitID handoff for submission @sid, parent @parent', [
        '@sid' => $submission_id,
        '@parent' => $parent_number,
      ]);
      throw new AccessDeniedHttpException('Invalid approval token.');
    }

    $workflow_id = sprintf('parent_approval_%d_p%d', $submission_id, $parent_number);
    $return_url = Url::fromRoute(
      'aabenforms_workflows.parent_approval_mitid_complete',
      [
        'parent_number' => $parent_number,
        'submission_id' => $submission_id,
        'token' => $token,
      ],
      ['absolute' => TRUE],
    )->toString();

    $login_url = Url::fromRoute(
      'aabenforms_mitid.login',
      [],
      [
        'query' => [
          'workflow_id' => $workflow_id,
          'return_url' => $return_url,
        ],
      ],
    )->toString();

    return new RedirectResponse($login_url);
  }

  /**
   * Handles the post-OIDC return from MitID for the parent flow.
   *
   * Re-validates the token (defence in depth: the workflow_id query
   * arg can be inspected/altered by a malicious return target), then
   * checks the aabenforms_mitid session manager for a real OIDC
   * session under our scoped workflow_id, then enforces the CPR gate
   * - the MitID-asserted CPR must match the parent_<N>_cpr captured
   * on the original submission. Without this check any holder of the
   * approval-token URL can authenticate with any MitID account and
   * approve another family's submission (issue #54).
   *
   * Three failure paths render distinct citizen-meaningful UX:
   * - Token invalid / tampered: handled by validateToken above (403).
   * - MitID session lacks CPR claim: 502 with "try again" UX.
   * - MitID CPR mismatch: 403 with sparse "wrong parent" UX. Audit
   *   row records both CPR hashes; moderation_state is left untouched
   *   so the case worker can see the failure and re-issue if needed.
   *
   * @param int $parent_number
   *   The parent number (1 or 2).
   * @param int $submission_id
   *   The webform submission ID.
   * @param string $token
   *   The security token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Redirect back to the approval page on success; rendered
   *   403/502 page on a CPR-gate failure.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  public function mitidComplete(int $parent_number, int $submission_id, string $token, Request $request): Response {
    if (!$this->tokenService->validateToken($submission_id, $parent_number, $token)) {
      $this->logger->warning('Invalid token at MitID complete for submission @sid, parent @parent', [
        '@sid' => $submission_id,
        '@parent' => $parent_number,
      ]);
      throw new AccessDeniedHttpException('Invalid approval token.');
    }

    $workflow_id = sprintf('parent_approval_%d_p%d', $submission_id, $parent_number);
    if (!$this->mitidSessionManager->hasValidSession($workflow_id)) {
      $this->logger->warning('No valid MitID session for workflow @wid (parent @parent, submission @sid)', [
        '@wid' => $workflow_id,
        '@parent' => $parent_number,
        '@sid' => $submission_id,
      ]);
      throw new AccessDeniedHttpException('MitID authentication did not complete.');
    }

    // Load submission for CPR comparison. NotFound is the right answer
    // here too - a missing submission shouldn't reveal whether the token
    // ever bound a valid id.
    $submission = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->load($submission_id);
    if (!$submission) {
      $this->logger->error('Submission @sid not found at MitID complete', [
        '@sid' => $submission_id,
      ]);
      throw new NotFoundHttpException('Submission not found.');
    }

    // Security gate: MitID-asserted CPR must equal parent_<N>_cpr.
    // The verifier handles audit logging on every outcome.
    $result = $this->cprVerifier->verify($submission, $parent_number, $workflow_id);
    if ($result === ParentCprVerifier::RESULT_MISSING_MITID_CPR) {
      // Upstream IdP failure - return 502 so the citizen sees a
      // "try again or contact us" message rather than a 403 that
      // implies they did something wrong.
      return $this->renderGateFailure(
        Response::HTTP_BAD_GATEWAY,
        $this->t('MitID-fejl'),
        $this->t('MitID returnerede ikke et CPR-nummer; prøv igen eller kontakt kommunen.')
      );
    }
    if ($result === ParentCprVerifier::RESULT_MISSING_EXPECTED_CPR) {
      // The submission carries no parent_<N>_cpr to compare against, so the
      // consent gate cannot verify which parent is acting. Fail CLOSED by
      // default (require_parent_cpr_match, default TRUE even if the config key
      // is absent). Deployments mid-migration can flip the flag off to keep
      // the legacy warn-and-allow until their forms are back-filled.
      $require_match = $this->config('aabenforms_workflows.settings')->get('require_parent_cpr_match') ?? TRUE;
      if ($require_match) {
        $this->logger->warning(
          'CPR gate denied: submission @sid has no parent@parent_cpr field; approval blocked (require_parent_cpr_match on, workflow @wid)',
          [
            '@sid' => $submission_id,
            '@parent' => $parent_number,
            '@wid' => $workflow_id,
          ]
        );
        return $this->renderGateFailure(
          Response::HTTP_FORBIDDEN,
          $this->t('Adgang nægtet'),
          $this->t('Sagen mangler det forventede CPR-nummer; kontakt sagsbehandleren.')
        );
      }
      $this->logger->warning(
        'CPR gate skipped: submission @sid has no parent@parent_cpr field; approval allowed without CPR verification (require_parent_cpr_match off, workflow @wid)',
        [
          '@sid' => $submission_id,
          '@parent' => $parent_number,
          '@wid' => $workflow_id,
        ]
      );
    }
    elseif ($result !== ParentCprVerifier::RESULT_MATCH) {
      // Mismatch: both CPRs were present but do not match - hard security
      // failure. The citizen-facing copy is sparse on purpose - we do not
      // confirm whether the right MitID account would have worked, only
      // that the case worker has been informed.
      return $this->renderGateFailure(
        Response::HTTP_FORBIDDEN,
        $this->t('Adgang nægtet'),
        $this->t('Du er ikke den ventede forælder. Sagsbehandleren er informeret.')
      );
    }

    $request->getSession()->set("mitid_authenticated_parent{$parent_number}_{$submission_id}", TRUE);
    $this->logger->info('Parent @parent MitID handoff verified for submission @sid', [
      '@parent' => $parent_number,
      '@sid' => $submission_id,
    ]);

    $url = Url::fromRoute('aabenforms_workflows.parent_approval', [
      'parent_number' => $parent_number,
      'submission_id' => $submission_id,
      'token' => $token,
    ])->toString();
    return new RedirectResponse($url);
  }

  /**
   * Renders a sparse failure page for the parent-approval CPR gate.
   *
   * Returns a Response with the requested status code and a minimal
   * HTML body. The body is rendered through the renderer service so
   * the translation system + escape rules apply, but the response
   * stays a plain Response (not a render array) so the caller can
   * set a non-200 status code.
   *
   * @param int $status
   *   HTTP status code (403 or 502).
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $heading
   *   Page heading - shown to the citizen.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   Body message - shown to the citizen.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTML response.
   */
  protected function renderGateFailure(int $status, $heading, $message): Response {
    $build = [
      '#theme' => 'status_messages',
      '#message_list' => [
        'error' => [$message],
      ],
      '#status_headings' => [
        'error' => $heading,
      ],
    ];
    $html = (string) $this->renderer->renderRoot($build);
    return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * Displays the completion page after approval/rejection.
   *
   * @param string $action
   *   The action taken (approve or reject).
   *
   * @return array
   *   A render array.
   */
  public function completePage(string $action): array {
    $is_approved = $action === 'approve';

    return [
      '#theme' => 'status_messages',
      '#message_list' => [
        'status' => [
          $is_approved
            ? $this->t('Thank you for approving this request. The case worker has been notified and will proceed with processing.')
            : $this->t('Your response has been recorded. The case worker has been notified of your decision.'),
        ],
      ],
      '#status_headings' => [
        'status' => $this->t('Decision Recorded'),
      ],
      'info' => [
        '#markup' => '<div class="parent-approval-complete">' .
        '<p>' . $this->t('You may now close this window.') . '</p>' .
        '<p>' . $this->t('If you have any questions, please contact the case worker handling this request.') . '</p>' .
        '</div>',
      ],
    ];
  }

}
