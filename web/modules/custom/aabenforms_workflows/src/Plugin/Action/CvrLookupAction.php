<?php

namespace Drupal\aabenforms_workflows\Plugin\Action;

use Drupal\aabenforms_core\Service\ServiceplatformenClient;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: CVR Company Lookup via Serviceplatformen SF1530.
 */
#[Action(
  id: 'aabenforms_cvr_lookup',
  label: new TranslatableMarkup('CVR Company Lookup'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Looks up company data from Serviceplatformen SF1530 using CVR number.'),
  version_introduced: '2.0.0',
)]
class CvrLookupAction extends AabenFormsActionBase {

  /**
   * The Serviceplatformen client.
   *
   * @var \Drupal\aabenforms_core\Service\ServiceplatformenClient
   */
  protected ServiceplatformenClient $serviceplatformenClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->serviceplatformenClient = $container->get('aabenforms_core.serviceplatformen_client');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * Whether to run CVR lookup in demo mode (no real Serviceplatformen call).
   *
   * True when the demo flag is set, or - the common case for a POC - when no
   * Serviceplatformen client certificate is provisioned. Once a certificate is
   * configured, real SF1530 lookups resume automatically.
   */
  protected function demoModeAllowed(): bool {
    if ($this->configFactory->get('aabenforms_workflows.settings')->get('allow_cvr_demo_mode')) {
      return TRUE;
    }
    $certs = $this->configFactory->get('aabenforms_core.settings')->get('serviceplatformen.certificates') ?? [];
    return empty($certs['cert_path']) && empty($certs['key_path']);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'cvr_token' => 'cvr',
      'result_token' => 'company_data',
      'use_cache' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['cvr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CVR token name'),
      '#description' => $this->t('Token containing the CVR number to look up.'),
      '#default_value' => $this->configuration['cvr_token'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Token to store company data result.'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];

    $form['use_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use cache'),
      '#description' => $this->t('Cache lookup results for 15 minutes.'),
      '#default_value' => $this->configuration['use_cache'],
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['cvr_token'] = $form_state->getValue('cvr_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    $this->configuration['use_cache'] = $form_state->getValue('use_cache');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $cvr = $this->getTokenValue($this->configuration['cvr_token'], '');

    // Clean CVR (remove spaces/hyphens).
    $cvr = $cvr ? preg_replace('/[^0-9]/', '', $cvr) : '';

    if (empty($cvr)) {
      $this->log('CVR lookup skipped: no CVR available to look up', [], 'warning');
      $this->setTokenValue($this->configuration['result_token'], NULL);
      $this->setResultStatus('skipped');
      $this->recordStep('CVR Registry Lookup', 'Skipped - no CVR available to look up', 'skipped');
      return;
    }

    if ($this->demoModeAllowed()) {
      // No Serviceplatformen certificate is provisioned (the POC case). Do not
      // call SF1530; record an honest, clearly-labelled demo step with test
      // data so the flow continues without surfacing a connection error.
      $demoCompany = [
        'cvr' => $cvr,
        'name' => 'Demovirksomhed ApS (testdata)',
        'demo' => TRUE,
      ];
      $this->setTokenValue($this->configuration['result_token'], $demoCompany);
      $this->setResultStatus('found');
      $this->log('CVR lookup ran in demo mode (no Serviceplatformen certificate).', [], 'info');
      $this->recordStep('CVR Registry Lookup', 'Demo: CVR-opslag simuleret med testdata. Rigtige Serviceplatformen-opslag kræver klientcertifikat.', 'completed');
      return;
    }

    try {
      $this->log('Performing CVR lookup via SF1530 for: {cvr}', [
        'cvr' => $cvr,
      ]);

      // Call SF1530 via ServiceplatformenClient.
      $options = [
        'no_cache' => !$this->configuration['use_cache'],
      ];

      $result = $this->serviceplatformenClient->request(
        'SF1530',
        'CompanyLookup',
        ['cvr' => $cvr],
        $options
      );

      // Extract company data.
      $companyData = $result['company'] ?? NULL;

      if (empty($companyData)) {
        $this->log('CVR lookup returned no data for: {cvr}', [
          'cvr' => $cvr,
        ], 'warning');
        $this->setTokenValue($this->configuration['result_token'], NULL);
        $this->setResultStatus('not_found');
        $this->recordStep('CVR Registry Lookup', 'No company found in the central business registry (SF1530)', 'failed');
        return;
      }

      $this->log('CVR lookup successful: {name}', [
        'name' => $companyData['name'] ?? 'Unknown',
      ]);

      $this->setTokenValue($this->configuration['result_token'], $companyData);
      $this->setResultStatus('found');
      $this->recordStep('CVR Registry Lookup', 'Company data retrieved from the central business registry (SF1530)');

    }
    catch (\Exception $e) {
      // Keep the technical detail in the server log, but never surface a raw
      // cURL/SSL/Serviceplatformen error to the citizen.
      $this->log('CVR lookup failed: {message}', ['message' => $e->getMessage()], 'error');
      $this->setTokenValue($this->configuration['result_token'], NULL);
      $this->setResultStatus('error');
      $this->recordStep('CVR Registry Lookup', 'CVR-opslaget er midlertidigt utilgaengeligt. Prov igen senere.', 'failed');
    }
  }

  /**
   * Writes an explicit scalar status companion token for downstream gating.
   *
   * The result_token holds the company array (or NULL); ECA conditions and the
   * audit action read this `<result_token>_status` scalar (`found`,
   * `not_found`, `skipped` or `error`) because comparing an array or NULL
   * token is unreliable.
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
