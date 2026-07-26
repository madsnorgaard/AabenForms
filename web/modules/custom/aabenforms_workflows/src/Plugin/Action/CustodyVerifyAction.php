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
 * ECA Action: Verify custody (foraeldremyndighed) via the CPR registry.
 *
 * Answers "is this adult a REGISTERED custody holder of this child?" against
 * the registry. This closes the gap left by the parent-approval CPR gate
 * (ParentCprVerifier), which only proves the approver is the person named on
 * the form, not that the person actually holds custody. Fail-closed: any
 * missing input, unknown CPR or registry failure verifies as 'false'.
 */
#[Action(
  id: 'aabenforms_custody_verify',
  label: new TranslatableMarkup('Custody Verification'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Verifies that an adult is a registered custody holder of a child via the CPR registry (SF6006 Familie+). Fail-closed.'),
  version_introduced: '2.1.0',
)]
class CustodyVerifyAction extends AabenFormsActionBase {

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
      'adult_cpr_token' => 'cpr',
      'child_cpr_token' => 'child_cpr',
      'result_token' => 'custody_verified',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['adult_cpr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Adult CPR token name'),
      '#description' => $this->t('Token containing the adult CPR (typically the MitID-asserted CPR).'),
      '#default_value' => $this->configuration['adult_cpr_token'],
      '#required' => TRUE,
    ];

    $form['child_cpr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Child CPR token name'),
      '#description' => $this->t('Token containing the child CPR to verify custody of.'),
      '#default_value' => $this->configuration['child_cpr_token'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t("Token set to 'true' or 'false'. Fail-closed: any error yields 'false'."),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['adult_cpr_token'] = $form_state->getValue('adult_cpr_token');
    $this->configuration['child_cpr_token'] = $form_state->getValue('child_cpr_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $adultCpr = $this->cleanCpr($this->getTokenValue($this->configuration['adult_cpr_token'], ''));
    $childCpr = $this->cleanCpr($this->getTokenValue($this->configuration['child_cpr_token'], ''));

    if ($adultCpr === '' || $childCpr === '') {
      $this->log('Custody verification failed closed: missing CPR input', [], 'warning');
      $this->setTokenValue($this->configuration['result_token'], 'false');
      $this->recordStep('Custody Verification', 'Failed closed - adult or child CPR missing', 'failed');
      return;
    }

    try {
      $hasCustody = $this->familyLookup->hasCustody($adultCpr, $childCpr);
    }
    catch (\Exception $e) {
      $this->log('Custody verification failed closed: {message}', ['message' => $e->getMessage()], 'error');
      $this->setTokenValue($this->configuration['result_token'], 'false');
      $this->recordStep('Custody Verification', 'Failed closed - the CPR registry could not be reached', 'failed');
      return;
    }

    $this->setTokenValue($this->configuration['result_token'], $hasCustody ? 'true' : 'false');

    if ($hasCustody) {
      $this->recordStep('Custody Verification', 'Custody confirmed against the CPR registry (SF6006)');
    }
    else {
      $this->log('Custody verification: adult is not a registered custody holder', [], 'warning');
      $this->recordStep('Custody Verification', 'Adult is not a registered custody holder of the child', 'failed');
    }
  }

  /**
   * Decrypts and normalizes a CPR token value to bare digits.
   *
   * @param string $raw
   *   The raw token value (possibly encrypted at rest).
   *
   * @return string
   *   Digit-only CPR, or '' when empty.
   */
  protected function cleanCpr(string $raw): string {
    $revealed = $this->cprAccess->reveal($raw);
    return $revealed ? (preg_replace('/[^0-9]/', '', $revealed) ?? '') : '';
  }

}
