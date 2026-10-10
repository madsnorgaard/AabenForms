<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Plugin\Action;

use Drupal\aabenforms_case\Service\DemoIncomeLookup;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: fetch household income for a CPR (demo eIndkomst lookup).
 *
 * Demonstrates the lookup → token → condition shape that drives the
 * fripladstilskud auto-decision, so income is fetched from the citizen's CPR
 * rather than self-reported on the form. The CPR is revealed (decrypted at
 * rest) and the lookup is audited with a hashed identifier.
 *
 * DEMO: the income is derived deterministically from the CPR (last digit < 5
 * → 150000, else 400000). In production this is replaced by a real
 * Serviceplatform eIndkomst lookup; the action contract (CPR token in, income
 * token out) stays the same.
 */
#[Action(
  id: 'aabenforms_case_income_lookup',
  label: new TranslatableMarkup('Fetch household income (eIndkomst)'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Looks up household income for a CPR (demo; production uses Serviceplatform eIndkomst).'),
  version_introduced: '1.0.0',
)]
class FetchIncomeAction extends CaseActionBase {

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   */
  protected CprAccess $cprAccess;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->cprAccess = $container->get('aabenforms_core.cpr_access');
    $instance->incomeLookup = $container->get('aabenforms_case.income_lookup');
    return $instance;
  }

  /**
   * The income lookup (demo rail until eIndkomst is wired).
   */
  protected DemoIncomeLookup $incomeLookup;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'cpr_token' => '[webform_submission:values:applicant_cpr:raw]',
      'result_token' => 'friplads_income',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['cpr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CPR token'),
      '#description' => $this->t('Token holding the CPR to look up income for.'),
      '#default_value' => $this->configuration['cpr_token'],
      '#required' => TRUE,
    ];
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token (income)'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['cpr_token'] = $form_state->getValue('cpr_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $rawCpr = $this->getTokenValue((string) ($this->configuration['cpr_token'] ?? ''), '');
      $resultToken = trim((string) ($this->configuration['result_token'] ?? 'friplads_income')) ?: 'friplads_income';
      $cpr = $this->cprAccess->reveal($rawCpr);
      $digits = preg_replace('/\D/', '', $cpr);

      if ($digits === '') {
        $this->recordStep('Indkomstopslag', 'Intet CPR at slå op.', 'failed');
        return;
      }

      // Shared with the prefill endpoint so both report the same figure.
      // Demo derivation until a Serviceplatformen eIndkomst client lands.
      $income = $this->incomeLookup->lookup($digits);
      if ($income === NULL) {
        $this->recordStep('Indkomstopslag', 'Intet CPR at slå op.', 'failed');
        return;
      }
      $demo = $this->incomeLookup->isDemoMode();

      $this->setTokenValue($resultToken, (string) $income);
      $this->recordStep('Indkomstopslag', sprintf('Husstandsindkomst hentet: %d kr.%s', $income, $demo ? ' (demo).' : '.'));
      $this->auditLogger->log('income_lookup', $digits, 'friplads', 'success', [
        'action_id' => $this->getPluginId(),
        'demo' => $demo,
      ]);
    }
    catch (\Throwable $e) {
      $this->handleError($e, 'Fetch income');
    }
  }

}
