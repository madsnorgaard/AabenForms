<?php

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\aabenforms_workflows\Service\ApprovalTokenService;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Drupal\eca\Plugin\ECA\PluginFormTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends parent approval email with secure approval link.
 */
#[Action(
  id: 'aabenforms_send_approval_email',
  label: new TranslatableMarkup('Send Parent Approval Email'),
  type: 'entity',
)]
#[EcaAction(
  description: new TranslatableMarkup('Sends approval email to parent with secure MitID login link'),
  version_introduced: '2.0.0',
)]
class SendApprovalEmailAction extends AabenFormsActionBase {

  use PluginFormTrait;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The approval token service.
   *
   * @var \Drupal\aabenforms_workflows\Service\ApprovalTokenService
   */
  protected ApprovalTokenService $approvalTokenService;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->approvalTokenService = $container->get('aabenforms_workflows.approval_token');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->languageManager = $container->get('language_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'parent_number' => '1',
      'email_field' => 'parent1_email',
      'submission_token' => 'webform_submission',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['parent_number'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent Number'),
      '#description' => $this->t('Which parent to send email to.'),
      '#options' => [
        '1' => $this->t('Parent 1'),
        '2' => $this->t('Parent 2'),
      ],
      '#default_value' => $this->configuration['parent_number'],
      '#required' => TRUE,
    ];

    $form['email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email Field Name'),
      '#description' => $this->t('The webform field containing parent email address.'),
      '#default_value' => $this->configuration['email_field'],
      '#required' => TRUE,
    ];

    $form['submission_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submission Token Name'),
      '#description' => $this->t('Token name for webform submission entity.'),
      '#default_value' => $this->configuration['submission_token'],
      '#required' => TRUE,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['parent_number'] = $form_state->getValue('parent_number');
    $this->configuration['email_field'] = $form_state->getValue('email_field');
    $this->configuration['submission_token'] = $form_state->getValue('submission_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $parent_number = (int) $this->configuration['parent_number'];
      $email_field = $this->configuration['email_field'];
      $submission_token = $this->configuration['submission_token'];

      // Get submission from token.
      $submission = $this->getTokenValue($submission_token, '');

      if (!$submission || !method_exists($submission, 'getElementData')) {
        $this->log('Invalid submission object in token: {token}', [
          'token' => $submission_token,
        ], 'error');
        return;
      }

      // Get parent email.
      $parent_email = $submission->getElementData($email_field);

      if (!$parent_email || !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
        $this->log('Invalid parent email: {email}', [
          'email' => $parent_email,
        ], 'error');
        return;
      }

      // Generate secure token.
      $token = $this->approvalTokenService->generateToken($submission->id(), $parent_number);

      // Build approval URL.
      $approval_url = \Drupal::url(
        'aabenforms_workflows.parent_approval',
        [
          'parent_number' => $parent_number,
          'submission_id' => $submission->id(),
          'token' => $token,
        ],
        ['absolute' => TRUE]
      );

      // Prepare email parameters.
      $params = [
        'parent_number' => $parent_number,
        'child_name' => $submission->getElementData('child_name'),
        'request_details' => $submission->getElementData('request_details'),
        'approval_url' => $approval_url,
        'deadline' => $this->dateFormatter->format(
          strtotime('+14 days'),
          'long'
        ),
        'submission_id' => $submission->id(),
      ];

      // Send email.
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      $result = $this->mailManager->mail(
        'aabenforms_workflows',
        'parent_approval',
        $parent_email,
        $langcode,
        $params,
        NULL,
        TRUE
      );

      if ($result['result']) {
        $this->log('Approval email sent to parent @parent at @email for submission @sid', [
          '@parent' => $parent_number,
          '@email' => $parent_email,
          '@sid' => $submission->id(),
        ], 'info');
        $this->recordStep('Approval Email Sent', 'Secure approval link sent to parent via email');
      }
      else {
        $this->log('Failed to send approval email to @email', [
          '@email' => $parent_email,
        ], 'error');
      }
    }
    catch (\Exception $e) {
      $this->handleError($e, 'Sending parent approval email');
    }
  }

}
