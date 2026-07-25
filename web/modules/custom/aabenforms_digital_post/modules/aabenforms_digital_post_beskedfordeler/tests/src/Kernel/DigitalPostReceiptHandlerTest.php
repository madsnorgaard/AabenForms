<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post_beskedfordeler\Kernel;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_digital_post_beskedfordeler\Service\DigitalPostReceiptHandler;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests asynchronous receipt reconciliation onto the log row and the case.
 *
 * @group aabenforms_digital_post
 */
class DigitalPostReceiptHandlerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'node',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'domain_access',
    'modeler_api',
    'eca',
    'webform',
    'aabenforms_core',
    'aabenforms_case',
    'aabenforms_digital_post',
    'aabenforms_digital_post_beskedfordeler',
  ];

  /**
   * The handler under test.
   */
  protected DigitalPostReceiptHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aabenforms_case');
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log', 'aabenforms_trace']);
    // The submodule's hook_install adds the receipt columns; run it explicitly.
    \Drupal::moduleHandler()->loadInclude('aabenforms_digital_post_beskedfordeler', 'install');
    _aabenforms_digital_post_beskedfordeler_install_log_columns();

    $this->handler = $this->container->get('aabenforms_digital_post_beskedfordeler.receipt_handler');
  }

  /**
   * A delivered receipt finalises the log row and the case (audited revision).
   */
  public function testDeliveredFinalisesCase(): void {
    $case = $this->seed('tx-delivered');
    $vidBefore = $case->getRevisionId();

    $state = $this->handler->handle('tx-delivered', DigitalPostReceiptHandler::OUTCOME_DELIVERED, FALSE, 'e-Boks');
    $this->assertSame(DigitalPostReceiptHandler::STATE_DELIVERED, $state);

    $reloaded = $this->reload($case);
    $this->assertSame('delivered', $reloaded->get('digital_post_receipt_status')->value);
    $this->assertNotEmpty($reloaded->get('digital_post_delivered_at')->value);
    $this->assertNotSame($vidBefore, $reloaded->getRevisionId(), 'A new revision was written.');
    $this->assertSame('delivered', $this->logStatus('tx-delivered'));
  }

  /**
   * A permanent failure marks the case failed.
   */
  public function testPermanentFailureMarksCaseFailed(): void {
    $this->seed('tx-failed');
    $state = $this->handler->handle('tx-failed', DigitalPostReceiptHandler::OUTCOME_FAILED, FALSE, 'recipient unknown');
    $this->assertSame(DigitalPostReceiptHandler::STATE_FAILED, $state);
    $this->assertSame('failed', $this->reloadByTx('tx-failed')->get('digital_post_receipt_status')->value);
    $this->assertSame('failed', $this->logStatus('tx-failed'));
  }

  /**
   * A transient failure leaves the case pending (no finalisation, no revision).
   */
  public function testTransientFailureStaysPending(): void {
    $case = $this->seed('tx-transient');
    $vidBefore = $case->getRevisionId();

    $state = $this->handler->handle('tx-transient', DigitalPostReceiptHandler::OUTCOME_FAILED, TRUE, 'carrier 503');
    $this->assertSame(DigitalPostReceiptHandler::STATE_PENDING, $state);

    $reloaded = $this->reload($case);
    $this->assertSame('pending', $reloaded->get('digital_post_receipt_status')->value);
    $this->assertSame($vidBefore, $reloaded->getRevisionId(), 'No revision on a transient failure.');
    $this->assertSame('pending', $this->logStatus('tx-transient'));
  }

  /**
   * An unknown transaction id is reported as unknown and changes nothing.
   */
  public function testUnknownTransaction(): void {
    $state = $this->handler->handle('tx-nope', DigitalPostReceiptHandler::OUTCOME_DELIVERED, FALSE, '');
    $this->assertSame(DigitalPostReceiptHandler::STATE_UNKNOWN, $state);
  }

  /**
   * Seeds a pending log row and a case both keyed to a transaction id.
   */
  private function seed(string $transactionId): AabenformsCase {
    $this->container->get('database')->insert('aabenforms_digital_post_log')
      ->fields([
        'transaction_id' => $transactionId,
        'mode' => 'live_test',
        'recipient_type' => 'cpr',
        'recipient_identifier_hash' => hash('sha256', 'cpr:2512489996'),
        'sender_cvr' => '12345678',
        'subject' => 'Afgørelse',
        'status' => 'pending',
        'reason_code' => NULL,
        'payload' => '{}',
        'response' => NULL,
        'created' => 1706356800,
        'receipt_status' => 'pending',
        'delivered_at' => NULL,
        'receipt_reason' => NULL,
      ])
      ->execute();

    $case = AabenformsCase::create([
      'case_type' => 'merudgifter',
      'status' => 'afgoerelse',
      'digital_post_tx' => $transactionId,
      'digital_post_receipt_status' => 'pending',
    ]);
    $case->save();
    return $case;
  }

  /**
   * Reloads a case fresh from storage.
   */
  private function reload(AabenformsCase $case): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $storage->resetCache([$case->id()]);
    return $storage->load($case->id());
  }

  /**
   * Loads the case correlated to a transaction id.
   */
  private function reloadByTx(string $transactionId): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('digital_post_tx', $transactionId)->execute();
    return $storage->load((int) reset($ids));
  }

  /**
   * The receipt_status recorded on the log row.
   */
  private function logStatus(string $transactionId): ?string {
    $value = $this->container->get('database')
      ->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['receipt_status'])
      ->condition('transaction_id', $transactionId)
      ->execute()->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

}
