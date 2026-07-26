<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_eca\Plugin\Action;

use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_digital_post\DigitalPost\RecipientResolution;
use Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver;
use Drupal\aabenforms_workflows\Plugin\Action\AabenFormsActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: Resolve Digital Post recipients for post about a child.
 *
 * Writes indexed recipient tokens so a flow can wire one Send Digital Post
 * action per slot: `<result>_1` and `<result>_2` hold the recipient CPRs
 * ('' when the slot is unused - the send action skips empty recipients).
 * The `<result>_status` companion carries which rule applied ('guardians',
 * 'pupil' or 'none') so a gate can branch to manual handling on 'none'.
 */
#[Action(
  id: 'aabenforms_resolve_guardians',
  label: new TranslatableMarkup('Resolve Digital Post Recipients for Child'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Resolves who receives Digital Post about a child: each custody holder when under 15, the young person from 15. Fail-closed.'),
  version_introduced: '2.1.0',
)]
class ResolveGuardiansAction extends AabenFormsActionBase {

  /**
   * The largest number of indexed recipient tokens written.
   *
   * CPR custody supports at most two custody holders at a time; two send
   * slots therefore cover the legal reality. Should the registry ever return
   * more, the surplus is logged and dropped rather than silently ignored.
   */
  protected const MAX_RECIPIENT_SLOTS = 2;

  /**
   * The guardian recipient resolver.
   *
   * @var \Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver
   */
  protected GuardianRecipientResolver $resolver;

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
    $instance->setResolver($container->get('aabenforms_digital_post.guardian_recipient_resolver'));
    $instance->setCprAccess($container->get('aabenforms_core.cpr_access'));
    return $instance;
  }

  /**
   * Setter injection for the resolver.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setResolver(GuardianRecipientResolver $resolver): void {
    $this->resolver = $resolver;
  }

  /**
   * Setter injection for the CPR access helper.
   *
   * Public so unit tests can swap in a stub without reflection.
   */
  public function setCprAccess(CprAccess $cprAccess): void {
    $this->cprAccess = $cprAccess;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'child_cpr_token' => 'child_cpr',
      'result_token' => 'post_recipients',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['child_cpr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Child CPR token name'),
      '#description' => $this->t('Token containing the child CPR the post concerns.'),
      '#default_value' => $this->configuration['child_cpr_token'],
      '#required' => TRUE,
    ];

    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token name'),
      '#description' => $this->t('Base token name. Writes &lt;name&gt; (full resolution), &lt;name&gt;_status (guardians/pupil/none), &lt;name&gt;_count, and &lt;name&gt;_1 / &lt;name&gt;_2 (recipient CPRs, empty when unused).'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['child_cpr_token'] = $form_state->getValue('child_cpr_token');
    $this->configuration['result_token'] = $form_state->getValue('result_token');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $childCpr = $this->getTokenValue($this->configuration['child_cpr_token'], '');
    $childCpr = $this->cprAccess->reveal((string) $childCpr);

    $resolution = $this->resolver->resolveForChild((string) $childCpr);
    $resultToken = (string) $this->configuration['result_token'];

    $cprs = $resolution->cprs();
    if (count($cprs) > self::MAX_RECIPIENT_SLOTS) {
      $this->log('Recipient resolution returned {count} recipients; only the first {max} slots are written', [
        'count' => count($cprs),
        'max' => self::MAX_RECIPIENT_SLOTS,
      ], 'warning');
    }

    $this->setTokenValue($resultToken, [
      'rule' => $resolution->rule,
      'reason' => $resolution->reason,
      'recipients' => $resolution->recipients,
    ]);
    $this->setTokenValue($resultToken . '_status', $resolution->rule);
    $this->setTokenValue($resultToken . '_count', (string) count($cprs));
    for ($slot = 1; $slot <= self::MAX_RECIPIENT_SLOTS; $slot++) {
      $this->setTokenValue($resultToken . '_' . $slot, $cprs[$slot - 1] ?? '');
    }

    switch ($resolution->rule) {
      case RecipientResolution::RULE_GUARDIANS:
        $this->recordStep(
          'Digital Post Recipients Resolved',
          sprintf('Child is under 15: post goes to %d registered custody holder(s)', count($cprs)),
        );
        break;

      case RecipientResolution::RULE_PUPIL:
        $this->recordStep(
          'Digital Post Recipients Resolved',
          'The young person is 15 or older and receives the post directly',
        );
        break;

      default:
        $this->log('Recipient resolution failed closed: {reason}', [
          'reason' => $resolution->reason,
        ], 'warning');
        $this->recordStep(
          'Digital Post Recipients Resolved',
          'No recipient could be determined - manual handling required. ' . $resolution->reason,
          'failed',
        );
    }
  }

}
