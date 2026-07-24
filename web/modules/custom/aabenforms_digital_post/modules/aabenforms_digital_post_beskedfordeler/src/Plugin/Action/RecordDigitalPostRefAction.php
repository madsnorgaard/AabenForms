<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_beskedfordeler\Plugin\Action;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_case\Plugin\Action\CaseActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;

/**
 * ECA Action: record the Digital Post transaction id on the case.
 *
 * Called by a flow right after aabenforms_digital_post_send so the pending
 * send becomes resolvable: it stamps the send transaction id and a `pending`
 * delivery status onto the case's queryable digital_post_tx field. When the
 * asynchronous Beskedfordeler receipt later arrives, DigitalPostReceiptHandler
 * finds the case by that id and finalises the outcome. Idempotent - a re-fired
 * flow does not overwrite an existing reference.
 */
#[Action(
  id: 'aabenforms_case_record_digital_post',
  label: new TranslatableMarkup('Record Digital Post reference on case'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Stamps the Digital Post transaction id + pending delivery status onto the case for later receipt reconciliation.'),
  version_introduced: '1.0.0',
)]
class RecordDigitalPostRefAction extends CaseActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'case_id_token' => 'case_id',
      'transaction_id_token' => '[digital_post_result:transaction_id]',
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
    $form['transaction_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction id token'),
      '#description' => $this->t('Token holding the send transaction id, e.g. [digital_post_result:transaction_id].'),
      '#default_value' => $this->configuration['transaction_id_token'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['case_id_token'] = $form_state->getValue('case_id_token');
    $this->configuration['transaction_id_token'] = $form_state->getValue('transaction_id_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $caseId = $this->getTokenValue((string) ($this->configuration['case_id_token'] ?? 'case_id'), '');
      $transactionId = $this->getTokenValue((string) ($this->configuration['transaction_id_token'] ?? ''), '');
      if ($caseId === '' || $transactionId === '') {
        $this->recordStep('Digital Post-reference', 'Mangler sags-id eller transaktions-id.', 'failed');
        return;
      }

      $case = $this->entityTypeManager->getStorage('aabenforms_case')->load($caseId);
      if (!$case instanceof AabenformsCase) {
        $this->recordStep('Digital Post-reference', sprintf('Sag #%s ikke fundet.', $caseId), 'failed');
        return;
      }

      // Idempotent: never overwrite an already-recorded reference.
      if ((string) $case->get('digital_post_tx')->value !== '') {
        $this->recordStep('Digital Post-reference', sprintf('Sag #%s har allerede en Digital Post-reference.', $caseId));
        return;
      }

      $case->set('digital_post_tx', $transactionId);
      $case->set('digital_post_receipt_status', 'pending');
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage('Digital Post afsendt (afventer kvittering).');
      $case->setRevisionCreationTime($this->time->getRequestTime());
      $case->setRevisionUserId((int) $this->currentUser->id());
      $case->save();

      $this->recordStep('Digital Post-reference', sprintf('Sag #%s knyttet til Digital Post-transaktion.', $caseId));
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'Record Digital Post reference');
    }
  }

}
