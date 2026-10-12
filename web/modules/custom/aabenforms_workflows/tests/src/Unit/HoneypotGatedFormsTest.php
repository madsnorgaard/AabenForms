<?php

namespace Drupal\Tests\aabenforms_workflows\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Guards which forms lose Honeypot's minimum-time check.
 *
 * Only the gated decision forms (MitID co-sign, staff leader decision) drop
 * the time check, and they keep the hidden bot-trap field. Every other form
 * keeps both protections.
 *
 * @group aabenforms_workflows
 */
class HoneypotGatedFormsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/aabenforms_workflows.module';
  }

  /**
   * Returns the protections left after the alter hook for a form id.
   */
  protected function protectionsFor(string $form_id): array {
    $options = ['honeypot', 'time_restriction'];
    aabenforms_workflows_honeypot_form_protections_alter($options, ['#form_id' => $form_id]);
    return $options;
  }

  /**
   * Gated decision forms keep the bot trap but not the time check.
   *
   * @dataProvider gatedForms
   */
  public function testGatedFormsKeepOnlyTheBotTrap(string $form_id): void {
    $this->assertSame(['honeypot'], $this->protectionsFor($form_id));
  }

  /**
   * Data provider: the gated decision forms.
   */
  public static function gatedForms(): array {
    return [
      ['aabenforms_parent_approval_form'],
      ['aabenforms_school_transfer_decision'],
      ['aabenforms_school_transfer_decided'],
    ];
  }

  /**
   * Public and other forms keep both protections.
   *
   * @dataProvider otherForms
   */
  public function testOtherFormsKeepBothProtections(string $form_id): void {
    $this->assertSame(['honeypot', 'time_restriction'], $this->protectionsFor($form_id));
  }

  /**
   * Data provider: forms that must stay fully protected.
   */
  public static function otherForms(): array {
    return [
      ['webform_submission_skoleskift_add_form'],
      ['user_pass'],
      ['aabenforms_mitid_demo_session'],
    ];
  }

}
