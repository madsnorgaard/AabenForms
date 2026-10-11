<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\aabenforms_workflows\Service\AppealOwnershipVerifier;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ECA Action: verify that the MitID appellant is a party to the case.
 *
 * Gate a klage (appeal) flow on this before aabenforms_case_appeal: it compares
 * the verified MitID session CPR against the case's original applicant CPR and
 * writes a `<result_token>_status` scalar (`verified`/`failed`) to gate on.
 * Fails closed - a missing session, an unresolved case, or a mismatch all
 * produce `failed` so an attacker cannot appeal a stranger's case by id.
 */
#[Action(
  id: 'aabenforms_verify_appeal_ownership',
  label: new TranslatableMarkup('Verify appeal ownership'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Verifies the MitID appellant is a party to the case before an appeal is lodged.'),
  version_introduced: '1.1.0',
)]
class VerifyAppealOwnershipAction extends AabenFormsActionBase {

  /**
   * The request-attribute key the SPA rides the workflow id on.
   */
  protected const REQUEST_WORKFLOW_ATTR = 'aabenforms_workflow_id';

  /**
   * The browser-session key a same-origin login binds the workflow id to.
   */
  protected const SESSION_WORKFLOW_KEY = 'mitid_workflow_id';

  /**
   * The ownership verifier.
   */
  protected AppealOwnershipVerifier $verifier;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->verifier = $container->get('aabenforms_workflows.appeal_ownership_verifier');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'case_id_token' => '[webform_submission:values:case_id]',
      'workflow_id_token' => 'workflow_id_klage',
      'result_token' => 'appeal_ownership',
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
    $form['workflow_id_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Workflow id token'),
      '#default_value' => $this->configuration['workflow_id_token'],
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
    $this->configuration['case_id_token'] = $form_state->getValue('case_id_token');
    $this->configuration['workflow_id_token'] = $form_state->getValue('workflow_id_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $resultToken = (string) ($this->configuration['result_token'] ?? 'appeal_ownership');
    try {
      $caseId = $this->getTokenValue((string) ($this->configuration['case_id_token'] ?? ''), '');
      $workflowId = $this->resolveWorkflowId();

      if ($caseId === '') {
        $this->denied($resultToken, 'Mangler sags-id.');
        return;
      }

      $result = $this->verifier->verify($caseId, $workflowId);
      if ($result === AppealOwnershipVerifier::RESULT_MATCH) {
        $this->setTokenValue($resultToken, TRUE);
        $this->setTokenValue($resultToken . '_status', 'verified');
        $this->recordStep('Klageberettigelse', 'Klager er part i sagen (MitID-verificeret).');
        return;
      }
      if ($result === AppealOwnershipVerifier::RESULT_MATCH_ON_BEHALF) {
        $this->setTokenValue($resultToken, TRUE);
        $this->setTokenValue($resultToken . '_status', 'verified');
        $this->recordStep('Klageberettigelse', 'Klagen er indgivet på vegne af parten med digital fuldmagt (MitID-verificeret).');
        return;
      }
      $this->denied($resultToken, sprintf('Klager er ikke part i sagen (%s).', $result));
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'Verify appeal ownership');
      $this->setTokenValue($resultToken, FALSE);
      $this->setTokenValue($resultToken . '_status', 'failed');
    }
  }

  /**
   * Records a fail-closed denial.
   */
  protected function denied(string $resultToken, string $message): void {
    $this->setTokenValue($resultToken, FALSE);
    $this->setTokenValue($resultToken . '_status', 'failed');
    $this->recordStep('Klageberettigelse afvist', $message, 'failed');
  }

  /**
   * Resolves the workflow id (configured token, then SPA payload, then session).
   */
  protected function resolveWorkflowId(): string {
    $workflowId = $this->getTokenValue((string) ($this->configuration['workflow_id_token'] ?? 'workflow_id'), '');
    if ($workflowId !== '') {
      return $workflowId;
    }
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      // Never a URL query param: a session id must not travel in a URL.
      $fromRequest = $request->attributes->get(self::REQUEST_WORKFLOW_ATTR);
      if (is_string($fromRequest) && $fromRequest !== '') {
        return $fromRequest;
      }
      $session = $request->getSession();
      if ($session && $session->isStarted()) {
        $bound = $session->get(self::SESSION_WORKFLOW_KEY);
        if (is_string($bound) && $bound !== '') {
          return $bound;
        }
      }
    }
    return '';
  }

}
