<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_mitid\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aabenforms_mitid\Plugin\AabenformsDashboard\MitidSection;

/**
 * Proves the dashboard's "Logins (24h)" counter moves on a real login (#191).
 *
 * The regression this guards: the tile queried action='mitid_login', which
 * nothing ever writes - a successful login is audited as
 * action='workflow_access' / purpose='mitid_session_created' - so the
 * counter was structurally stuck at zero while logins happened underneath.
 *
 * @group aabenforms_mitid
 */
class MitidSectionLoginMetricTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'domain',
    'key',
    'encrypt',
    'aabenforms_core',
    'aabenforms_mitid',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);
    $this->installConfig(['system', 'aabenforms_mitid']);
  }

  /**
   * Reads the Logins (24h) value from the section's secondary metrics.
   */
  private function loginsMetric(): int {
    $section = MitidSection::create($this->container, [], 'mitid', []);
    foreach ($section->getSecondaryMetrics() as $metric) {
      if ((string) $metric['label'] === 'Logins (24h)') {
        return (int) $metric['value'];
      }
    }
    $this->fail('The MitID card no longer exposes a Logins (24h) metric.');
  }

  /**
   * A session created through MitIdSessionManager moves the counter.
   */
  public function testLoginCounterCountsStoredSessions(): void {
    $this->assertSame(0, $this->loginsMetric(), 'Counter starts at zero');

    /** @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager $sm */
    $sm = $this->container->get('aabenforms_mitid.session_manager');
    // The audit row is only written for a session that carries a CPR, which
    // is exactly what a completed MitID login stores.
    $this->assertTrue($sm->storeSession('wf_' . bin2hex(random_bytes(16)), [
      'cpr' => '0101904521',
      'name' => 'Test Testesen',
      'assurance_level' => 'substantial',
    ]));

    $this->assertSame(1, $this->loginsMetric(), 'One stored MitID session counts as one login');
  }

}
