<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Kernel;

use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the DEMO physical post transport against the real log table.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\TestMode\PhysicalPostTransport
 * @group aabenforms_digital_post
 */
class PhysicalPostTransportTest extends KernelTestBase {

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
    'aabenforms_core',
    'aabenforms_digital_post',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['aabenforms_digital_post']);
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
  }

  /**
   * A post for Birthe.
   */
  private function post(): DigitalPost {
    return new DigitalPost(Recipient::cpr('2403482210'), new Sender('12345678', 'Kommune'), 'Afgørelse', '<p>Brev</p>');
  }

  /**
   * Counts log rows.
   */
  private function rows(): array {
    return $this->container->get('database')->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['transaction_id', 'channel', 'status', 'mode', 'reason_code'])
      ->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * In a demo mode the print job is recorded once (idempotent).
   */
  public function testRecordsPrintJobOnceInDemoMode(): void {
    $transport = $this->container->get('aabenforms_digital_post.physical_transport');
    $first = $transport->send($this->post(), 'tx-print-1');
    $second = $transport->send($this->post(), 'tx-print-1');
    $this->assertTrue($first->isSuccess());
    $this->assertTrue($second->isSuccess());
    $rows = $this->rows();
    $this->assertCount(1, $rows, 'A re-send does not queue a second letter');
    $this->assertSame('physical', $rows[0]['channel']);
    $this->assertSame('queued_print', $rows[0]['status']);
    $this->assertSame('physical_demo', $rows[0]['mode']);
  }

  /**
   * In a live mode the DEMO print channel refuses and records the refusal.
   */
  public function testRefusesInLiveMode(): void {
    $this->config('aabenforms_digital_post.settings')->set('test_mode', 'live')->save();
    $result = $this->container->get('aabenforms_digital_post.physical_transport')->send($this->post(), 'tx-print-2');
    $this->assertFalse($result->isSuccess());
    $this->assertSame(Result::REASON_PRINT_UNAVAILABLE, $result->reasonCode);
    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $this->assertSame('failure', $rows[0]['status']);
    $this->assertSame('PRINT_UNAVAILABLE', $rows[0]['reason_code']);
  }

}
