<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Plugin\Action;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_case\Service\FristClock;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: start the appeal deadline (klagefrist) clock on a decided case.
 *
 * The klagefrist must run from when the citizen is notified, not from the
 * decision itself (FVL meddelelseskrav) - so a flow runs this only on the
 * branch where the decision letter was confirmed dispatched. Kept separate
 * from MakeDecisionAction, which records the decision but no longer stamps the
 * deadline. Only valid while the case is in "afgoerelse".
 */
#[Action(
  id: 'aabenforms_case_set_klagefrist',
  label: new TranslatableMarkup('Start appeal deadline (klagefrist)'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Starts the klagefrist clock on a decided case once the decision letter is confirmed dispatched.'),
  version_introduced: '1.1.0',
)]
class SetKlagefristAction extends CaseActionBase {

  /**
   * The deadline clock (owns the klagefrist arithmetic).
   */
  protected FristClock $fristClock;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->fristClock = $container->get('aabenforms_case.frist_clock');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'case_id_token' => '[case_id]',
      'klagefrist_uger' => 4,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['case_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Case id token'),
      '#default_value' => $this->configuration['case_id_token'],
      '#required' => TRUE,
    ];
    $form['klagefrist_uger'] = [
      '#type' => 'number',
      '#title' => $this->t('Appeal deadline (weeks)'),
      '#min' => 1,
      '#default_value' => $this->configuration['klagefrist_uger'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['case_id_token'] = $form_state->getValue('case_id_token');
    $this->configuration['klagefrist_uger'] = $form_state->getValue('klagefrist_uger');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $caseId = $this->getTokenValue((string) ($this->configuration['case_id_token'] ?? '[case_id]'), '');
      $uger = max(1, (int) ($this->configuration['klagefrist_uger'] ?? 4));
      if ($caseId === '') {
        $this->recordStep('Klagefrist', 'Mangler sags-id.', 'failed');
        return;
      }

      $case = $this->entityTypeManager->getStorage('aabenforms_case')->load($caseId);
      if (!$case instanceof AabenformsCase) {
        $this->recordStep('Klagefrist', sprintf('Sag #%s ikke fundet.', $caseId), 'failed');
        return;
      }

      if ($case->getStatus() !== 'afgoerelse') {
        $this->recordStep('Klagefrist', sprintf('Sag #%s er ikke afgjort - klagefrist kan ikke sættes.', $caseId), 'failed');
        return;
      }

      // Idempotent: keep an existing klagefrist.
      if ((int) ($case->get('klagefrist')->value ?? 0) > 0) {
        $this->recordStep('Klagefrist', sprintf('Sag #%s har allerede en klagefrist.', $caseId));
        return;
      }

      $now = $this->time->getRequestTime();
      $case->set('klagefrist', $this->fristClock->computeKlagefrist($now, $uger));
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage(sprintf('Klagefrist startet (afgørelsesbrev afsendt): %d uger.', $uger));
      $case->setRevisionCreationTime($now);
      $case->setRevisionUserId((int) $this->currentUser->id());
      $case->save();

      $this->recordStep('Klagefrist', sprintf('Sag #%s: klagefrist på %d uger startet.', $caseId, $uger));
      $this->auditLogger->log('case_klagefrist', (string) $caseId, sprintf('%d uger', $uger), 'success', [
        'action_id' => $this->getPluginId(),
      ]);
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'Set klagefrist');
    }
  }

}
