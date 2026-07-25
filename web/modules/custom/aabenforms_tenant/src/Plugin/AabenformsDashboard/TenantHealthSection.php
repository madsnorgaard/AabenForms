<?php

declare(strict_types=1);

namespace Drupal\aabenforms_tenant\Plugin\AabenformsDashboard;

use Drupal\aabenforms_core\Dashboard\AabenformsDashboardSectionBase;
use Drupal\aabenforms_core\Dashboard\Attribute\AabenformsDashboardSection;
use Drupal\aabenforms_tenant\Service\TenantProvisioner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Glass-box card for tenant (kommune / magistrat) isolation health.
 *
 * Hidden in the single-tenant demo (one or zero domains). Once several tenants
 * exist it surfaces, at a glance, whether every tenant has the CPR key +
 * encryption profile it needs - a missing pair means CPR encryption would fail
 * closed for that tenant, so it is worth flagging before a citizen hits it.
 */
#[AabenformsDashboardSection(id: 'tenants', weight: -36)]
class TenantHealthSection extends AabenformsDashboardSectionBase {

  /**
   * Cached per-tenant provisioning states.
   *
   * @var array<int, array>|null
   */
  private ?array $states = NULL;

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TenantProvisioner $provisioner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('aabenforms_tenant.tenant_provisioner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Tenants');
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(): bool {
    // Single-tenant / demo mode has zero or one domain and no isolation to
    // report; hide the card until multi-tenancy is actually in use.
    return count($this->states()) > 1;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusBadge(): ?array {
    $incomplete = $this->countMissingConfig();
    if ($incomplete > 0) {
      return [
        'label' => $this->t('@n tenant(s) missing CPR key/profile', ['@n' => $incomplete]),
        'tone' => 'warning',
      ];
    }
    return [
      'label' => $this->t('@n tenants isolated', ['@n' => count($this->states())]),
      'tone' => 'success',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSecondaryMetrics(): array {
    $keysPending = 0;
    foreach ($this->states() as $state) {
      if (!$state['env_set']) {
        $keysPending++;
      }
    }
    return [
      ['label' => $this->t('Tenants'), 'value' => count($this->states())],
      ['label' => $this->t('Key env vars pending'), 'value' => $keysPending],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMainLink(): array {
    return [
      'label' => $this->t('Provision tenant'),
      'url' => Url::fromRoute('aabenforms_tenant.provision'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return ['config:domain.record_list'];
  }

  /**
   * Number of tenants missing their CPR key or encryption profile.
   */
  private function countMissingConfig(): int {
    $missing = 0;
    foreach ($this->states() as $state) {
      if (!$state['key'] || !$state['profile']) {
        $missing++;
      }
    }
    return $missing;
  }

  /**
   * Loads each domain's provisioning state, tolerating an absent Domain module.
   *
   * @return array<int, array>
   *   One describe() result per tenant/domain.
   */
  private function states(): array {
    if ($this->states !== NULL) {
      return $this->states;
    }
    $this->states = [];
    try {
      $domains = $this->entityTypeManager->getStorage('domain')->loadMultiple();
    }
    catch (\Throwable) {
      // No Domain module / storage: leave empty, the card stays hidden.
      return $this->states;
    }
    foreach ($domains as $domain) {
      try {
        $this->states[] = $this->provisioner->describe($domain->id());
      }
      catch (\Throwable) {
        // Skip a domain with a non-canonical id rather than hide the card.
      }
    }
    return $this->states;
  }

}
