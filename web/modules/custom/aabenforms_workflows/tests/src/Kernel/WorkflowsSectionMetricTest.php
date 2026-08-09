<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aabenforms_workflows\Plugin\AabenformsDashboard\WorkflowsSection;
use Drupal\eca\Entity\Eca;

/**
 * Proves the dashboard's "active workflows" counts ECA flows (#192).
 *
 * The regression this guards: the hero metric counted the wizard's
 * template_instance configs only, so hand-authored flows in config/sync -
 * all 32 of them on main - never moved the number and a deploy that created
 * new flows looked like it had not landed.
 *
 * @group aabenforms_workflows
 */
class WorkflowsSectionMetricTest extends KernelTestBase {

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
  }

  /**
   * Builds the dashboard section plugin.
   */
  private function heroValue(): int {
    $section = WorkflowsSection::create($this->container, [], 'workflows', []);
    return (int) $section->getHeroMetric()['value'];
  }

  /**
   * Creates a bare ECA flow entity.
   */
  private function createFlow(string $id, bool $status): void {
    Eca::create([
      'id' => $id,
      'label' => $id,
      'status' => $status,
      'modeler' => 'fallback',
      'version' => '1.0.0',
      'events' => [],
      'conditions' => [],
      'gateways' => [],
      'actions' => [],
    ])->save();
  }

  /**
   * The hero metric reflects enabled ECA flows, not wizard instances.
   */
  public function testActiveWorkflowsCountsEnabledEcaFlows(): void {
    $this->assertSame(0, $this->heroValue(), 'No flows, no active workflows');

    // Two hand-authored (non-wizard) flows, the shape a config deploy adds.
    $this->createFlow('hand_authored_a', TRUE);
    $this->createFlow('hand_authored_b', TRUE);
    // A disabled flow must not count as active.
    $this->createFlow('disabled_flow', FALSE);

    $this->assertSame(2, $this->heroValue(), 'Enabled ECA flows move the counter; disabled ones do not');
  }

}
