<?php

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Family/custody lookup for the authenticated citizen.
 *
 * Resolves the children the given adult holds custody of, including each
 * child's full custody-holder set, so downstream steps (co-signature,
 * Digital Post recipient resolution) never rely on citizen-typed CPRs.
 */
#[Action(
  id: 'aabenforms_family_lookup',
  label: new TranslatableMarkup('Family Relations Lookup'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Looks up children and custody holders from the CPR registry (SF6006 Familie+).'),
  version_introduced: '2.1.0',
)]
class FamilyLookupAction extends AabenFormsActionBase {

  /**
   * The family relations lookup service.
   *
   * @var \Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface
   */
  protected FamilyRelationsLookupInterface $familyLookup;

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   *
   * @var \Drupal\aabenforms_core\Service\CprAccess
   */
  protected CprAccess $cprAccess;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->familyLookup = $container->get('aabenforms_core.family_lookup');
    $instance->cprAccess = $container->get('aabenforms_core.cpr_access');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'cpr_token' => 'cpr',
      'result_token' => 'family_data',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['cpr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CPR token name'),
      '#description' => $this->t('Token containing the adult CPR number to look up children for.'),
      '#default_value' => $this->configuration['cpr_token'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Token to store the children list. A companion token &lt;name&gt;_status carries found, not_found, skipped or error.'),
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
    $cpr = $this->getTokenValue($this->configuration['cpr_token'], '');
    $cpr = $this->cprAccess->reveal((string) $cpr);
    $cpr = $cpr ? preg_replace('/[^0-9]/', '', $cpr) : '';

    if (empty($cpr)) {
      $this->log('Family lookup skipped: no CPR available', [], 'warning');
      $this->setTokenValue($this->configuration['result_token'], NULL);
      $this->setResultStatus('skipped');
      $this->recordStep('Family Relations Lookup', 'Skipped - no CPR available to look up', 'skipped');
      return;
    }

    try {
      $children = $this->familyLookup->childrenOf($cpr);

      if (empty($children)) {
        $this->setTokenValue($this->configuration['result_token'], []);
        $this->setResultStatus('not_found');
        $this->recordStep('Family Relations Lookup', 'No children with registered custody found in the CPR registry');
        return;
      }

      $this->setTokenValue($this->configuration['result_token'], $children);
      $this->setResultStatus('found');
      $this->log('Family lookup found {count} children with registered custody', [
        'count' => count($children),
      ]);
      // Honest labelling: never claim a registry confirmation for demo data.
      $this->recordStep('Family Relations Lookup', $this->familyLookup->isDemoMode()
        ? 'Demo: family relations simulated with test data. Real SF6006 lookups require a Serviceplatformen client certificate.'
        : 'Custody-verified family relations retrieved from the CPR registry (SF6006)');
    }
    catch (\Exception $e) {
      $this->log('Family lookup failed: {message}', ['message' => $e->getMessage()], 'error');
      $this->setTokenValue($this->configuration['result_token'], NULL);
      $this->setResultStatus('error');
      $this->recordStep('Family Relations Lookup', 'The family registry lookup is temporarily unavailable', 'failed');
    }
  }

  /**
   * Writes the scalar status companion token for downstream gating.
   *
   * @param string $status
   *   One of 'found', 'not_found', 'skipped' or 'error'.
   */
  protected function setResultStatus(string $status): void {
    $resultToken = (string) ($this->configuration['result_token'] ?? '');
    if ($resultToken !== '') {
      $this->setTokenValue($resultToken . '_status', $status);
    }
  }

}
