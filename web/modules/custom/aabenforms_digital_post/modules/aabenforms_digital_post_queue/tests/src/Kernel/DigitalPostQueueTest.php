<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post_queue\Kernel;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\Service\DigitalPostSenderInterface;
use Drupal\aabenforms_digital_post_queue\Plugin\AdvancedQueue\JobType\DigitalPostSendJob;
use Drupal\advancedqueue\Job;
use Drupal\KernelTests\KernelTestBase;
use Psr\Log\NullLogger;

/**
 * Tests the Digital Post resilience queue engine.
 *
 * Proves the mechanism advances on its own and honours the flow + async API:
 * a queued send maps to success/pending -> done (and the case is stamped so the
 * async receipt reconciles it), a transient failure -> retry, a permanent
 * failure -> dead-letter; and an enqueued job drains through the real processor.
 *
 * @group aabenforms_digital_post
 */
class DigitalPostQueueTest extends KernelTestBase {

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
    'advancedqueue',
    'aabenforms_core',
    'aabenforms_case',
    'aabenforms_digital_post',
    'aabenforms_digital_post_beskedfordeler',
    'aabenforms_digital_post_queue',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aabenforms_case');
    // The Database queue backend stores jobs in the {advancedqueue} table.
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $this->installSchema('aabenforms_digital_post', ['aabenforms_digital_post_log']);
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log', 'aabenforms_trace']);
    $this->installConfig(['aabenforms_digital_post', 'aabenforms_digital_post_queue']);
    \Drupal::moduleHandler()->loadInclude('aabenforms_digital_post_beskedfordeler', 'install');
    _aabenforms_digital_post_beskedfordeler_install_log_columns();
  }

  /**
   * An accepted send (success/pending) completes and stamps its case.
   */
  public function testAcceptedSucceedsAndStampsCase(): void {
    $case = $this->seedCase();
    $job = $this->job(['case_id' => $case->id()]);
    $result = $this->jobType(Result::pending('tx-1', 'accepted'))->process($job);

    $this->assertSame(Job::STATE_SUCCESS, $result->getState());
    $reloaded = $this->reloadCase($case);
    $this->assertSame('tx-1', $reloaded->get('digital_post_tx')->value);
    $this->assertSame('pending', $reloaded->get('digital_post_receipt_status')->value);
  }

  /**
   * A transient transport failure returns a RETRYABLE failure (not dead-letter).
   */
  public function testTransientFailureRetries(): void {
    $result = $this->jobType(Result::failure('tx-2', Result::REASON_TRANSPORT, 'timeout'))->process($this->job());
    $this->assertSame(Job::STATE_FAILURE, $result->getState());
    // NULL max_retries = fall back to the JobType's max_retries (5): it retries.
    $this->assertNull($result->getMaxRetries());
  }

  /**
   * A permanent failure dead-letters (zero retries).
   */
  public function testPermanentFailureDeadLetters(): void {
    $result = $this->jobType(Result::failure('tx-3', Result::REASON_VALIDATION, 'bad recipient'))->process($this->job());
    $this->assertSame(Job::STATE_FAILURE, $result->getState());
    $this->assertSame(0, $result->getMaxRetries());
  }

  /**
   * An enqueued job drains through the real processor and ends successful.
   *
   * The fake_db default transport returns success, so this proves the
   * dispatcher -> queue -> processor loop end to end and autonomously.
   */
  public function testEnqueueAndDrain(): void {
    /** @var \Drupal\aabenforms_digital_post_queue\Service\DigitalPostQueueDispatcher $dispatcher */
    $dispatcher = $this->container->get('aabenforms_digital_post_queue.dispatcher');
    $post = new DigitalPost(Recipient::cvr('12345678'), new Sender('12345678', 'Kommune'), 'Emne', '<p>Body</p>');
    $dispatcher->enqueue($post, 'tx-drain');

    $queue = $this->container->get('entity_type.manager')->getStorage('advancedqueue_queue')->load('digital_post');
    $this->assertSame(1, (int) $queue->getBackend()->countJobs()['queued']);

    $this->container->get('advancedqueue.processor')->processQueue($queue);
    $counts = $queue->getBackend()->countJobs();
    $this->assertSame(1, (int) ($counts['success'] ?? 0));
    $this->assertSame(0, (int) ($counts['queued'] ?? 0));
  }

  /**
   * Builds the JobType with a stubbed sender returning the given Result.
   */
  private function jobType(Result $result): DigitalPostSendJob {
    $sender = new class($result) implements DigitalPostSenderInterface {

      public function __construct(private readonly Result $result) {}

      /**
       * {@inheritdoc}
       */
      public function send(DigitalPost $post): Result {
        return $this->result;
      }

      /**
       * {@inheritdoc}
       */
      public function testMode(): string {
        return 'fake_db';
      }

    };
    return new DigitalPostSendJob(
      [],
      'aabenforms_digital_post_send',
      ['max_retries' => 5, 'retry_delay' => 60],
      $sender,
      $this->container->get('aabenforms_core.cpr_access'),
      $this->container->get('entity_type.manager'),
      new NullLogger(),
    );
  }

  /**
   * A job carrying a CVR recipient (no CPR encryption needed for the test).
   */
  private function job(array $extra = []): Job {
    return Job::create('aabenforms_digital_post_send', [
      'transaction_id' => $extra['transaction_id'] ?? 'tx-x',
      'recipient_type' => 'cvr',
      'recipient' => '12345678',
      'sender_cvr' => '12345678',
      'subject' => 'Emne',
      'body' => '<p>Body</p>',
      'type' => DigitalPost::TYPE_DIGITAL_POST,
      'case_id' => $extra['case_id'] ?? NULL,
    ]);
  }

  /**
   * Seeds a case in a post-send lifecycle state.
   */
  private function seedCase(): AabenformsCase {
    $case = AabenformsCase::create(['case_type' => 'merudgifter', 'status' => 'afgoerelse']);
    $case->save();
    return $case;
  }

  /**
   * Reloads a case fresh.
   */
  private function reloadCase(AabenformsCase $case): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $storage->resetCache([$case->id()]);
    return $storage->load($case->id());
  }

}
