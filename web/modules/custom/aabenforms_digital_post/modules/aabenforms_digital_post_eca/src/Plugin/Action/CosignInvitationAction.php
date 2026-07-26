<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_eca\Plugin\Action;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface;
use Drupal\aabenforms_workflows\Plugin\Action\AabenFormsActionBase;
use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Send a co-signature (medunderskrift) invitation.
 *
 * The Digital Post generalization of the parent-approval invitation,
 * following the national co-sign template (borger.dk Omsorgs- og
 * ansvarserklaering): the co-signer receives a Digital Post message with a
 * secure link, authenticates with MitID, and approves within 14 days. The
 * link reuses the ENTIRE existing parent-approval machinery (token service,
 * controller, MitID step-up, CPR gate + opt-in custody gate), so any webform
 * carrying the parent<N>_cpr / parent<N>_status field convention gets
 * co-signing without new plumbing.
 *
 * Channel selection mirrors the forloeb convention: a CPR recipient gets
 * Digital Post; when no CPR is present (or the send is skipped in demo), the
 * email fallback uses the existing parent_approval mail template.
 */
#[Action(
  id: 'aabenforms_send_cosign_invitation',
  label: new TranslatableMarkup('Send Co-sign Invitation'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Sends a co-signature invitation with a secure MitID approval link via Digital Post (CPR) with email fallback.'),
  version_introduced: '2.1.0',
)]
class CosignInvitationAction extends AabenFormsActionBase {

  /**
   * The Digital Post sender service.
   *
   * @var \Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface
   */
  protected DigitalPostSenderInterface $sender;

  /**
   * The approval token service.
   *
   * @var \Drupal\aabenforms_workflows\Service\ApprovalTokenService
   */
  protected ApprovalTokenService $approvalTokenService;

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * The mail manager (email fallback channel).
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The config factory (default sender CVR).
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setSender($container->get('aabenforms_digital_post.sender'));
    $instance->setApprovalTokenService($container->get('aabenforms_workflows.approval_token'));
    $instance->setCprAccess($container->get('aabenforms_core.cpr_access'));
    $instance->setMailManager($container->get('plugin.manager.mail'));
    $instance->setConfigFactory($container->get('config.factory'));
    return $instance;
  }

  /**
   * Setter injection for the config factory.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setConfigFactory(ConfigFactoryInterface $configFactory): void {
    $this->configFactory = $configFactory;
  }

  /**
   * Setter injection for the Digital Post sender.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setSender(DigitalPostSenderInterface $sender): void {
    $this->sender = $sender;
  }

  /**
   * Setter injection for the approval token service.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setApprovalTokenService(ApprovalTokenService $service): void {
    $this->approvalTokenService = $service;
  }

  /**
   * Setter injection for the CPR access helper.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setCprAccess(CprAccess $cprAccess): void {
    $this->cprAccess = $cprAccess;
  }

  /**
   * Setter injection for the mail manager.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setMailManager(MailManagerInterface $mailManager): void {
    $this->mailManager = $mailManager;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'parent_number' => '2',
      'cpr_field' => 'parent2_cpr',
      'email_field' => 'parent2_email',
      'child_name_field' => 'child_name',
      'subject_template' => 'Anmodning om medunderskrift',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['parent_number'] = [
      '#type' => 'select',
      '#title' => $this->t('Co-signer slot'),
      '#description' => $this->t('Which parent<N> field set and approval slot the invitation is for.'),
      '#options' => ['1' => $this->t('Slot 1'), '2' => $this->t('Slot 2')],
      '#default_value' => $this->configuration['parent_number'],
      '#required' => TRUE,
    ];
    $form['cpr_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Co-signer CPR field'),
      '#description' => $this->t('Webform field holding the co-signer CPR (Digital Post channel). Leave the value empty on the submission to fall back to email.'),
      '#default_value' => $this->configuration['cpr_field'],
      '#required' => TRUE,
    ];
    $form['email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fallback email field'),
      '#description' => $this->t('Webform field holding the co-signer email, used when no CPR is present or the Digital Post send fails.'),
      '#default_value' => $this->configuration['email_field'],
      '#required' => TRUE,
    ];
    $form['child_name_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Child name field'),
      '#default_value' => $this->configuration['child_name_field'],
    ];
    $form['subject_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->configuration['subject_template'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach (['parent_number', 'cpr_field', 'email_field', 'child_name_field', 'subject_template'] as $key) {
      $this->configuration[$key] = (string) $form_state->getValue($key);
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $submission = $this->getSubmission();
    if ($submission === NULL) {
      $this->recordStep('Co-sign Invitation', 'Skipped - no webform submission in scope', 'skipped');
      $this->log('Co-sign invitation skipped: no submission resolved', [], 'warning');
      return;
    }

    $slot = (int) $this->configuration['parent_number'];
    $token = $this->approvalTokenService->generateToken((int) $submission->id(), $slot);
    $approvalUrl = $this->buildApprovalUrl($slot, (int) $submission->id(), $token);

    $childName = (string) ($submission->getElementData((string) $this->configuration['child_name_field']) ?? '');
    $cpr = $this->cprAccess->reveal((string) ($submission->getElementData((string) $this->configuration['cpr_field']) ?? ''));
    $cpr = $cpr ? (preg_replace('/[^0-9]/', '', $cpr) ?? '') : '';

    if ($cpr !== '') {
      try {
        $result = $this->sender->send(new DigitalPost(
          recipient: Recipient::cpr($cpr),
          sender: Sender::fromConfig($this->configFactory),
          subject: (string) $this->configuration['subject_template'],
          body: $this->buildLetterBody($childName, $approvalUrl),
          type: DigitalPost::TYPE_DIGITAL_POST,
          meta: [
            'transaction_id' => 'cosign_' . substr(hash('sha256', $submission->uuid() . ':' . $this->getPluginId() . ':' . $slot), 0, 40),
          ],
        ));
        if ($result->isSuccess() || $result->isPending()) {
          $this->recordStep('Co-sign Invitation Sent', 'Secure co-signature link sent via Digital Post (14-day window)');
          return;
        }
        $this->log('Co-sign Digital Post failed ({reason}); falling back to email', [
          'reason' => (string) $result->reasonCode,
        ], 'warning');
      }
      catch (\Throwable $e) {
        $this->log('Co-sign Digital Post error; falling back to email: {message}', [
          'message' => $e->getMessage(),
        ], 'warning');
      }
    }

    $this->sendEmailFallback($submission, $slot, $childName, $approvalUrl, $cpr === '');
  }

  /**
   * Sends the invitation via the email fallback channel.
   *
   * @param \Drupal\webform\WebformSubmissionInterface|object $submission
   *   The webform submission.
   * @param int $slot
   *   The co-signer slot.
   * @param string $childName
   *   The child's name for the template.
   * @param string $approvalUrl
   *   The secure approval URL.
   * @param bool $noCpr
   *   Whether the fallback ran because no CPR was present (vs a failed send).
   */
  protected function sendEmailFallback(object $submission, int $slot, string $childName, string $approvalUrl, bool $noCpr): void {
    $email = (string) ($submission->getElementData((string) $this->configuration['email_field']) ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->recordStep('Co-sign Invitation', 'Failed - no valid Digital Post recipient or fallback email on the submission', 'failed');
      $this->log('Co-sign invitation failed: no channel available for slot {slot}', ['slot' => $slot], 'error');
      return;
    }

    $result = $this->mailManager->mail(
      'aabenforms_workflows',
      'parent_approval',
      $email,
      'en',
      [
        'parent_number' => $slot,
        'child_name' => $childName,
        'request_details' => '',
        'approval_url' => $approvalUrl,
        'deadline' => date('d-m-Y', strtotime('+14 days')),
        'submission_id' => $submission->id(),
      ],
      NULL,
      TRUE,
    );

    if (!empty($result['result'])) {
      $this->recordStep(
        'Co-sign Invitation Sent',
        $noCpr ? 'Secure co-signature link sent via email (no CPR on submission)' : 'Secure co-signature link sent via email (Digital Post unavailable)',
      );
    }
    else {
      $this->recordStep('Co-sign Invitation', 'Failed - email fallback did not send', 'failed');
    }
  }

  /**
   * Builds the absolute approval URL for the co-signer link.
   *
   * Protected so unit tests can override it - Url::fromRoute needs the
   * routed container which unit tests do not bootstrap.
   *
   * @param int $slot
   *   The co-signer slot.
   * @param int $submissionId
   *   The submission id.
   * @param string $token
   *   The signed approval token.
   *
   * @return string
   *   The absolute URL.
   */
  protected function buildApprovalUrl(int $slot, int $submissionId, string $token): string {
    return Url::fromRoute('aabenforms_workflows.parent_approval', [
      'parent_number' => $slot,
      'submission_id' => $submissionId,
      'token' => $token,
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Builds the Danish Digital Post letter body.
   *
   * @param string $childName
   *   The child's name, or ''.
   * @param string $approvalUrl
   *   The secure approval URL.
   *
   * @return string
   *   HTML body for the MeMo message.
   */
  protected function buildLetterBody(string $childName, string $approvalUrl): string {
    $regarding = $childName !== ''
      ? sprintf('<p>Der er indsendt en anmodning, som vedroerer %s, og som kraever din medunderskrift som foraeldremyndighedsindehaver.</p>', htmlspecialchars($childName, ENT_QUOTES, 'UTF-8'))
      : '<p>Der er indsendt en anmodning, som kraever din medunderskrift som foraeldremyndighedsindehaver.</p>';
    return $regarding
      . sprintf('<p>Aabn linket og log ind med MitID for at se anmodningen og afgive din beslutning:</p><p><a href="%s">Se og underskriv anmodningen</a></p>', htmlspecialchars($approvalUrl, ENT_QUOTES, 'UTF-8'))
      . '<p>Linket er gyldigt i 14 dage. Reagerer du ikke inden fristen, behandles sagen manuelt af kommunen.</p>';
  }

}
