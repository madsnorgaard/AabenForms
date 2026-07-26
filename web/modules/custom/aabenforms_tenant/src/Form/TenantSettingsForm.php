<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for AabenForms tenant employee provisioning.
 *
 * Maps an OIDC claim (delivered by MitID/NemLogin in the id_token) to
 * the aabenforms_employee role. Empty claim field disables automatic
 * provisioning so admins can fall back to manual role assignment.
 */
class TenantSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['aabenforms_tenant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenforms_tenant_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aabenforms_tenant.settings');

    $form['employee_provisioning'] = [
      '#type' => 'details',
      '#title' => $this->t('Employee role provisioning'),
      '#open' => TRUE,
      '#description' => $this->t('Citizens whose OIDC claim matches the rule below are granted the aabenforms_employee role on login, which unlocks HR webforms. Leave the claim field empty to disable automatic provisioning.'),
    ];
    $form['employee_provisioning']['employee_claim_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Claim field'),
      '#description' => $this->t('OIDC claim name to read (e.g. <code>employee_id</code>, <code>dk:gov:saml:attribute:CprNumberIdentifier</code>, <code>email</code>).'),
      '#default_value' => (string) $config->get('employee_provisioning.employee_claim_field'),
    ];
    $form['employee_provisioning']['employee_claim_match'] = [
      '#type' => 'select',
      '#title' => $this->t('Match rule'),
      '#options' => [
        'equals' => $this->t('Exact equals'),
        'starts_with' => $this->t('Starts with (useful for email domains)'),
      ],
      '#default_value' => (string) ($config->get('employee_provisioning.employee_claim_match') ?: 'equals'),
    ];
    $form['employee_provisioning']['employee_claim_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expected value'),
      '#description' => $this->t('For "equals", the exact claim string. For "starts_with", a prefix like <code>@mycommune.dk</code>.'),
      '#default_value' => (string) $config->get('employee_provisioning.employee_claim_value'),
    ];

    $form['tenant_binding'] = [
      '#type' => 'details',
      '#title' => $this->t('Tenant binding (auto-bind users at login)'),
      '#open' => FALSE,
      '#description' => $this->t('When a user logs in, the claim below is matched against the map. Each matching tenant is added to the user\'s Domain Access, so a caseworker is pinned to their kommune. Binding is additive and never removes tenants. A CVR claim identifies a whole kommune, so magistrat-level binding stays manual. Leave the map empty to disable.'),
    ];
    $form['tenant_binding']['claim_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Claim field'),
      '#description' => $this->t('Session claim carrying the tenant discriminator (e.g. <code>cvr</code>, <code>municipality_code</code>).'),
      '#default_value' => (string) $config->get('tenant_binding.claim_field'),
    ];
    $form['tenant_binding']['binding_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Claim value to tenant map'),
      '#description' => $this->t('One mapping per line as <code>claim-value|tenant-id</code>, e.g. <code>12345678|aarhus_mtm</code>.'),
      '#default_value' => $this->mapToText($config->get('tenant_binding.map') ?: []),
      '#rows' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('aabenforms_tenant.settings')
      ->set('employee_provisioning.employee_claim_field', (string) $form_state->getValue('employee_claim_field'))
      ->set('employee_provisioning.employee_claim_match', (string) $form_state->getValue('employee_claim_match'))
      ->set('employee_provisioning.employee_claim_value', (string) $form_state->getValue('employee_claim_value'))
      ->set('tenant_binding.claim_field', (string) $form_state->getValue('claim_field'))
      ->set('tenant_binding.map', $this->textToMap((string) $form_state->getValue('binding_map')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Renders the stored claim->tenant map as one "value|tenant" line each.
   *
   * @param array $map
   *   The stored map (list of ['claim_value' => ..., 'tenant_id' => ...]).
   *
   * @return string
   *   The textarea representation.
   */
  private function mapToText(array $map): string {
    $lines = [];
    foreach ($map as $entry) {
      $value = (string) ($entry['claim_value'] ?? '');
      $tenant = (string) ($entry['tenant_id'] ?? '');
      if ($value !== '' && $tenant !== '') {
        $lines[] = $value . '|' . $tenant;
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Parses "value|tenant" lines into the stored map structure.
   *
   * @param string $text
   *   The textarea value.
   *
   * @return array
   *   A list of ['claim_value' => ..., 'tenant_id' => ...].
   */
  private function textToMap(string $text): array {
    $map = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || !str_contains($line, '|')) {
        continue;
      }
      [$value, $tenant] = array_map('trim', explode('|', $line, 2));
      if ($value !== '' && $tenant !== '') {
        $map[] = ['claim_value' => $value, 'tenant_id' => $tenant];
      }
    }
    return $map;
  }

}
