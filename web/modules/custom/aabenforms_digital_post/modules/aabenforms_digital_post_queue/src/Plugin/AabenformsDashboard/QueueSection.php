<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post_queue\Plugin\AabenformsDashboard;

use Drupal\aabenforms_core\Dashboard\AabenformsDashboardSectionBase;
use Drupal\aabenforms_core\Dashboard\Attribute\AabenformsDashboardSection;
use Drupal\aabenforms_digital_post_queue\Service\DigitalPostQueueDispatcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Glass-box card for the Digital Post resilience queue on the AabenForms board.
 *
 * Surfaces the queue's health at a glance: how many sends are in flight and,
 * crucially, whether any have dead-lettered and need a caseworker. The card
 * links straight to the advancedqueue jobs UI where a job can be retried,
 * released or deleted - so the queue is operable, not a black box.
 */
#[AabenformsDashboardSection(id: 'digital_post_queue', weight: -38)]
class QueueSection extends AabenformsDashboardSectionBase {

  /**
   * Cached job counts keyed by state.
   *
   * @var array<string, int>|null
   */
  private ?array $counts = NULL;

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Digital Post queue');
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusBadge(): ?array {
    $c = $this->counts();
    $failed = $c['failure'] ?? 0;
    $inFlight = ($c['queued'] ?? 0) + ($c['processing'] ?? 0);
    return match (TRUE) {
      $failed > 0 => ['label' => $this->t('@n need attention', ['@n' => $failed]), 'tone' => 'danger'],
      $inFlight > 0 => ['label' => $this->t('@n in flight', ['@n' => $inFlight]), 'tone' => 'brand'],
      default => ['label' => $this->t('Clear'), 'tone' => 'success'],
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getHeroMetric(): ?array {
    // Mutually exclusive with the status badge, which we use instead.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSecondaryMetrics(): array {
    $c = $this->counts();
    return [
      ['label' => $this->t('In queue'), 'value' => ($c['queued'] ?? 0) + ($c['processing'] ?? 0)],
      ['label' => $this->t('Sent'), 'value' => $c['success'] ?? 0],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMainLink(): array {
    return [
      'label' => $this->t('Manage queue'),
      'url' => Url::fromRoute('entity.advancedqueue_queue.collection'),
    ];
  }

  /**
   * Loads the digital_post queue job counts, tolerating an absent queue.
   *
   * @return array<string, int>
   *   Counts keyed by advancedqueue job state.
   */
  private function counts(): array {
    if ($this->counts !== NULL) {
      return $this->counts;
    }
    $this->counts = [];
    try {
      $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(DigitalPostQueueDispatcher::QUEUE_ID);
      if ($queue !== NULL) {
        $this->counts = array_map('intval', $queue->getBackend()->countJobs());
      }
    }
    catch (\Throwable) {
      // Leave counts empty; the card renders a neutral "Clear" state.
    }
    return $this->counts;
  }

}
