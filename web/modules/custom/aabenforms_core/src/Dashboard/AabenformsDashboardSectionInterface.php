<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Dashboard;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Contract for an AabenForms admin-dashboard section.
 *
 * Each method returns simple data; the dashboard controller composes the
 * card visuals. Sections must NOT render HTML themselves - they describe
 * state, the template renders it.
 *
 * Per the UX brief, a section's hero is EITHER one big number OR one status
 * pill, never both. If both are returned, the controller picks the badge
 * and ignores the hero metric.
 */
interface AabenformsDashboardSectionInterface extends PluginInspectionInterface, CacheableDependencyInterface {

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup;

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int;

  /**
   * Whether this section should render at all on the current request.
   *
   * Examples: hide a Tenants card when only one tenant exists; hide
   * Mock Services in production.
   */
  public function isApplicable(): bool;

  /**
   * Status pill, if the section has a discrete state.
   *
   * @return array|null
   *   Either ['label' => string|TranslatableMarkup, 'tone' =>
   *   'neutral'|'brand'|'success'|'warning'|'danger'] or NULL.
   */
  public function getStatusBadge(): ?array;

  /**
   * Hero metric, if the section's primary surface is a number.
   *
   * @return array|null
   *   Either ['label' => string|TranslatableMarkup, 'value' => string|int]
   *   or NULL. Mutually exclusive with getStatusBadge() per UX rule.
   */
  public function getHeroMetric(): ?array;

  /**
   * Up to two secondary 'label: value' rows.
   *
   * @return array
   *   Zero, one, or two entries; each ['label' => ..., 'value' => ...].
   */
  public function getSecondaryMetrics(): array;

  /**
   * Footer link CTA for the card.
   *
   * @return array
   *   ['label' => string|TranslatableMarkup, 'url' => \Drupal\Core\Url].
   */
  public function getMainLink(): array;

  /**
   * Optional second footer CTA, shown after the main link.
   *
   * @return array
   *   ['label' => string|TranslatableMarkup, 'url' => \Drupal\Core\Url], or an
   *   empty array for no secondary link.
   */
  public function getSecondaryLink(): array;

}
