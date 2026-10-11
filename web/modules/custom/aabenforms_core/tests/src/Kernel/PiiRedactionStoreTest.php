<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\aabenforms_core\Privacy\PiiRedactor;
use Drupal\KernelTests\KernelTestBase;

/**
 * Proves no raw CPR or secret reaches the trace and replay stores (#74).
 *
 * @group aabenforms_core
 */
class PiiRedactionStoreTest extends KernelTestBase {

  /**
   * A demo CPR that must never be found in storage.
   */
  protected const CPR = '0101904521';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'modeler_api',
    'eca',
    'aabenforms_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('aabenforms_core', ['aabenforms_trace']);
  }

  /**
   * Steps written by the collector and the trace store hold no raw CPR.
   */
  public function testTraceStoreRedacts(): void {
    $collector = $this->container->get('aabenforms_core.workflow_execution_collector');
    $collector->addStep('aabenforms_log', 'info', 'Ansøgning fra ' . self::CPR . ' modtaget');
    $collector->addStep('aabenforms_cpr_lookup', 'CPR Registry Lookup', 'Opslag fejlede', 'failed', 'SF1520 afviste 010190-4521');

    $execution = $collector->toArray();
    // Raw steps handed in by any other caller are redacted by the store too.
    $execution['steps'][] = [
      'id' => 'custom',
      'name' => 'Custom',
      'description' => 'Barn ' . self::CPR,
      'status' => 'completed',
      'access_token' => 'eyJ.secret',
    ];

    $store = $this->container->get('aabenforms_core.trace_store');
    $store->save(7, 'test_form', NULL, $execution);

    $raw = (string) $this->container->get('database')
      ->select('aabenforms_trace', 't')
      ->fields('t', ['steps'])
      ->condition('sid', 7)
      ->execute()
      ->fetchField();
    $this->assertStringNotContainsString(self::CPR, $raw);
    $this->assertStringNotContainsString('010190-4521', $raw);
    $this->assertStringNotContainsString('eyJ.secret', $raw);
    $this->assertStringContainsString(PiiRedactor::CPR_MASK, $raw);

    $trace = $store->load(7);
    $this->assertSame('Ansøgning fra DDMMYY-XXXX modtaget', $trace['steps'][0]['description']);
    $this->assertSame('SF1520 afviste DDMMYY-XXXX', $trace['steps'][1]['error']);
  }

  /**
   * The ECA token browser replay store is redacted at write time.
   */
  public function testEcaReplayStoreRedacts(): void {
    $history = [
      'steps' => [
        [
          'tokens' => [
            ['label' => 'Cpr', 'token' => 'entity:values:cpr', 'value' => self::CPR],
            ['label' => 'Id Token', 'token' => 'mitid:id_token', 'value' => 'eyJ.id.token'],
          ],
          'session' => ['workflow_id' => 'wf_secretHandle0123456789'],
        ],
      ],
    ];

    $browser = $this->container->get('eca.token_browser');
    // Shared, cross-user store (replay armed) and the private per-user store.
    $browser->storeHistory($history, 'model::event');
    $hash = $browser->storeHistory($history);

    $shared = $this->container->get('keyvalue.expirable')->get('tempstore.shared.eca_process_debugger')->getAll();
    $private = $this->container->get('keyvalue.expirable')->get('tempstore.private.eca_process_debugger')->getAll();
    $this->assertNotEmpty($shared);
    $this->assertNotEmpty($private);

    foreach (array_merge(array_values($shared), array_values($private)) as $item) {
      $payload = $this->decompress($item->data);
      $this->assertStringNotContainsString(self::CPR, $payload);
      $this->assertStringNotContainsString('eyJ.id.token', $payload);
      $this->assertStringNotContainsString('wf_secretHandle', $payload);
      $this->assertStringContainsString(PiiRedactor::CPR_MASK, $payload);
    }

    // The modeler still reads the redacted history back in ECA's format.
    $read = $browser->getHistoryByEvent('model::event');
    $this->assertSame(PiiRedactor::CPR_MASK, $read[0]['steps'][0]['tokens'][0]['value']);
    $this->assertSame(PiiRedactor::REDACTED, $read[0]['ip']);
    $this->assertSame(PiiRedactor::REDACTED, $browser->getHistoryByHash($hash)['steps'][0]['tokens'][1]['value']);
  }

  /**
   * Opens an ECA-compressed tempstore payload.
   */
  protected function decompress(string $data): string {
    $this->assertStringStartsWith("\x1f\x9d", $data);
    return (string) gzuncompress(substr($data, 2));
  }

}
