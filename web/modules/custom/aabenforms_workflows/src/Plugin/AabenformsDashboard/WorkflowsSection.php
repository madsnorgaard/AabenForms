<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Plugin\AabenformsDashboard;

use Drupal\aabenforms_core\Dashboard\AabenformsDashboardSectionBase;
use Drupal\aabenforms_core\Dashboard\Attribute\AabenformsDashboardSection;
use Drupal\aabenforms_workflows\Service\BpmnTemplateManager;
use Drupal\aabenforms_workflows\Service\WorkflowTemplateInstantiator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * {@inheritdoc}
 */
#[AabenformsDashboardSection(id: 'workflows', weight: -50)]
class WorkflowsSection extends AabenformsDashboardSectionBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly BpmnTemplateManager $templateManager,
    protected readonly WorkflowTemplateInstantiator $instantiator,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('aabenforms_workflows.bpmn_template_manager'),
      $container->get('aabenforms_workflows.template_instantiator'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Workflows');
  }

  /**
   * {@inheritdoc}
   */
  public function getHeroMetric(): ?array {
    // "Active workflows" means enabled ECA flows - every flow that fires,
    // whether hand-authored in config/sync or built with the wizard. The
    // previous count read only the wizard's template_instance configs, so
    // deploying hand-authored flows never moved the number and a deploy
    // looked like it had not landed (#192).
    try {
      $count = $this->entityTypeManager->getStorage('eca')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', TRUE)
        ->count()
        ->execute();
    }
    catch (\Throwable) {
      $count = 0;
    }
    return [
      'value' => (int) $count,
      'label' => $this->t('active workflows'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSecondaryMetrics(): array {
    return [
      [
        'label' => $this->t('Built with the wizard'),
        'value' => count($this->instantiator->getInstances()),
      ],
      [
        'label' => $this->t('Templates available'),
        'value' => count($this->templateManager->getAvailableTemplates()),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMainLink(): array {
    return [
      'label' => $this->t('Browse templates'),
      'url' => Url::fromRoute('aabenforms_workflows.template_browser'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return ['config:eca_list', 'aabenforms_workflows:templates'];
  }

}
