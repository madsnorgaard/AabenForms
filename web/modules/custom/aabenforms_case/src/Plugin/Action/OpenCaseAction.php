<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Plugin\Action;

use Drupal\aabenforms_case\Service\CaseLookup;
use Drupal\aabenforms_case\Service\FristClock;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Open a case from the current submission.
 *
 * Creates an aabenforms_case referencing the webform submission, stamps the
 * receipt date (now), computes the deadline via the FristClock for the
 * configured area, and starts the case in the "modtaget" state. The new case
 * id is written to the [case_id] token so later actions (transition, journal,
 * SF2900 distribute) can act on it.
 *
 * Privacy: the case stores only a reference to the submission, never the raw
 * CPR - that stays encrypted on the submission. What it does carry is a
 * SHA-256 of the applicant CPR (subject_cpr_hash) so the citizen can later be
 * offered "my cases" without any decryption, see CaseLookup.
 */
#[Action(
  id: 'aabenforms_case_open',
  label: new TranslatableMarkup('Open case'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Opens a persistent case from the submission, with a deadline (frist) clock.'),
  version_introduced: '1.0.0',
)]
class OpenCaseAction extends CaseActionBase {

  /**
   * The deadline clock.
   */
  protected FristClock $fristClock;

  /**
   * The case-by-CPR index helper.
   */
  protected CaseLookup $caseLookup;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->fristClock = $container->get('aabenforms_case.frist_clock');
    $instance->caseLookup = $container->get('aabenforms_case.case_lookup');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'case_type' => 'sag',
      'case_id_token' => 'case_id',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['case_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Case type / area'),
      '#description' => $this->t('Casework area key, e.g. "underretning" or "friplads". Drives the deadline lookup in aabenforms_case.settings.'),
      '#default_value' => $this->configuration['case_type'],
      '#required' => TRUE,
    ];

    $form['case_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token (case id)'),
      '#description' => $this->t('Token name to receive the new case id, for later actions.'),
      '#default_value' => $this->configuration['case_id_token'],
      '#required' => TRUE,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['case_type'] = $form_state->getValue('case_type');
    $this->configuration['case_id_token'] = $form_state->getValue('case_id_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $submission = $this->getSubmission();
      $caseType = trim((string) ($this->configuration['case_type'] ?? 'sag')) ?: 'sag';
      $resultToken = trim((string) ($this->configuration['case_id_token'] ?? 'case_id')) ?: 'case_id';

      $now = $this->time->getRequestTime();
      $due = $this->fristClock->computeDue($now, $caseType);

      $submissionId = $submission?->id();
      $title = sprintf(
        '%s - indsendelse %s',
        ucfirst($caseType),
        $submissionId !== NULL ? '#' . $submissionId : 'uden submission'
      );

      $storage = $this->entityTypeManager->getStorage('aabenforms_case');

      // Idempotency: never open a second case for the same submission. A
      // content_entity:insert event can fire more than once (retry, re-save,
      // resubmission), and a duplicate case would mean two frist clocks, two
      // ambiguous [case_id] tokens, and double journalising/distribution.
      if ($submissionId !== NULL) {
        $existingIds = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('submission_ref', $submissionId)
          ->range(0, 1)
          ->execute();
        if ($existingIds !== []) {
          $existingId = (string) reset($existingIds);
          $this->setTokenValue($resultToken, $existingId);
          $this->recordStep('Sag findes', sprintf('Sag #%s findes allerede for indsendelse #%s.', $existingId, $submissionId));
          return;
        }
      }

      $case = $storage->create([
        'title' => $title,
        'case_type' => $caseType,
        'status' => 'modtaget',
        'submission_ref' => $submissionId,
        'modtagelsesdato' => $now,
        'frist_due' => $due,
        // Hash-only subject index (never the CPR itself), so the klage form
        // can offer the citizen their own cases. NULL when the form has no
        // applicant CPR.
        'subject_cpr_hash' => $submission !== NULL ? $this->caseLookup->subjectHashFromSubmission($submission) : NULL,
      ]);
      // Attribute the founding revision (who opened the case), like every
      // transition revision does.
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage('Sag oprettet fra indsendelse.');
      $case->setRevisionCreationTime($now);
      $case->setRevisionUserId((int) $this->currentUser->id());
      $case->save();

      $this->setTokenValue($resultToken, (string) $case->id());
      $this->recordStep(
        'Sag oprettet',
        sprintf('Sag #%s (%s) oprettet med frist.', $case->id(), $caseType)
      );

      $this->auditLogger->log(
        'case_opened',
        (string) $case->id(),
        $caseType,
        'success',
        [
          'action_id' => $this->getPluginId(),
          'submission_id' => $submissionId,
        ]
      );
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'Open case');
    }
  }

}
