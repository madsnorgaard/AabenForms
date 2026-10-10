<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Form;

use Drupal\aabenforms_workflows\Service\OrgChartServiceInterface;
use Drupal\aabenforms_workflows\Service\SchoolTransferCaseView;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The school leader approves or rejects a co-signed school transfer.
 *
 * The decision is written to the submission's caseworker_status and saved
 * through the normal entity save, so skoleskift_decision_flow runs as this
 * user and its aabenforms_resolve_employee gate decides whether the decision
 * stands. The form refuses up front when the user is not an employee in the
 * org chart, so a status is never set that the flow would then deny.
 */
class SchoolTransferDecisionForm extends FormBase {

  public function __construct(
    protected SchoolTransferCaseView $caseView,
    protected OrgChartServiceInterface $orgChart,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aabenforms_workflows.school_transfer_case_view'),
      $container->get('aabenforms_workflows.org_chart'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenforms_school_transfer_decision';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?WebformSubmissionInterface $webform_submission = NULL): array {
    if ($webform_submission === NULL || $webform_submission->getWebform()?->id() !== SchoolTransferCaseView::WEBFORM_ID) {
      throw new NotFoundHttpException();
    }
    $form_state->set('sid', (int) $webform_submission->id());
    $summary = $this->caseView->staffSummary($webform_submission);

    $form['#attributes']['data-testid'] = 'skoleskift-decision';
    $form['summary'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $summary['case_id']
        ? $this->t('Case #@id', ['@id' => $summary['case_id']])
        : $this->t('Application'),
    ];
    $items = [
      [$this->t('Child'), $summary['child_name'] . ' (' . $summary['child_cpr_masked'] . ')'],
      [$this->t('Current school'), $summary['current_school']],
      [$this->t('Requested school'), $summary['target_school']],
      [$this->t('Reason given by the parents'), $summary['reason']],
    ];
    foreach ($summary['signatures'] as $signature) {
      $label = $signature['role'] === 'applicant' ? $this->t('Applicant') : $this->t('Co-signer');
      $state = $signature['signed'] ? $this->t('signed with MitID') : $this->t('not signed');
      $who = trim($signature['name'] . ' (' . $signature['cpr_masked'] . ')');
      $items[] = [$label, $who . ' - ' . $state];
    }
    $rows = '';
    foreach ($items as [$label, $value]) {
      $rows .= '<dt>' . Html::escape((string) $label) . '</dt><dd>' . Html::escape((string) $value) . '</dd>';
    }
    $form['summary']['list'] = ['#markup' => '<dl class="skoleskift-summary">' . $rows . '</dl>'];

    if (!$this->caseView->isAwaitingDecision($webform_submission)) {
      $form['done'] = [
        '#markup' => '<p>' . $this->t('This application has already been decided or is not ready for a decision.') . '</p>',
      ];
      return $form;
    }

    $form['decision'] = [
      '#type' => 'radios',
      '#title' => $this->t('Decision'),
      '#required' => TRUE,
      '#options' => [
        'approved' => $this->t('Approve school transfer'),
        'rejected' => $this->t('Reject'),
      ],
    ];
    $form['rejection_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for the rejection'),
      '#description' => $this->t('Required when you reject. The parents can appeal the decision.'),
      '#rows' => 4,
      '#states' => [
        'visible' => [':input[name="decision"]' => ['value' => 'rejected']],
        'required' => [':input[name="decision"]' => ['value' => 'rejected']],
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send decision'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $submission = $this->loadSubmission($form_state);
    if ($submission === NULL || !$this->caseView->isAwaitingDecision($submission)) {
      $form_state->setErrorByName('decision', $this->t('This application has already been decided or is not ready for a decision.'));
      return;
    }
    if ($this->orgChart->employeeIdForAccountName($this->currentUser()->getAccountName()) === '') {
      $form_state->setErrorByName('decision', $this->t('Your account is not registered as an employee in the organisation, so you cannot make the decision.'));
      return;
    }
    $decision = (string) $form_state->getValue('decision');
    if (!in_array($decision, ['approved', 'rejected'], TRUE)) {
      $form_state->setErrorByName('decision', $this->t('Choose a decision.'));
    }
    if ($decision === 'rejected' && trim((string) $form_state->getValue('rejection_reason')) === '') {
      $form_state->setErrorByName('rejection_reason', $this->t('Give a reason for the rejection.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $submission = $this->loadSubmission($form_state);
    if ($submission === NULL) {
      return;
    }
    $decision = (string) $form_state->getValue('decision');
    $submission->setElementData('caseworker_status', $decision);
    $submission->setElementData('caseworker_comment', $decision === 'rejected'
      ? trim((string) $form_state->getValue('rejection_reason'))
      : '');
    // Normal entity save: skoleskift_decision_flow fires on this update and
    // runs as the current (staff) user.
    $submission->save();

    $this->logger('aabenforms_workflows')->info('School transfer @sid decided (@decision) by @user', [
      '@sid' => $submission->id(),
      '@decision' => $decision,
      '@user' => $this->currentUser()->getAccountName(),
    ]);
    $form_state->setRedirect('aabenforms_workflows.school_transfer_decided', [
      'webform_submission' => $submission->id(),
    ]);
  }

  /**
   * Reloads the submission bound to this form.
   */
  protected function loadSubmission(FormStateInterface $form_state): ?WebformSubmissionInterface {
    $sid = (int) $form_state->get('sid');
    if (!$sid) {
      return NULL;
    }
    // Fresh from storage, so a decision made meanwhile is seen. Not
    // loadUnchanged(): webform only attaches submission data on a normal
    // load, so reset the static cache and load instead.
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $storage->resetCache([$sid]);
    $submission = $storage->load($sid);
    return $submission instanceof WebformSubmissionInterface ? $submission : NULL;
  }

}
