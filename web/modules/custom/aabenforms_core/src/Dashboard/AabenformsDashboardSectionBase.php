<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Dashboard;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sane defaults for a dashboard section.
 *
 * Most plugins override only the accessors they need. Cache contexts
 * default to user.permissions and max-age defaults to 60 seconds.
 */
abstract class AabenformsDashboardSectionBase extends PluginBase implements AabenformsDashboardSectionInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('@id', ['@id' => $this->getPluginId()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->pluginDefinition['weight'] ?? 0;
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusBadge(): ?array {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getHeroMetric(): ?array {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSecondaryMetrics(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSecondaryLink(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 60;
  }

}
