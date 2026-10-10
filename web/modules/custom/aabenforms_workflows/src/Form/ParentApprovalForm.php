<?php

namespace Drupal\aabenforms_workflows\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_core\Service\FrontendUrl;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\aabenforms_workflows\Service\ParentCprVerifier;
use Drupal\eca\Service\ContentEntityTypes;
use Drupal\eca_content\Event\ContentEntityCustomEvent;
use Drupal\eca_content\Event\ContentEntityEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides a form for parent approval/rejection.
 *
 * Displays child information and request details with appropriate
 * GDPR controls based on parent relationship status.
 */
class ParentApprovalForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The approval token service.
   *
   * @var \Drupal\aabenforms_workflows\Service\ApprovalTokenService
   */
  protected ApprovalTokenService $tokenService;

  /**
   * The MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager
   */
  protected MitIdSessionManager $mitidSessionManager;

  /**
   * The parent-approval CPR verifier (issue #54 consent gate).
   *
   * @var \Drupal\aabenforms_workflows\Service\ParentCprVerifier
   */
  protected ParentCprVerifier $cprVerifier;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected $submission;

  /**
   * The parent number.
   *
   * @var int
   */
  protected int $parentNumber;

  /**
   * The security token.
   *
   * @var string
   */
  protected string $token;

  /**
   * Whether parents live together.
   *
   * @var bool
   */
  protected bool $parentsTogether;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The ECA content entity types service.
   *
   * @var \Drupal\eca\Service\ContentEntityTypes
   */
  protected ContentEntityTypes $entityTypes;

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * Builds return URLs into the citizen frontend (allowlisted origin only).
   *
   * @var \Drupal\aabenforms_core\Service\FrontendUrl|null
   */
  protected ?FrontendUrl $frontendUrl;

  /**
   * Webforms whose co-sign ends back in the frontend demo, with the path.
   */
  protected const FRONTEND_RETURN = [
    'school_transfer' => ['/demo/skoleskift', ['som' => 'lars', 'medunderskrevet' => '1']],
  ];

  /**
   * Constructs a ParentApprovalForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\aabenforms_workflows\Service\ApprovalTokenService $token_service
   *   The approval token service.
   * @param \Drupal\aabenforms_mitid\Service\MitIdSessionManager $mitid_session_manager
   *   The MitID session manager (re-verifies the OIDC handoff at submit).
   * @param \Drupal\aabenforms_workflows\Service\ParentCprVerifier $cpr_verifier
   *   The parent-approval CPR verifier (issue #54 consent gate).
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher, used to fire the one-shot approval custom event.
   * @param \Drupal\eca\Service\ContentEntityTypes $entity_types
   *   The ECA content entity types service.
   * @param \Drupal\aabenforms_core\Service\CprAccess $cpr_access
   *   The CPR access helper, used to decrypt the stored child CPR for display.
   * @param \Drupal\aabenforms_core\Service\FrontendUrl|null $frontend_url
   *   The frontend URL builder.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ApprovalTokenService $token_service,
    MitIdSessionManager $mitid_session_manager,
    ParentCprVerifier $cpr_verifier,
    LoggerInterface $logger,
    EventDispatcherInterface $event_dispatcher,
    ContentEntityTypes $entity_types,
    CprAccess $cpr_access,
    ?FrontendUrl $frontend_url = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tokenService = $token_service;
    $this->mitidSessionManager = $mitid_session_manager;
    $this->cprVerifier = $cpr_verifier;
    $this->logger = $logger;
    $this->eventDispatcher = $event_dispatcher;
    $this->entityTypes = $entity_types;
    $this->cprAccess = $cpr_access;
    $this->frontendUrl = $frontend_url;
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
      $container->get('logger.factory')->get('aabenforms_workflows'),
      $container->get('event_dispatcher'),
      $container->get('eca.service.content_entity_types'),
      $container->get('aabenforms_core.cpr_access'),
      $container->get('aabenforms_core.frontend_url'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenforms_parent_approval_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $submission = NULL, $parent_number = NULL, $token = NULL, $parents_together = TRUE): array {
    $this->submission = $submission;
    $this->parentNumber = $parent_number;
    $this->token = $token;
    $this->parentsTogether = $parents_together;

    // Child information section.
    $form['child_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Child Information'),
    ];

    $form['child_info']['child_name'] = [
      '#type' => 'item',
      '#title' => $this->t('Child Name'),
      '#markup' => '<strong>' . htmlspecialchars($submission->getElementData('child_name')) . '</strong>',
    ];

    // CPR - mask if parents apart per GDPR.
    $child_cpr = $this->cprAccess->reveal((string) $submission->getElementData('child_cpr'));
    if (!$this->parentsTogether) {
      // Mask CPR: show only first 6 digits (birthdate) and last digit.
      $child_cpr = substr($child_cpr, 0, 6) . '-XXX' . substr($child_cpr, -1);
    }

    $form['child_info']['child_cpr'] = [
      '#type' => 'item',
      '#title' => $this->t('CPR Number'),
      '#markup' => '<code>' . htmlspecialchars($child_cpr) . '</code>',
    ];

    if (!$this->parentsTogether) {
      $form['child_info']['gdpr_notice'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('Note: Some information is masked for privacy as parents are registered as living apart.') .
        '</div>',
      ];
    }

    // Request details section.
    $form['request_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Request Details'),
    ];

    $form['request_info']['request_details'] = [
      '#type' => 'item',
      '#title' => $this->t('Description'),
      '#markup' => '<p>' . nl2br(htmlspecialchars((string) ($submission->getElementData('request_details') ?? $submission->getElementData('reason') ?? $submission->getElementData('details') ?? ''))) . '</p>',
    ];

    // Request date.
    $form['request_info']['request_date'] = [
      '#type' => 'item',
      '#title' => $this->t('Submitted'),
      '#markup' => \Drupal::service('date.formatter')->format($submission->getCreatedTime(), 'long'),
    ];

    // Approval decision section.
    $form['decision'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Your Decision'),
    ];

    $form['decision']['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Do you approve this request?'),
      '#options' => [
        'approve' => $this->t('Yes, I approve this request'),
        'reject' => $this->t('No, I do not approve this request'),
      ],
      '#required' => TRUE,
    ];

    $form['decision']['comments'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Comments (optional)'),
      '#description' => $this->t('Add any comments or concerns about this request.'),
      '#rows' => 4,
    ];

    // Hidden fields.
    $form['submission_id'] = [
      '#type' => 'hidden',
      '#value' => $submission->id(),
    ];

    $form['parent_number'] = [
      '#type' => 'hidden',
      '#value' => $parent_number,
    ];

    $form['token'] = [
      '#type' => 'hidden',
      '#value' => $token,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Decision'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Re-validate token to prevent CSRF attacks.
    $submission_id = $form_state->getValue('submission_id');
    $parent_number = $form_state->getValue('parent_number');
    $token = $form_state->getValue('token');

    if (!$this->tokenService->validateToken($submission_id, $parent_number, $token)) {
      $form_state->setErrorByName('token', $this->t('Security validation failed. Please try again or request a new approval link.'));
    }

    // Verify submission still exists and is in correct state.
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $submission = $storage->load($submission_id);

    if (!$submission) {
      $form_state->setErrorByName('submission_id', $this->t('The submission could not be found.'));
      return;
    }

    $status_field = "parent{$parent_number}_status";
    $current_status = $submission->getElementData($status_field);

    if (in_array($current_status, ['complete', 'rejected'])) {
      $form_state->setErrorByName('submission_id', $this->t('This request has already been processed.'));
      return;
    }

    // Defence in depth: re-verify the MitID session + CPR match at submit
    // time. The controller gate ran before this form was shown, but the
    // 15-minute MitID session may have expired since, and we never want a
    // stale or different identity to finalise the decision.
    $workflow_id = sprintf('parent_approval_%d_p%d', $submission_id, $parent_number);
    if (!$this->mitidSessionManager->hasValidSession($workflow_id)) {
      // The controller bound the verified (minted) MitID session to this
      // submission + parent in the server-side session; never read it from
      // the request. The CPR re-verification below still applies.
      $bound = (string) $this->getRequest()->getSession()->get("mitid_approval_workflow_parent{$parent_number}_{$submission_id}", '');
      if ($bound !== '') {
        $workflow_id = $bound;
      }
    }
    if (!$this->mitidSessionManager->hasValidSession($workflow_id)) {
      $form_state->setErrorByName('submission_id', $this->t('Your MitID session has expired. Please open the approval link again to re-authenticate.'));
      return;
    }
    $require_match = $this->config('aabenforms_workflows.settings')->get('require_parent_cpr_match') ?? TRUE;
    $cpr_result = $this->cprVerifier->verify($submission, (int) $parent_number, $workflow_id);
    $consent_ok = $cpr_result === ParentCprVerifier::RESULT_MATCH
      || (!$require_match && $cpr_result === ParentCprVerifier::RESULT_MISSING_EXPECTED_CPR);
    if (!$consent_ok) {
      $this->logger->warning('Parent approval submit blocked by CPR re-check: submission @sid parent @parent result @result', [
        '@sid' => $submission_id,
        '@parent' => $parent_number,
        '@result' => $cpr_result,
      ]);
      $form_state->setErrorByName('submission_id', $this->t('Security verification failed. Please contact the case worker.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $submission_id = $form_state->getValue('submission_id');
    $parent_number = $form_state->getValue('parent_number');
    $action = $form_state->getValue('action');
    $comments = $form_state->getValue('comments');

    // Load and update submission.
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $submission = $storage->load($submission_id);

    if (!$submission) {
      $this->messenger()->addError($this->t('An error occurred. Please contact support.'));
      return;
    }

    // Update parent status field.
    $status_field = "parent{$parent_number}_status";
    $new_status = $action === 'approve' ? 'complete' : 'rejected';
    $submission->setElementData($status_field, $new_status);

    // Save comments if provided.
    if ($comments) {
      $comment_field = "parent{$parent_number}_comments";
      $submission->setElementData($comment_field, $comments);
    }

    // Save submission.
    try {
      $submission->save();

      // Dispatch a one-shot ECA custom event for a recorded approval. The
      // identity and consent were already verified in validateForm() above,
      // so this fires exactly once per approval and the flow can record it
      // honestly - unlike the old content_entity:update trigger, which never
      // matched and would have re-fired on every later save.
      if ($action === 'approve') {
        $event = new ContentEntityCustomEvent(
          $submission,
          $this->entityTypes,
          sprintf('parent%d_approved', $parent_number),
          []
        );
        $this->eventDispatcher->dispatch($event, ContentEntityEvents::CUSTOM);
      }

      $this->logger->info('Parent @parent @action submission @sid', [
        '@parent' => $parent_number,
        '@action' => $new_status,
        '@sid' => $submission_id,
      ]);

      // A school transfer co-sign returns the parent to the frontend receipt.
      // The URL is built from the allowlisted frontend origin and a fixed
      // path, never from request input; the receipt itself is loaded with
      // the parent's own MitID session, not from this URL.
      $frontend = $this->frontendReturnUrl((string) $submission->getWebform()?->id(), (string) $action);
      if ($frontend !== NULL) {
        $form_state->setResponse(new TrustedRedirectResponse($frontend));
        return;
      }

      // Success message.
      $message = $action === 'approve'
        ? $this->t('Thank you. Your approval has been recorded.')
        : $this->t('Your response has been recorded. The case worker will be notified.');

      $this->messenger()->addStatus($message);

      // Redirect to confirmation page.
      $form_state->setRedirect('aabenforms_workflows.parent_approval_complete', [
        'action' => $action,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save approval: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while saving your response. Please try again or contact support.'));
    }
  }

  /**
   * The frontend return URL after a co-sign, or NULL for the Drupal page.
   *
   * @param string $webform_id
   *   The submission's webform id.
   * @param string $action
   *   The parent's action (approve or reject).
   *
   * @return string|null
   *   An absolute frontend URL, or NULL to keep the default completion page.
   */
  public function frontendReturnUrl(string $webform_id, string $action): ?string {
    if ($action !== 'approve' || !isset(self::FRONTEND_RETURN[$webform_id]) || $this->frontendUrl === NULL) {
      return NULL;
    }
    [$path, $query] = self::FRONTEND_RETURN[$webform_id];
    return $this->frontendUrl->build($path, $query);
  }

}
