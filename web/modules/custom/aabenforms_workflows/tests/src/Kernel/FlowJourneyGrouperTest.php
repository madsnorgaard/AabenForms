<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\eca\Entity\Eca;
use Drupal\webform\Entity\Webform;

/**
 * Tests the journey grouping behind the flow overview (#200).
 *
 * @group aabenforms_workflows
 */
class FlowJourneyGrouperTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'key',
    'encrypt',
    'domain',
    'webform',
    'modeler_api',
    'eca',
    'eca_base',
    'eca_content',
    'aabenforms_core',
    'aabenforms_mitid',
    'aabenforms_workflows',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('webform', ['webform']);
    Webform::create(['id' => 'school_transfer', 'title' => 'Ansøgning om skoleskift'])->save();
    Webform::create(['id' => 'contact', 'title' => 'Contact Form'])->save();
  }

  /**
   * Creates a bare ECA flow with the given events.
   *
   * @param string $id
   *   The flow id.
   * @param array<string, array<string, mixed>> $events
   *   Events keyed by machine name: ['plugin' => ..., 'configuration' => [...]].
   * @param bool $status
   *   Enabled state.
   */
  private function createFlow(string $id, array $events, bool $status = TRUE): void {
    foreach ($events as &$event) {
      $event += ['successors' => []];
    }
    unset($event);
    Eca::create([
      'id' => $id,
      'label' => $id,
      'status' => $status,
      'modeler' => 'fallback',
      'version' => '1.0.0',
      'events' => $events,
      'conditions' => [],
      'gateways' => [],
      'actions' => [],
    ])->save();
  }

  /**
   * Journeys form around webforms; stages order intake, event, decision.
   */
  public function testGrouping(): void {
    // The skoleskift shape: intake (insert), decision (update), and a
    // co-sign stage that only listens to a custom event but shares the
    // machine-name token.
    $this->createFlow('skoleskift_flow', [
      'e1' => ['plugin' => 'content_entity:insert', 'configuration' => ['type' => 'webform_submission school_transfer']],
    ]);
    $this->createFlow('skoleskift_decision_flow', [
      'e1' => ['plugin' => 'content_entity:update', 'configuration' => ['type' => 'webform_submission school_transfer']],
    ]);
    $this->createFlow('skoleskift_cosign_flow', [
      'e1' => ['plugin' => 'content_entity:custom', 'configuration' => ['event_id' => 'parent2_approved']],
    ]);
    // A custom-event flow sharing no token with any journey.
    $this->createFlow('parent1_approval_flow', [
      'e1' => ['plugin' => 'content_entity:custom', 'configuration' => ['event_id' => 'parent1_approved']],
    ]);
    // A cron-only flow and a disabled webform-bound flow.
    $this->createFlow('nightly_tabulation', [
      'e1' => ['plugin' => 'eca_base:eca_cron', 'configuration' => ['frequency' => '+1 day']],
    ]);
    $this->createFlow('contact_flow', [
      'e1' => ['plugin' => 'content_entity:insert', 'configuration' => ['type' => 'webform_submission contact']],
    ], FALSE);

    $groups = $this->container->get('aabenforms_workflows.flow_journey_grouper')->group();

    // Two journeys, one per bound webform.
    $this->assertSame(['Ansøgning om skoleskift', 'Contact Form'],
      array_map(static fn (array $j) => $j['label'], array_values($groups['journeys'])));

    // The skoleskift journey holds all three stages in intake -> event ->
    // decision order, including the custom-event co-sign joined by token.
    $skoleskift = array_map(static fn (array $f) => $f['id'], $groups['journeys']['school_transfer']['flows']);
    $this->assertSame(['skoleskift_flow', 'skoleskift_cosign_flow', 'skoleskift_decision_flow'], $skoleskift);

    // The unmatched custom-event flow lands in the events bucket.
    $this->assertSame(['parent1_approval_flow'], array_map(static fn (array $f) => $f['id'], $groups['events']));
    // Cron-only flows land in scheduled.
    $this->assertSame(['nightly_tabulation'], array_map(static fn (array $f) => $f['id'], $groups['scheduled']));

    // Status and triggers survive into the rows.
    $contact = $groups['journeys']['contact']['flows'][0];
    $this->assertFalse($contact['enabled']);
    $this->assertSame('On submission (Contact Form)', $contact['triggers'][0]);
    $this->assertFalse($contact['wizard']);
  }

  /**
   * Wizard-built flows are flagged from their template_instance config.
   */
  public function testWizardSourceFlag(): void {
    $this->createFlow('contact_form_123', [
      'e1' => ['plugin' => 'content_entity:insert', 'configuration' => ['type' => 'webform_submission contact']],
    ]);
    $this->container->get('config.factory')->getEditable('aabenforms_workflows.template_instance.contact_form_123')
      ->set('label', 'Wizard built')->save();

    $groups = $this->container->get('aabenforms_workflows.flow_journey_grouper')->group();
    $this->assertTrue($groups['journeys']['contact']['flows'][0]['wizard']);
  }

}
