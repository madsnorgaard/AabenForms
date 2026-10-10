<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Enrolment;

use Drupal\aabenforms_core\Enrolment\DemoPupilEnrolment;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the demo STIL pupil enrolment rail.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Enrolment\DemoPupilEnrolment
 * @group aabenforms_core
 */
class DemoPupilEnrolmentTest extends UnitTestCase {

  /**
   * The demo children resolve to their fixture schools and grades.
   *
   * @covers ::currentInstitution
   * @covers ::isDemoMode
   */
  public function testDemoChildrenResolveToFixtureSchools(): void {
    $rail = new DemoPupilEnrolment();
    $this->assertTrue($rail->isDemoMode(), 'The demo rail is never presented as live');
    $this->assertSame(['institution_number' => '751101', 'name' => 'Frederiksbjerg Skole', 'grade' => '2. klasse'], $rail->currentInstitution('0109182345'));
    $this->assertSame(['institution_number' => '751102', 'name' => 'Katrinebjergskolen', 'grade' => '9. klasse'], $rail->currentInstitution('200510-2345'));
    $this->assertSame(['institution_number' => '751103', 'name' => 'Møllevangskolen', 'grade' => '1. klasse'], $rail->currentInstitution('1203192345'));
    $this->assertNull($rail->currentInstitution('0101904521'), 'An adult is not enrolled');
    $this->assertNull($rail->currentInstitution(''));
  }

  /**
   * The school name comes from the institution registry when available.
   *
   * @covers ::currentInstitution
   */
  public function testNameResolvesThroughRegistry(): void {
    $institution = new class {

      /**
       * Returns the registry name.
       */
      public function label(): string {
        return 'Frederiksbjerg Skole (registret)';
      }

    };
    $registry = new class($institution) {

      public function __construct(private object $institution) {
      }

      /**
       * Finds by number.
       */
      public function findByNumber(string $number): ?object {
        return $number === '751101' ? $this->institution : NULL;
      }

    };
    $rail = new DemoPupilEnrolment($registry);
    $this->assertSame('Frederiksbjerg Skole (registret)', $rail->currentInstitution('0109182345')['name']);
    // Unknown in the registry: fixture name is kept.
    $this->assertSame('Katrinebjergskolen', $rail->currentInstitution('2005102345')['name']);
  }

}
