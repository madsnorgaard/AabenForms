<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Plugin\Action;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;

/**
 * ECA Action: Transition a case to a new lifecycle state.
 *
 * Loads the case referenced by the configured token, validates that the move
 * is a lawful forward transition (AabenformsCase::allowedTransitions), and
 * saves a NEW revision carrying the transition log message - so the lifecycle
 * history is auditable (who/when/why). Rejects illegal transitions instead of
 * silently writing a bad state.
 */
#[Action(
  id: 'aabenforms_case_transition',
  label: new TranslatableMarkup('Transition case'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Moves a case to a new lawful lifecycle state, recording an auditable revision.'),
  version_introduced: '1.0.0',
)]
class TransitionCaseAction extends CaseActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'case_id_token' => 'case_id',
      'target_status' => '',
      'log_message' => 'Status ændret.',
      'result_token' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['case_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Case id token'),
      '#description' => $this->t('Token holding the case id, e.g. case_id or [case_id].'),
      '#default_value' => $this->configuration['case_id_token'],
      '#required' => TRUE,
    ];

    $form['target_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Target status'),
      '#options' => AabenformsCase::statusOptions(),
      '#default_value' => $this->configuration['target_status'],
      '#required' => TRUE,
    ];

    $form['log_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Revision log message'),
      '#default_value' => $this->configuration['log_message'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name (optional)'),
      '#description' => $this->t("When set, the token receives 'success' or 'failed' so a flow can gate follow-up actions on the transition actually happening (idempotent joins)."),
      '#default_value' => $this->configuration['result_token'],
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['case_id_token'] = $form_state->getValue('case_id_token');
    $this->configuration['target_status'] = $form_state->getValue('target_status');
    $this->configuration['log_message'] = $form_state->getValue('log_message');
    $this->configuration['result_token'] = (string) $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $caseId = $this->getTokenValue((string) ($this->configuration['case_id_token'] ?? 'case_id'), '');
      $target = (string) ($this->configuration['target_status'] ?? '');
      $logMessage = (string) ($this->configuration['log_message'] ?? 'Status ændret.');

      if ($caseId === '' || $target === '') {
        $this->setOutcome('failed');
        $this->recordStep('Sagsovergang', 'Mangler sags-id eller målstatus.', 'failed');
        return;
      }

      $storage = $this->entityTypeManager->getStorage('aabenforms_case');
      $case = $storage->load($caseId);
      if (!$case instanceof AabenformsCase) {
        $this->setOutcome('failed');
        $this->recordStep('Sagsovergang', sprintf('Sag #%s ikke fundet.', $caseId), 'failed');
        return;
      }

      $current = $case->getStatus();
      $allowed = AabenformsCase::allowedTransitions()[$current] ?? [];
      if (!in_array($target, $allowed, TRUE)) {
        $this->log('Illegal case transition {from}->{to} on case {id}', [
          'from' => $current,
          'to' => $target,
          'id' => $caseId,
        ], 'warning');
        $this->setOutcome('failed');
        $this->recordStep(
          'Sagsovergang afvist',
          sprintf('Ulovlig overgang %s -> %s.', $current, $target),
          'failed'
        );
        return;
      }

      $case->setStatus($target);
      // Force a new revision so the transition is auditable.
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage($logMessage);
      $case->setRevisionCreationTime($this->time->getRequestTime());
      $case->setRevisionUserId((int) $this->currentUser->id());
      $case->save();

      $this->setOutcome('success');
      $this->recordStep('Sagsovergang', sprintf('Sag #%s: %s -> %s.', $caseId, $current, $target));
      $this->auditLogger->log(
        'case_transition',
        (string) $caseId,
        sprintf('%s->%s', $current, $target),
        'success',
        ['action_id' => $this->getPluginId()]
      );
    }
    catch (\Throwable $e) {
      $this->setOutcome('failed');
      $this->handleError($e, 'Transition case');
    }
  }

  /**
   * Writes the transition outcome to the optional result token.
   *
   * @param string $outcome
   *   Either 'success' or 'failed'.
   */
  protected function setOutcome(string $outcome): void {
    $resultToken = (string) ($this->configuration['result_token'] ?? '');
    if ($resultToken !== '') {
      $this->setTokenValue($resultToken, $outcome);
    }
  }

}
