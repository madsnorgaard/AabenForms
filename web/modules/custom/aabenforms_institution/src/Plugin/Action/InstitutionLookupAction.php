<?php

declare(strict_types=1);

namespace Drupal\aabenforms_institution\Plugin\Action;

use Drupal\aabenforms_institution\Service\InstitutionRegistry;
use Drupal\aabenforms_workflows\Plugin\Action\AabenFormsActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Institution lookup and review routing.
 *
 * Resolves an institutionsnummer to registry data and the review-task
 * recipient (leader email, escalating up the parent chain when the
 * institution has none), so flows route submissions to the right school
 * without self-asserted email fields.
 */
#[Action(
  id: 'aabenforms_institution_lookup',
  label: new TranslatableMarkup('Institution Lookup'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Resolves an institutionsnummer to institution data and the review-task recipient email.'),
  version_introduced: '2.1.0',
)]
class InstitutionLookupAction extends AabenFormsActionBase {

  /**
   * The institution registry.
   *
   * @var \Drupal\aabenforms_institution\Service\InstitutionRegistry
   */
  protected InstitutionRegistry $registry;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setRegistry($container->get('aabenforms_institution.registry'));
    return $instance;
  }

  /**
   * Setter injection for the registry.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setRegistry(InstitutionRegistry $registry): void {
    $this->registry = $registry;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'institution_number_token' => 'institution_number',
      'result_token' => 'institution',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['institution_number_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Institution number token name'),
      '#description' => $this->t('Token containing the 6-digit institutionsnummer.'),
      '#default_value' => $this->configuration['institution_number_token'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Writes &lt;name&gt; (institution data), &lt;name&gt;_status (found/not_found/skipped) and &lt;name&gt;_leader_email (review-task recipient, parent-chain escalated).'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['institution_number_token'] = $form_state->getValue('institution_number_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $number = trim($this->getTokenValue($this->configuration['institution_number_token'], ''));
    $resultToken = (string) $this->configuration['result_token'];

    if ($number === '') {
      $this->setTokenValue($resultToken, NULL);
      $this->setTokenValue($resultToken . '_status', 'skipped');
      $this->setTokenValue($resultToken . '_leader_email', '');
      $this->recordStep('Institution Lookup', 'Skipped - no institution number provided', 'skipped');
      return;
    }

    $institution = $this->registry->findByNumber($number);

    if ($institution === NULL || !$institution->isActive()) {
      $this->log('Institution lookup: {number} unknown or inactive', ['number' => $number], 'warning');
      $this->setTokenValue($resultToken, NULL);
      $this->setTokenValue($resultToken . '_status', 'not_found');
      $this->setTokenValue($resultToken . '_leader_email', '');
      $this->recordStep('Institution Lookup', 'Institution not found in the registry or inactive', 'failed');
      return;
    }

    $leaderEmail = $this->registry->leaderEmailFor($number);

    $this->setTokenValue($resultToken, [
      'institution_number' => $institution->getInstitutionNumber(),
      'name' => (string) $institution->label(),
      'type' => $institution->getType(),
      'district' => $institution->getDistrict(),
      'leader_name' => $institution->getLeaderName(),
      'leader_email' => $leaderEmail,
    ]);
    $this->setTokenValue($resultToken . '_status', 'found');
    $this->setTokenValue($resultToken . '_leader_email', $leaderEmail);
    $this->recordStep(
      'Institution Lookup',
      sprintf('Institution resolved from the registry%s', $leaderEmail !== '' ? ' with review routing' : ' (no leader email - manual routing needed)'),
    );
  }

}
