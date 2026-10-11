<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Prefill;

use Drupal\aabenforms_core\Prefill\LegalBasis;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the per-webform legal basis map behind the prefill endpoint.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Prefill\LegalBasis
 * @group aabenforms_core
 */
class LegalBasisTest extends UnitTestCase {

  /**
   * Every reviewed form names its sector law, with the exact wording.
   *
   * @covers ::for
   * @covers ::has
   * @dataProvider reviewedForms
   */
  public function testReviewedFormsHaveTheirOwnBasis(string $webform, string $expected): void {
    $this->assertTrue(LegalBasis::has($webform));
    $this->assertSame($expected, LegalBasis::for($webform));
  }

  /**
   * The reviewed forms and their statutory references.
   */
  public static function reviewedForms(): array {
    return [
      'merudgifter' => ['merudgifter', 'Serviceloven §41, Forvaltningsloven §7, DBF art. 6(1)(e)'],
      'school_transfer' => ['school_transfer', 'Folkeskoleloven §36, forældreansvarsloven §3'],
      'klage' => ['klage', 'Forvaltningsloven §25, Retssikkerhedsloven §66'],
      'building_permit' => ['building_permit', 'Byggeloven §16'],
    ];
  }

  /**
   * An unreviewed form falls back to the general basis, never to nothing.
   *
   * @covers ::for
   * @covers ::has
   */
  public function testUnknownFormFallsBackToGeneralBasis(): void {
    $this->assertFalse(LegalBasis::has('contact'));
    $this->assertSame(LegalBasis::DEFAULT, LegalBasis::for('contact'));
    $this->assertNotSame('', LegalBasis::DEFAULT);
    $this->assertStringContainsString('Forvaltningsloven', LegalBasis::DEFAULT);
  }

  /**
   * No entry in the map is empty: a silent basis is a compliance gap.
   *
   * @covers ::for
   */
  public function testNoEntryIsEmpty(): void {
    foreach (LegalBasis::MAP as $webform => $basis) {
      $this->assertNotSame('', trim($basis), "Legal basis for $webform must not be empty");
      $this->assertSame($basis, LegalBasis::for($webform));
    }
  }

}
