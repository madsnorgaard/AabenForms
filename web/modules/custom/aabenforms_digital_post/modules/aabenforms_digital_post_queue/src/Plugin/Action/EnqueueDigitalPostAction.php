<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_queue\Plugin\Action;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post_queue\Service\DigitalPostQueueDispatcher;
use Drupal\aabenforms_workflows\Plugin\Action\AabenFormsActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: enqueue a Digital Post for asynchronous, retryable delivery.
 *
 * The async twin of aabenforms_digital_post_send. Instead of calling
 * Serviceplatformen inline (which blocks the citizen's submit on the gov API's
 * uptime), it derives the stable idempotency id, enqueues the send onto the
 * self-draining resilience queue, records a `queued` step and sets the status
 * token to `queued`. The queue advances on cron with retry + dead-letter, then
 * stamps the case so the async Beskedfordeler receipt reconciles delivery -
 * the ECA flow rules and idempotency contracts are all preserved.
 */
#[Action(
  id: 'aabenforms_digital_post_enqueue',
  label: new TranslatableMarkup('Enqueue Digital Post (async)'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Enqueue a SF1601 Digital Post for asynchronous, retryable delivery via the resilience queue.'),
  version_introduced: '1.0.0',
)]
class EnqueueDigitalPostAction extends AabenFormsActionBase {

  /**
   * The queue dispatcher.
   *
   * @var \Drupal\aabenforms_digital_post_queue\Service\DigitalPostQueueDispatcher
   */
  protected DigitalPostQueueDispatcher $dispatcher;

  /**
   * The CPR access helper.
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->dispatcher = $container->get('aabenforms_digital_post_queue.dispatcher');
    $instance->cprAccess = $container->get('aabenforms_core.cpr_access');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
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
      'case_id_token' => 'case_id',
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
      '#default_value' => $this->configuration['recipient_token'],
      '#required' => TRUE,
    ];
    $form['recipient_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Recipient type'),
      '#options' => [Recipient::TYPE_CPR => 'CPR', Recipient::TYPE_CVR => 'CVR'],
      '#default_value' => $this->configuration['recipient_type'],
    ];
    $form['sender_cvr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender CVR (override)'),
      '#default_value' => $this->configuration['sender_cvr_token'],
    ];
    $form['subject_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->configuration['subject_template'],
      '#required' => TRUE,
    ];
    $form['body_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $this->configuration['body_template'],
      '#required' => TRUE,
    ];
    $form['case_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Case id token'),
      '#default_value' => $this->configuration['case_id_token'],
    ];
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#default_value' => $this->configuration['result_token'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $keys = [
      'recipient_token', 'recipient_type', 'sender_cvr_token', 'subject_template',
      'body_template', 'type', 'case_id_token', 'result_token',
    ];
    foreach ($keys as $key) {
      $this->configuration[$key] = (string) $form_state->getValue($key, $this->configuration[$key] ?? '');
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $recipientType = (string) $this->configuration['recipient_type'];
      $recipientRaw = $this->getTokenValue((string) $this->configuration['recipient_token'], '');
      if ($recipientType === Recipient::TYPE_CPR && $recipientRaw !== '') {
        $recipientRaw = $this->cprAccess->reveal($recipientRaw);
      }
      if ($recipientRaw === '') {
        $this->recordStep('Digital Post queued', 'Recipient not resolved; nothing enqueued.', 'skipped');
        $this->setTokenValue((string) $this->configuration['result_token'] . '_status', 'failed');
        return;
      }

      $recipient = $recipientType === Recipient::TYPE_CVR
        ? Recipient::cvr($recipientRaw)
        : Recipient::cpr($recipientRaw);
      $senderOverride = $this->getTokenValue((string) $this->configuration['sender_cvr_token'], '');
      $sender = $senderOverride !== '' ? new Sender(cvr: $senderOverride) : Sender::fromConfig($this->configFactory);

      $post = new DigitalPost(
        recipient: $recipient,
        sender: $sender,
        subject: $this->renderTemplate((string) $this->configuration['subject_template']),
        body: $this->renderTemplate((string) $this->configuration['body_template']),
        type: (string) $this->configuration['type'],
      );

      // Stable idempotency id from the submission, matching the synchronous path.
      $submission = $this->getSubmission();
      $stableKey = $submission !== NULL
        ? $submission->uuid() . ':' . $this->getPluginId() . ':' . (string) $this->configuration['result_token']
        : uniqid('', TRUE);
      $transactionId = 'dp_' . substr(hash('sha256', $stableKey), 0, 40);

      $caseId = $this->getTokenValue((string) $this->configuration['case_id_token'], '');
      $this->dispatcher->enqueue($post, $transactionId, $caseId !== '' ? $caseId : NULL);

      $this->recordStep('Digital Post queued', 'Afsendelse lagt i kø til robust levering (kvittering afventes).');
      $this->setTokenValue((string) $this->configuration['result_token'] . '_status', 'queued');
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'EnqueueDigitalPostAction');
      $this->setTokenValue((string) $this->configuration['result_token'] . '_status', 'failed');
    }
  }

  /**
   * Renders a literal-or-[token] template through the ECA token service.
   */
  protected function renderTemplate(string $template): string {
    if (!str_contains($template, '[')) {
      return $template;
    }
    $rendered = $this->tokenService->replaceClear($template, []);
    return is_string($rendered) ? $rendered : $template;
  }

}
