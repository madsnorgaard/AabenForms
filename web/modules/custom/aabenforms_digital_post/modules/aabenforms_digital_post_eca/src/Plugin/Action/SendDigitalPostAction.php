<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_eca\Plugin\Action;

use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface;
use Drupal\aabenforms_workflows\Plugin\Action\AabenFormsActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Send Digital Post.
 *
 * Reads a recipient identifier from an ECA token, builds a DigitalPost
 * DTO using the configured sender, and dispatches it via the
 * aabenforms_digital_post.sender service. The send Result's
 * transactionId is written back to a token the flow can inspect.
 *
 * The plugin is intentionally thin - it does not know about SOAP, MeMo
 * XML, certificates, or test modes. All of that lives behind the
 * DigitalPostSender service boundary.
 */
#[Action(
  id: 'aabenforms_digital_post_send',
  label: new TranslatableMarkup('Send Digital Post'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Send a SF1601 Digital Post to a citizen (CPR) or company (CVR) via aabenforms_digital_post.'),
  version_introduced: '1.0.0',
)]
class SendDigitalPostAction extends AabenFormsActionBase {

  /**
   * The Digital Post sender service.
   *
   * @var \Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface
   */
  protected DigitalPostSenderInterface $sender;

  /**
   * The config factory used to resolve the default sender CVR.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setSender($container->get('aabenforms_digital_post.sender'));
    $instance->setConfigFactory($container->get('config.factory'));
    $instance->setCprAccess($container->get('aabenforms_core.cpr_access'));
    return $instance;
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
   * Setter injection for the Digital Post sender service.
   *
   * Public so unit tests can swap in a stub without reflection. The
   * production wiring goes through create() above.
   */
  public function setSender(DigitalPostSenderInterface $sender): void {
    $this->sender = $sender;
  }

  /**
   * Setter injection for the config factory.
   *
   * Public so unit tests can swap in a stub without reflection. The
   * production wiring goes through create() above.
   */
  public function setConfigFactory(ConfigFactoryInterface $configFactory): void {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'recipient_token' => '',
      'recipient_type' => 'cpr',
      'sender_cvr_token' => '',
      'subject_template' => 'Afgørelse',
      'body_template' => '<p>Se vedlagte bilag.</p>',
      'type' => DigitalPost::TYPE_DIGITAL_POST,
      'result_token' => 'digital_post_result',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['recipient_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient token'),
      '#description' => $this->t('ECA token resolving to the recipient identifier. Examples: [citizen_session:cpr], [webform_submission:values:cpr:raw]'),
      '#default_value' => $this->configuration['recipient_token'],
      '#required' => TRUE,
    ];
    $form['recipient_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Recipient type'),
      '#options' => [
        Recipient::TYPE_CPR => $this->t('CPR (citizen)'),
        Recipient::TYPE_CVR => $this->t('CVR (company)'),
      ],
      '#default_value' => $this->configuration['recipient_type'],
      '#required' => TRUE,
    ];
    $form['sender_cvr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender CVR (override)'),
      '#description' => $this->t('Leave empty to use the module default from aabenforms_digital_post.settings.'),
      '#default_value' => $this->configuration['sender_cvr_token'],
    ];
    $form['subject_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#description' => $this->t('Literal subject or [token]-containing template.'),
      '#default_value' => $this->configuration['subject_template'],
      '#required' => TRUE,
    ];
    $form['body_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body (HTML allowed for Digital Post)'),
      '#default_value' => $this->configuration['body_template'],
      '#required' => TRUE,
      '#rows' => 4,
    ];
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Delivery type'),
      '#options' => [
        DigitalPost::TYPE_DIGITAL_POST => $this->t('Digital Post only'),
        DigitalPost::TYPE_AUTOMATISK_VALG => $this->t('Automatisk Valg (digital with fjernprint fallback)'),
        DigitalPost::TYPE_FYSISK_POST => $this->t('Fysisk Post (physical mail only)'),
        DigitalPost::TYPE_NEM_SMS => $this->t('NemSMS'),
      ],
      '#default_value' => $this->configuration['type'],
    ];
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Token that receives the typed Result. Keys: success (bool), transaction_id, reason_code, message.'),
      '#default_value' => $this->configuration['result_token'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $keys = [
      'recipient_token',
      'recipient_type',
      'sender_cvr_token',
      'subject_template',
      'body_template',
      'type',
      'result_token',
    ];
    foreach ($keys as $key) {
      $this->configuration[$key] = (string) $form_state->getValue($key);
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $recipientRaw = $this->resolveRecipient();
    $recipientType = (string) $this->configuration['recipient_type'];

    // A CPR recipient read from a stored webform field is encrypted at rest;
    // decrypt it before addressing the Digital Post. CVR is not encrypted.
    if ($recipientType === Recipient::TYPE_CPR) {
      $recipientRaw = $this->cprAccess->reveal($recipientRaw);
    }

    if ($recipientRaw === '') {
      $this->recordStep(
        label: 'Digital Post skipped',
        description: 'Recipient not resolved. Common in demo mode without a real MitID session or when the webform field is empty.',
        status: 'skipped',
      );
      $this->log('Digital Post skipped: recipient empty');
      $this->setResultToken(status: 'failed', transactionId: '', reasonCode: 'RECIPIENT_EMPTY', message: 'Recipient not resolved.');
      return;
    }

    try {
      $recipient = $recipientType === Recipient::TYPE_CVR
        ? Recipient::cvr($recipientRaw)
        : Recipient::cpr($recipientRaw);

      $senderCvrOverride = $this->getTokenValue((string) $this->configuration['sender_cvr_token'], '');
      $sender = $senderCvrOverride !== ''
        ? new Sender(cvr: $senderCvrOverride)
        : Sender::fromConfig($this->configFactory);

      // Idempotency: derive a stable, PII-free transaction id from the
      // submission so a re-fired flow reuses the same id. The live transport's
      // transaction_id unique key + pre-send lookup then prevent a duplicate
      // official letter. Off-submission (no context), fall back to a generated
      // id (the sender does this when meta carries none).
      $submission = $this->getSubmission();
      $meta = [];
      if ($submission !== NULL) {
        $stableKey = $submission->uuid() . ':' . $this->getPluginId() . ':' . (string) $this->configuration['result_token'];
        $meta['transaction_id'] = 'dp_' . substr(hash('sha256', $stableKey), 0, 40);
      }

      $post = new DigitalPost(
        recipient: $recipient,
        sender: $sender,
        subject: $this->renderTemplate((string) $this->configuration['subject_template']),
        body: $this->renderTemplate((string) $this->configuration['body_template']),
        type: (string) $this->configuration['type'],
        meta: $meta,
      );

      $result = $this->sender->send($post);

      // Map the three-valued Result. A live send that was ACCEPTED (pending) is
      // NOT a failure: it advances the flow, and the async Beskedfordeler
      // receipt reconciles delivery later.
      $status = $result->isSuccess() ? 'success' : ($result->isPending() ? 'pending' : 'failed');
      $mode = $this->sender->testMode();
      $demo = in_array($mode, ['fake_db', 'wiremock'], TRUE);
      $description = $demo
        ? sprintf('Demo: Digital Post simuleret (%s).', $mode)
        : ($result->message ?: 'Digital Post afsendt.');
      $stepLabels = [
        'success' => 'Digital Post sent',
        'pending' => 'Digital Post accepted (awaiting receipt)',
        'failed' => 'Digital Post failed',
      ];
      $this->recordStep(
        label: $stepLabels[$status],
        description: $description,
        status: $status === 'failed' ? 'failed' : 'completed',
      );
      $this->setResultToken(
        status: $status,
        transactionId: $result->transactionId,
        reasonCode: $result->reasonCode,
        message: $result->message,
      );
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'SendDigitalPostAction');
      $this->setResultToken(
        status: 'failed',
        transactionId: '',
        reasonCode: 'VALIDATION',
        message: $e->getMessage(),
      );
      $this->recordStep(
        label: 'Digital Post failed',
        description: 'Validation error: ' . $e->getMessage(),
        status: 'failed',
      );
    }
  }

  /**
   * Resolve the recipient identifier.
   *
   * Strategy, in order:
   * 1. Parse the recipient_token config as `[webform_submission:values:FIELD:raw]`
   *    and read the field directly off the entity exposed by the triggering
   *    ECA event. This handles the common webform-submit case without
   *    depending on ECA's key-based token data registry.
   * 2. Fall back to tokenService->getTokenData() with the raw token string
   *    as a key. Handles session-backed tokens populated by earlier actions
   *    (e.g. MitIdValidateAction writing '[citizen_session:cpr]' semantics).
   * 3. Return '' if neither resolves.
   */
  private function resolveRecipient(): string {
    $token = (string) $this->configuration['recipient_token'];
    if ($token === '') {
      return '';
    }

    // Strategy 1: parse [webform_submission:values:FIELD:raw] and read
    // directly from the event's entity.
    if (preg_match('/^\[webform_submission:values:([a-zA-Z0-9_]+)(?::raw)?\]$/', $token, $m)) {
      $entity = $this->eventEntity();
      if ($entity !== NULL && method_exists($entity, 'getElementData')) {
        $value = $entity->getElementData($m[1]);
        if (is_string($value) && $value !== '') {
          return $value;
        }
      }
    }

    // Strategy 2: resolve a bracketed token via ECA token replacement. This
    // handles session-backed sub-keys like [building_session:cpr], where the
    // token environment holds DataType objects (not plain strings/arrays) that
    // getTokenData() cannot return directly - the recipient CPR comes from the
    // VERIFIED MitID session, never the spoofable submitted form value.
    if (str_contains($token, '[') && method_exists($this->tokenService, 'replaceClear')) {
      $replaced = $this->tokenService->replaceClear($token, []);
      if (is_string($replaced) && trim($replaced) !== '') {
        return trim($replaced);
      }
    }

    // Strategy 3: flat ECA token data registry lookup (plain string/array).
    $key = trim($token, '[]');
    $value = $this->tokenService->getTokenData($key);
    if (is_string($value)) {
      return $value;
    }
    if (is_array($value) && isset($value['cpr']) && is_string($value['cpr'])) {
      return $value['cpr'];
    }

    return '';
  }

  /**
   * Extract the content entity from the triggering ECA event, if any.
   */
  private function eventEntity(): ?object {
    if (!isset($this->event)) {
      return NULL;
    }
    foreach (['getEntity', 'getContext'] as $method) {
      if (method_exists($this->event, $method)) {
        $result = $this->event->{$method}();
        if (is_object($result) && method_exists($result, 'getElementData')) {
          return $result;
        }
      }
    }
    return NULL;
  }

  /**
   * Replaces [token] placeholders in a template.
   *
   * Only subject + body templates go through this; literal strings pass
   * through unchanged.
   */
  private function renderTemplate(string $template): string {
    if ($template === '' || !str_contains($template, '[')) {
      return $template;
    }
    return preg_replace_callback(
      '/\[([^\]]+)\]/',
      function ($m) {
        $value = $this->tokenService->getTokenData($m[1]);
        if (is_string($value) || is_numeric($value)) {
          return (string) $value;
        }
        return $m[0];
      },
      $template,
    );
  }

  /**
   * Writes the typed Result back into the configured ECA token.
   *
   * The `_status` companion carries the real three-valued outcome so a flow can
   * branch correctly: `success` (delivered via a mock rail), `pending` (a live
   * send was ACCEPTED by Serviceplatformen but not yet delivered - the case
   * should advance to await the async receipt, NOT be treated as failed), or
   * `failed`. Keys on the DTO token: success (bool), pending (bool), status,
   * transaction_id, reason_code, message.
   *
   * @param string $status
   *   One of 'success', 'pending', 'failed'.
   * @param string $transactionId
   *   The send transaction id.
   * @param string|null $reasonCode
   *   The failure reason code, or NULL.
   * @param string $message
   *   A human-readable message.
   */
  private function setResultToken(string $status, string $transactionId, ?string $reasonCode, string $message): void {
    $name = (string) $this->configuration['result_token'];
    if ($name === '') {
      return;
    }
    $this->setTokenValue($name, [
      'success' => $status === 'success',
      'pending' => $status === 'pending',
      'status' => $status,
      'transaction_id' => $transactionId,
      'reason_code' => $reasonCode,
      'message' => $message,
    ]);
    // Status-token companion so an eca_scalar gate can branch on the outcome
    // (a DTO token cannot be compared directly). A flow routes the case close
    // on '[<result_token>_status]' == 'success', records the Digital Post
    // reference on 'success' OR 'pending', and treats 'failed' as an error.
    $this->setTokenValue($name . '_status', $status);
  }

}
