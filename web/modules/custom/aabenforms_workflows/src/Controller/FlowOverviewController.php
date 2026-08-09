<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\aabenforms_workflows\Service\FlowJourneyGrouper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Journey-grouped overview of all ECA flows (#200).
 *
 * The raw model list at /admin/config/workflow/eca stays as the low-level
 * admin view; this page shows the same flows grouped by the citizen journey
 * they belong to, with trigger, source and status visible per row, so an
 * operator can see which flows belong together and spot a flow bound to a
 * webform outside its journey at a glance.
 */
class FlowOverviewController extends ControllerBase {

  public function __construct(protected readonly FlowJourneyGrouper $grouper) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('aabenforms_workflows.flow_journey_grouper'));
  }

  /**
   * Renders the grouped overview.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function overview(): array {
    $groups = $this->grouper->group();

    $build = [
      '#attached' => ['library' => ['aabenforms_workflows/workflow_wizard']],
      'intro' => [
        '#markup' => '<p>' . $this->t('Flows grouped by the journey they belong to. Stages run top to bottom: intake, event-driven steps, decision. The raw model list remains at <a href=":url">ECA models</a>.', [
          ':url' => Url::fromRoute('entity.eca.collection')->toString(),
        ]) . '</p>',
      ],
    ];

    $total = 0;
    $enabled = 0;
    foreach ($groups['journeys'] as $journey) {
      foreach ($journey['flows'] as $flow) {
        $total++;
        $enabled += $flow['enabled'] ? 1 : 0;
      }
    }
    foreach (['events', 'scheduled', 'other'] as $bucket) {
      foreach ($groups[$bucket] as $flow) {
        $total++;
        $enabled += $flow['enabled'] ? 1 : 0;
      }
    }
    $build['summary'] = [
      '#markup' => '<p><strong>' . $this->t('@enabled enabled of @total flows in @journeys journeys.', [
        '@enabled' => $enabled,
        '@total' => $total,
        '@journeys' => count($groups['journeys']),
      ]) . '</strong></p>',
    ];

    foreach ($groups['journeys'] as $webform_id => $journey) {
      $build['journey_' . $webform_id] = $this->journeyDetails(
        $this->t('@label (@count flows)', ['@label' => $journey['label'], '@count' => count($journey['flows'])]),
        $journey['flows'],
      );
    }

    $buckets = [
      'events' => $this->t('Event-driven steps without a journey'),
      'scheduled' => $this->t('Scheduled (cron)'),
      'other' => $this->t('Other'),
    ];
    foreach ($buckets as $key => $label) {
      if ($groups[$key]) {
        $build['bucket_' . $key] = $this->journeyDetails(
          $this->t('@label (@count)', ['@label' => $label, '@count' => count($groups[$key])]),
          $groups[$key],
        );
      }
    }

    return $build;
  }

  /**
   * Builds one journey group as an open details with a flow table.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The group title.
   * @param array<int, array<string, mixed>> $flows
   *   Flow rows from the grouper.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function journeyDetails($title, array $flows): array {
    $rows = [];
    foreach ($flows as $flow) {
      $links = [
        '#type' => 'dropbutton',
        '#links' => [
          'edit' => [
            'title' => $this->t('Edit'),
            'url' => Url::fromRoute('entity.eca.edit_form', ['eca' => $flow['id']]),
          ],
          'graph' => [
            'title' => $this->t('Graph'),
            'url' => Url::fromRoute('aabenforms_workflows.flow_graph', ['eca' => $flow['id']]),
          ],
        ],
      ];
      $rows[] = [
        $flow['label'],
        ['data' => ['#markup' => '<code>' . $flow['id'] . '</code>']],
        implode(', ', $flow['triggers']),
        $flow['wizard'] ? $this->t('Wizard') : $this->t('Hand-authored'),
        $flow['enabled'] ? $this->t('Enabled') : $this->t('Disabled'),
        ['data' => $links],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $title,
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Flow'),
          $this->t('Machine name'),
          $this->t('Trigger'),
          $this->t('Source'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No flows.'),
      ],
    ];
  }

}
