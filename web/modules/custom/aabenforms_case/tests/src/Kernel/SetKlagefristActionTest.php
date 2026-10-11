<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_case\Kernel;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the aabenforms_case_set_klagefrist action.
 *
 * The appeal clock starts from notification, so this action only stamps the
 * klagefrist on a decided (afgoerelse) case and is idempotent.
 *
 * @group aabenforms_case
 */
class SetKlagefristActionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'modeler_api',
    'eca',
    'webform',
    'aabenforms_core',
    'aabenforms_case',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aabenforms_case');
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);
  }

  /**
   * Creates a persisted case in the given status and sets the case_id token.
   */
  protected function caseIn(string $status): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    /** @var \Drupal\aabenforms_case\Entity\AabenformsCase $case */
    $case = $storage->create(['title' => 'Sag', 'case_type' => 'merudgifter', 'status' => $status]);
    $case->save();
    $this->container->get('eca.token_services')->addTokenData('case_id', (string) $case->id());
    return $case;
  }

  /**
   * Reloads a case fresh.
   */
  protected function reload(int $id): AabenformsCase {
    $storage = $this->container->get('entity_type.manager')->getStorage('aabenforms_case');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Runs the action.
   */
  protected function setKlagefrist(int $uger = 4): void {
    $this->container->get('plugin.manager.action')
      ->createInstance('aabenforms_case_set_klagefrist', ['klagefrist_uger' => $uger])
      ->execute();
  }

  /**
   * Starts the klagefrist on a decided case.
   */
  public function testStartsKlagefristOnDecidedCase(): void {
    $case = $this->caseIn('afgoerelse');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->setKlagefrist(4);
    $this->assertSame($now + (4 * 7 * 86400), (int) $this->reload((int) $case->id())->get('klagefrist')->value);
  }

  /**
   * Refuses to start the klagefrist on a case that is not decided.
   */
  public function testRejectsWhenNotDecided(): void {
    $case = $this->caseIn('oplyst');
    $this->setKlagefrist(4);
    $this->assertNull($this->reload((int) $case->id())->get('klagefrist')->value, 'No klagefrist set on an undecided case');
  }

  /**
   * Is idempotent: a second run does not move an existing klagefrist.
   */
  public function testIdempotent(): void {
    $case = $this->caseIn('afgoerelse');
    $this->setKlagefrist(4);
    $first = (int) $this->reload((int) $case->id())->get('klagefrist')->value;
    $this->setKlagefrist(8);
    $this->assertSame($first, (int) $this->reload((int) $case->id())->get('klagefrist')->value, 'Klagefrist unchanged on re-run');
  }

  /**
   * A physical letter starts the klagefrist from send date + delivery days.
   */
  public function testPhysicalChannelShiftsKlagefrist(): void {
    $this->config('aabenforms_case.settings')->set('physical_post_delivery_days', 5)->save();
    $case = $this->caseIn('afgoerelse');
    $case->set('delivery_channel', 'physical');
    $case->save();
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->setKlagefrist(4);
    $this->assertSame($now + (5 * 86400) + (4 * 7 * 86400), (int) $this->reload((int) $case->id())->get('klagefrist')->value);
  }

  /**
   * A recorded digital channel keeps the klagefrist exactly as before.
   */
  public function testDigitalChannelKeepsKlagefrist(): void {
    $case = $this->caseIn('afgoerelse');
    $case->set('delivery_channel', 'digital');
    $case->save();
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->setKlagefrist(4);
    $this->assertSame($now + (4 * 7 * 86400), (int) $this->reload((int) $case->id())->get('klagefrist')->value);
  }

}
