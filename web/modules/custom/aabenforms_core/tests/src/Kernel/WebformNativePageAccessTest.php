<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\webform\Entity\Webform;

/**
 * Webform's native submission page is closed to everyone but webform admins.
 *
 * The API submit route is the only citizen path: it binds the applicant CPR
 * to the MitID session. The native page at /webform/{id} would accept any
 * CPR, so aabenforms_core_webform_access() forbids 'submission_page' for
 * accounts without 'administer webform' and leaves the other operations
 * alone.
 *
 * @group aabenforms_core
 */
class WebformNativePageAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'key',
    'encrypt',
    'real_aes',
    'domain',
    'webform',
    'aabenforms_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['webform']);
    // uid 1 is the superuser; create it so the users below are ordinary.
    $this->createUser();
  }

  /**
   * Anonymous and ordinary users cannot open the native page.
   */
  public function testNativePageIsForbiddenForCitizens(): void {
    $webform = Webform::create(['id' => 'friplads_test', 'title' => 'Friplads']);
    $webform->save();

    $anonymous = $this->createUser([], 'anon');
    $anonymous = \Drupal::entityTypeManager()->getStorage('user')->load(0) ?? $anonymous;
    $this->assertFalse($webform->access('submission_page', $anonymous), 'Anonymous cannot open /webform/{id}.');

    $citizen = $this->createUser([]);
    $this->assertFalse($webform->access('submission_page', $citizen), 'An authenticated user cannot open /webform/{id}.');
  }

  /**
   * Staff who administer webforms keep the page, and other operations are untouched.
   */
  public function testStaffKeepThePageAndApiOperationsAreUntouched(): void {
    $webform = Webform::create(['id' => 'friplads_test', 'title' => 'Friplads']);
    $webform->save();

    $staff = $this->createUser(['administer webform']);
    $this->assertTrue($webform->access('submission_page', $staff), 'Webform administrators keep the native page.');

    // The API controller checks 'view' and 'submission_create'. Webform's
    // default access rules let everyone create a submission, and the hook
    // must not change that, otherwise /api/webform/{id}/submit breaks.
    $citizen = $this->createUser([]);
    $this->assertTrue($webform->access('submission_create', $citizen), 'submission_create is not touched by the hook.');
  }

}
