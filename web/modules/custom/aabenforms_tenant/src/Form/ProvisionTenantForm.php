<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Form;

use Drupal\aabenforms_tenant\Service\TenantProvisioner;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stands up a tenant (kommune / magistrat) from the admin UI.
 *
 * The one-command Drush provisioner, surfaced as a form so an operator can
 * create a Domain + per-tenant CPR key + encryption profile without the CLI.
 * The key uses the env provider, so the form reminds the operator which
 * environment variable to set afterwards.
 */
final class ProvisionTenantForm extends FormBase {

  /**
   * The tenant provisioner.
   *
   * @var \Drupal\aabenforms_tenant\Service\TenantProvisioner
   */
  protected TenantProvisioner $provisioner;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->provisioner = $container->get('aabenforms_tenant.tenant_provisioner');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenforms_tenant_provision';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Provision a tenant: a Domain plus its own CPR encryption key and profile, so cases and CPR are isolated per tenant. Re-running is safe. For a magistratsstyre like Aarhus, give each magistrat its own subdomain.') . '</p>',
    ];
    $form['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant id'),
      '#description' => $this->t('Lowercase letters, digits and underscores only, e.g. aarhus_mtm. This becomes the domain id and the tenant discriminator.'),
      '#required' => TRUE,
      '#maxlength' => 64,
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Human-readable name, e.g. Aarhus - Teknik og Miljoe.'),
      '#required' => TRUE,
    ];
    $form['hostname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hostname'),
      '#description' => $this->t('The host that routes to this tenant, e.g. mtm.aarhus.aabenforms.dk.'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Provision tenant'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->provisioner->provision(
        (string) $form_state->getValue('tenant_id'),
        (string) $form_state->getValue('label'),
        (string) $form_state->getValue('hostname'),
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return;
    }

    if ($result['created'] === []) {
      $this->messenger()->addStatus($this->t('Tenant @id was already provisioned; nothing changed.', [
        '@id' => $result['tenant_id'],
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Provisioned tenant @id: @changes.', [
        '@id' => $result['tenant_id'],
        '@changes' => implode(', ', $result['created']),
      ]));
    }
    foreach ($result['warnings'] as $warning) {
      $this->messenger()->addWarning($warning);
    }
    $this->messenger()->addStatus($this->t('CPR key material is read from the environment variable @env.', [
      '@env' => $result['env_variable'],
    ]));
  }

}
