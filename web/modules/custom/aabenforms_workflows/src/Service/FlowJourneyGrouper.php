<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Groups ECA flows into citizen journeys for the flow overview (#200).
 *
 * The flat ECA model list hides that many flows are stages of one journey
 * (intake -> co-sign -> decision). The grouping here is mechanical and
 * derivable from config alone, in priority order:
 *
 * 1. A flow that listens to a webform (insert or update) belongs to that
 *    webform's journey. Intake and decision stages of the same case meet
 *    here, because they bind the same webform.
 * 2. A flow that only listens to a custom event joins the journey whose
 *    member flows share its first machine-name token (skoleskift_cosign_flow
 *    joins the skoleskift journey). No match puts it in the event bucket.
 * 3. Cron-only flows land in the scheduled bucket; anything else in other.
 */
class FlowJourneyGrouper {

  use StringTranslationTrait;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the grouped overview.
   *
   * @return array{journeys: array<string, array{label: string, flows: array<int, array<string, mixed>>}>, events: array<int, array<string, mixed>>, scheduled: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>}
   *   Journeys keyed by webform id plus the three fallback buckets. Each
   *   flow row carries id, label, enabled, wizard, triggers (strings) and
   *   a stage weight used for ordering inside a journey.
   */
  public function group(): array {
    $wizard_ids = [];
    foreach ($this->configFactory->listAll('aabenforms_workflows.template_instance.') as $name) {
      $wizard_ids[substr($name, strlen('aabenforms_workflows.template_instance.'))] = TRUE;
    }

    $journeys = [];
    $unbound = [];

    $webform_storage = $this->entityTypeManager->getStorage('webform');
    /** @var \Drupal\eca\Entity\Eca $eca */
    foreach ($this->entityTypeManager->getStorage('eca')->loadMultiple() as $id => $eca) {
      $row = [
        'id' => $id,
        'label' => (string) $eca->label(),
        'enabled' => (bool) $eca->status(),
        'wizard' => isset($wizard_ids[$id]),
        'triggers' => [],
        'stage' => 3,
      ];
      $webforms = [];
      $has_custom = FALSE;
      $has_cron = FALSE;

      foreach ($eca->get('events') ?? [] as $event) {
        $plugin = $event['plugin'] ?? '';
        $config = $event['configuration'] ?? [];
        $type = (string) ($config['type'] ?? '');
        if (str_starts_with($type, 'webform_submission ')) {
          $webform_id = substr($type, strlen('webform_submission '));
          $webforms[$webform_id] = TRUE;
          $op = str_ends_with($plugin, ':update') ? 'update' : 'submission';
          $webform = $webform_storage->load($webform_id);
          $row['triggers'][] = (string) ($op === 'update'
            ? $this->t('On update (@webform)', ['@webform' => $webform ? $webform->label() : $webform_id])
            : $this->t('On submission (@webform)', ['@webform' => $webform ? $webform->label() : $webform_id]));
          $row['stage'] = min($row['stage'], $op === 'update' ? 2 : 0);
        }
        elseif (str_ends_with($plugin, ':custom')) {
          $has_custom = TRUE;
          $row['triggers'][] = (string) $this->t('Event: @event', ['@event' => $config['event_id'] ?? '?']);
          $row['stage'] = min($row['stage'], 1);
        }
        elseif (str_contains($plugin, 'cron')) {
          $has_cron = TRUE;
          $row['triggers'][] = (string) $this->t('Cron: @frequency', ['@frequency' => $config['frequency'] ?? '?']);
        }
        else {
          $row['triggers'][] = $plugin;
        }
      }

      if ($webforms) {
        foreach (array_keys($webforms) as $webform_id) {
          if (!isset($journeys[$webform_id])) {
            $webform = $webform_storage->load($webform_id);
            $journeys[$webform_id] = [
              'label' => (string) ($webform ? $webform->label() : $webform_id),
              'flows' => [],
            ];
          }
          $journeys[$webform_id]['flows'][] = $row;
        }
      }
      else {
        $row['kind'] = $has_custom ? 'events' : ($has_cron ? 'scheduled' : 'other');
        $unbound[] = $row;
      }
    }

    // Pass 2: an unbound custom-event flow joins the journey whose member
    // flows share its first machine-name token.
    $events = [];
    $scheduled = [];
    $other = [];
    foreach ($unbound as $row) {
      if ($row['kind'] === 'events') {
        $token = explode('_', $row['id'])[0];
        $joined = FALSE;
        foreach ($journeys as $webform_id => $journey) {
          foreach ($journey['flows'] as $member) {
            if (explode('_', $member['id'])[0] === $token) {
              $journeys[$webform_id]['flows'][] = $row;
              $joined = TRUE;
              break 2;
            }
          }
        }
        if (!$joined) {
          $events[] = $row;
        }
      }
      elseif ($row['kind'] === 'scheduled') {
        $scheduled[] = $row;
      }
      else {
        $other[] = $row;
      }
    }

    // Stage order inside a journey: intake (insert) -> event-driven steps
    // -> decision (update); stable by id within a stage.
    foreach ($journeys as &$journey) {
      usort($journey['flows'], static fn (array $a, array $b) => [$a['stage'], $a['id']] <=> [$b['stage'], $b['id']]);
    }
    unset($journey);
    uasort($journeys, static fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

    return [
      'journeys' => $journeys,
      'events' => $events,
      'scheduled' => $scheduled,
      'other' => $other,
    ];
  }

}
