<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_case\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the aabenforms_employment_check (sygedagpenge) action.
 *
 * @group aabenforms_case
 */
class EmploymentCheckActionTest extends KernelTestBase {

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
    $this->installSchema('aabenforms_core', ['aabenforms_audit_log']);
  }

  /**
   * Runs the check for a CPR and returns the token values by suffix.
   */
  protected function check(string $cpr, string $firstSickDay = ''): array {
    $tokens = $this->container->get('eca.token_services');
    $tokens->addTokenData('cpr', $cpr);
    $tokens->addTokenData('sick', $firstSickDay);
    $this->container->get('plugin.manager.action')
      ->createInstance('aabenforms_employment_check', [
        'cpr_token' => 'cpr',
        'first_sick_day_token' => 'sick',
        'result_token' => 'emp',
        'opfoelgning_weeks' => 8,
      ])
      ->execute();
    $out = [];
    foreach (['status', 'employer', 'hours', 'first_sick_day', 'employer_period_end', 'opfoelgning'] as $suffix) {
      $value = $tokens->getTokenData('emp_' . $suffix);
      $out[$suffix] = is_object($value) && method_exists($value, 'getString') ? $value->getString() : $value;
    }
    return $out;
  }

  /**
   * Mikkel (demo persona) meets the beskæftigelseskrav; dates are computed.
   */
  public function testMikkelMeetsRequirement(): void {
    $out = $this->check('1502856234');
    $this->assertSame('met', $out['status']);
    $this->assertStringContainsString('(demo)', $out['employer']);
    $this->assertGreaterThanOrEqual(240, (int) $out['hours']);
    $first = \DateTimeImmutable::createFromFormat('!d-m-Y', $out['first_sick_day'], new \DateTimeZone('Europe/Copenhagen'));
    $this->assertSame($first->modify('+8 weeks')->format('d-m-Y'), $out['opfoelgning'], 'First follow-up 8 calendar weeks after the first sick day');
    $this->assertSame($first->modify('+29 days')->format('d-m-Y'), $out['employer_period_end']);

    $row = $this->container->get('database')->select('aabenforms_audit_log', 'a')
      ->fields('a')
      ->condition('action', 'employment_lookup')
      ->execute()
      ->fetchAssoc();
    $this->assertSame('sygedagpenge', $row['purpose']);
    $this->assertSame(hash('sha256', '1502856234'), $row['identifier_hash']);
    $this->assertStringNotContainsString('1502856234', (string) $row['context']);
  }

  /**
   * Unknown employment does not meet the requirement.
   *
   * The submitted first sick day is the fallback for the follow-up date,
   * which is computed across the October DST change.
   */
  public function testUnknownEmploymentIsNotMet(): void {
    $out = $this->check('0101801234', '2026-10-05');
    $this->assertSame('not_met', $out['status']);
    $this->assertSame('0', $out['hours']);
    $this->assertSame('05-10-2026', $out['first_sick_day']);
    $this->assertSame('30-11-2026', $out['opfoelgning']);
  }

  /**
   * An empty CPR is not met and never throws.
   */
  public function testEmptyCpr(): void {
    $this->assertSame('not_met', $this->check('')['status']);
  }

}
