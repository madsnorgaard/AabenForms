<?php

namespace Drupal\Tests\aabenforms_core\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Pins the settings the decision-state guard silently depends on.
 *
 * The guard aabenforms_core_protect_decision_fields() only covers the CREATE
 * path: it returns early when $submission->isNew() is FALSE, because the
 * citizen update is written server-side by ParentApprovalForm after the MitID,
 * CPR and custody gates have run, and re-clamping there would undo a lawful
 * approval.
 *
 * That is only safe while no client can drive an UPDATE at all. Two settings
 * make that true today, and neither lives in code:
 *
 * 1. jsonapi.settings ships read_only, so PATCH against
 *    /jsonapi/webform_submission/* is refused outright.
 * 2. The decision-bearing webforms ship `draft: none` with prepopulate off, so
 *    a citizen cannot re-open a stored submission through the form either.
 *
 * If either changes, a citizen regains a write path to caseworker_status and
 * the guard has to start covering updates. This test is the tripwire: it fails
 * loudly at that moment rather than leaving a silent privilege escalation.
 *
 * @group aabenforms_core
 */
class ProtectDecisionFieldsTest extends UnitTestCase {

  /**
   * Webforms carrying decision-state elements the guard protects.
   */
  private const DECISION_WEBFORMS = [
    'school_transfer',
    'ppr_referral',
    'guardian_consent',
    'merudgifter',
    'parent_request_form',
  ];

  /**
   * Absolute path to config/sync.
   */
  private function configSyncDir(): string {
    // This file sits at
    // web/modules/custom/aabenforms_core/tests/src/Unit/, so seven levels up
    // is the repository root.
    $root = dirname(__DIR__, 7);
    $dir = $root . '/config/sync';
    $this->assertDirectoryExists($dir, 'Expected to resolve config/sync; this test file has moved.');
    return $dir;
  }

  /**
   * JSON:API stays read-only, so no client can PATCH a stored submission.
   */
  public function testJsonapiIsReadOnly(): void {
    $file = $this->configSyncDir() . '/jsonapi.settings.yml';
    $this->assertFileExists($file);

    $settings = Yaml::decode((string) file_get_contents($file));
    $this->assertTrue(
      $settings['read_only'] ?? NULL,
      'jsonapi.settings must stay read_only. With writes enabled, a citizen could PATCH caseworker_status onto a stored submission, and aabenforms_core_protect_decision_fields() does not guard the update path.'
    );
  }

  /**
   * Decision-bearing webforms offer no citizen-driven update path.
   */
  public function testDecisionWebformsDisallowDraftsAndPrepopulate(): void {
    $checked = 0;

    foreach (self::DECISION_WEBFORMS as $webformId) {
      $file = $this->configSyncDir() . '/webform.webform.' . $webformId . '.yml';
      if (!file_exists($file)) {
        // The list spans several feature branches; skip what is not deployed
        // here rather than failing on an absence that is not a defect.
        continue;
      }
      $checked++;
      $webform = Yaml::decode((string) file_get_contents($file));
      $settings = $webform['settings'] ?? [];

      $this->assertSame('none', $settings['draft'] ?? 'none', sprintf(
        'Webform %s enables drafts. A draft is a stored submission the citizen can re-open and re-post, which is exactly the update path the decision-state guard does not cover.',
        $webformId
      ));
      $this->assertFalse((bool) ($settings['form_prepopulate'] ?? FALSE), sprintf(
        'Webform %s enables prepopulate, which lets a client seed element values from the query string, including the decision-state elements.',
        $webformId
      ));
    }

    $this->assertGreaterThan(0, $checked, 'Expected at least one decision-bearing webform in config/sync.');
  }

}
