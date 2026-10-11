<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tells the case handler at work that a case awaits their decision.
 *
 * Sent to the handler's work e-mail (e.g. the school leader from the
 * institution registry). E-mail is not a secure channel, so the message is
 * fixed text in hook_mail with only the case number and a link to the
 * decision list: no names, no CPR, nothing a flow can inject. The handler
 * logs in with their staff account to see the case.
 */
#[Action(
  id: 'aabenforms_notify_case_handler',
  label: new TranslatableMarkup('Notify case handler by e-mail'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('E-mails the case handler (no personal data) that a case awaits their decision, with a link to the decision list.'),
  version_introduced: '1.2.0',
)]
class NotifyCaseHandlerAction extends AabenFormsActionBase {

  /**
   * The mail manager.
   */
  protected MailManagerInterface $mailManager;

  /**
   * The e-mail validator.
   */
  protected EmailValidatorInterface $emailValidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->emailValidator = $container->get('email.validator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'recipient_token' => '[target_institution_leader_email]',
      'case_id_token' => '[skoleskift_case_id]',
      'link_path' => '/sagsbehandling/skoleskift',
      'result_token' => 'case_handler_notice',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['recipient_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient e-mail token'),
      '#default_value' => $this->configuration['recipient_token'],
      '#required' => TRUE,
    ];
    $form['case_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Case id token'),
      '#default_value' => $this->configuration['case_id_token'],
      '#required' => TRUE,
    ];
    $form['link_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Decision list path'),
      '#description' => $this->t('A path on this site, e.g. /sagsbehandling/skoleskift.'),
      '#default_value' => $this->configuration['link_path'],
      '#required' => TRUE,
    ];
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach (['recipient_token', 'case_id_token', 'link_path', 'result_token'] as $key) {
      $this->configuration[$key] = (string) $form_state->getValue($key);
    }
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $resultToken = (string) ($this->configuration['result_token'] ?: 'case_handler_notice');
    $recipient = trim((string) $this->getTokenValue((string) $this->configuration['recipient_token'], ''));
    $caseId = trim((string) $this->getTokenValue((string) $this->configuration['case_id_token'], ''));

    if ($recipient === '' || !$this->emailValidator->isValid($recipient)) {
      $this->setTokenValue($resultToken . '_status', 'skipped');
      $this->recordStep('Skoleleder ikke underrettet', 'Ingen gyldig mail i institutionsregistret. Sagen skal fordeles manuelt.', 'skipped');
      return;
    }

    $path = (string) $this->configuration['link_path'];
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
      $this->setTokenValue($resultToken . '_status', 'failed');
      $this->recordStep('Skoleleder ikke underrettet', 'Ugyldigt link i flowets opsætning.', 'failed');
      return;
    }
    $link = Url::fromUri('base:' . ltrim($path, '/'), ['absolute' => TRUE])->toString();

    $result = $this->mailManager->mail('aabenforms_workflows', 'case_handler_notice', $recipient, 'da', [
      'case_id' => ctype_digit($caseId) ? $caseId : '',
      'link' => $link,
    ]);
    if (empty($result['result'])) {
      $this->setTokenValue($resultToken . '_status', 'failed');
      $this->recordStep('Skoleleder ikke underrettet', 'Mailen kunne ikke sendes. Sagen skal fordeles manuelt.', 'failed');
      return;
    }
    $this->setTokenValue($resultToken . '_status', 'sent');
    $this->recordStep('Skoleleder underrettet', 'Besked sendt til skolelederens arbejdsmail med link til afgørelseslisten (uden personoplysninger).');
  }

}
