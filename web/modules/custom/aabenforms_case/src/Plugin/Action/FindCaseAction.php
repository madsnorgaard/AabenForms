<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Find the case opened for the current submission.
 *
 * Cross-event flows (a co-signature arriving days later, a caseworker
 * decision on submission update) run in a fresh token environment where the
 * [case_id] written by the original insert flow no longer exists. This
 * action re-resolves the case from the submission reference so downstream
 * case actions (transition, decide, journal) can run in any event context.
 */
#[Action(
  id: 'aabenforms_case_find',
  label: new TranslatableMarkup('Find Case for Submission'),
  type: 'entity',
)]
#[EcaAction(
  description: new TranslatableMarkup('Resolves the case opened for the current webform submission and writes its id to a token.'),
  version_introduced: '2.1.0',
)]
class FindCaseAction extends CaseActionBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'result_token' => 'case_id',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Token receiving the case id. A companion &lt;name&gt;_status carries found or not_found.'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $resultToken = (string) $this->configuration['result_token'];
    $submission = $this->getSubmission();

    if ($submission === NULL) {
      $this->setTokenValue($resultToken, '');
      $this->setTokenValue($resultToken . '_status', 'not_found');
      $this->recordStep('Find Case', 'Skipped - no webform submission in scope', 'skipped');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('aabenforms_case');
    $ids = $storage->getQuery()
      ->condition('submission_ref', $submission->id())
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      $this->log('No case found for submission {sid}', ['sid' => $submission->id()], 'warning');
      $this->setTokenValue($resultToken, '');
      $this->setTokenValue($resultToken . '_status', 'not_found');
      $this->recordStep('Find Case', 'No case is registered for this submission', 'failed');
      return;
    }

    $caseId = (string) reset($ids);
    $this->setTokenValue($resultToken, $caseId);
    $this->setTokenValue($resultToken . '_status', 'found');
    $this->recordStep('Find Case', sprintf('Case #%s resolved for the submission', $caseId));
  }

}
