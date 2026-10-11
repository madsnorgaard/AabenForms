<?php

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\aabenforms_workflows\Service\OrgChartServiceInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Resolve and bind the employee identity.
 *
 * Internal and HR flows previously routed money and tasks on a
 * self-asserted employee_id form field. This action derives the employee
 * identifier from the authenticated account via the org-chart directory
 * and writes it to a result token, plus a companion status token
 * (verified | failed) so the flow can gate on it. Anonymous submissions,
 * or accounts that map to no employee, resolve to failed.
 */
#[Action(
  id: 'aabenforms_resolve_employee',
  label: new TranslatableMarkup('Resolve employee identity'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Binds the submission to the authenticated employee via the org-chart directory.'),
  version_introduced: '2.1.0',
)]
class ResolveEmployeeAction extends AabenFormsActionBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $account;

  /**
   * The org-chart directory.
   *
   * @var \Drupal\aabenforms_workflows\Service\OrgChartServiceInterface
   */
  protected OrgChartServiceInterface $orgChart;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->account = $container->get('current_user');
    $instance->orgChart = $container->get('aabenforms_workflows.org_chart');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'submitted_employee_id_token' => '[webform_submission:values:employee_id:raw]',
      'result_token' => 'employee_id',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['submitted_employee_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submitted employee id token'),
      '#description' => $this->t('Self-asserted employee id from the form. Used only to detect a mismatch; the authenticated identity is authoritative.'),
      '#default_value' => $this->configuration['submitted_employee_id_token'],
    ];
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Receives the resolved employee id. A companion <token>_status (verified/failed) is also set.'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['submitted_employee_id_token'] = $form_state->getValue('submitted_employee_id_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    if ($this->account->isAnonymous()) {
      $this->setResult('', 'failed');
      $this->recordStep('Employee identity', 'Afvist: interne anmodninger kræver, at medarbejderen er logget ind.', 'failed');
      return;
    }

    $resolvedId = $this->orgChart->employeeIdForAccountName($this->account->getAccountName());
    if ($resolvedId === '') {
      $this->setResult('', 'failed');
      $this->recordStep('Employee identity', 'Afvist: den loggede bruger er ikke knyttet til en medarbejder i organisationen.', 'failed');
      return;
    }

    $submitted = $this->getTokenValue((string) $this->configuration['submitted_employee_id_token'], '');
    $mismatch = $submitted !== '' && $submitted !== $resolvedId;

    $this->setResult($resolvedId, 'verified');
    $this->recordStep(
      'Employee identity',
      $mismatch
        ? 'Medarbejderidentitet bekraeftet fra login. Det indtastede medarbejdernummer blev ignoreret, da det ikke matchede.'
        : 'Medarbejderidentitet bekraeftet fra login.',
      'completed'
    );
  }

  /**
   * Writes the resolved employee id and its companion status token.
   *
   * @param string $employeeId
   *   The resolved employee identifier (may be empty on failure).
   * @param string $status
   *   Either 'verified' or 'failed'.
   */
  protected function setResult(string $employeeId, string $status): void {
    $resultToken = (string) ($this->configuration['result_token'] ?? '');
    if ($resultToken !== '') {
      $this->setTokenValue($resultToken, $employeeId);
      $this->setTokenValue($resultToken . '_status', $status);
    }
  }

}
