<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Identity;

use Drupal\aabenforms_core\Identity\Representation;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aabenforms_core\Identity\Representation
 * @group aabenforms_core
 */
final class RepresentationTest extends UnitTestCase {

  private const BIRTHE = '2403482210';

  /**
   * A valid claim with Birthe and one malformed entry.
   */
  private function parsed(): array {
    return Representation::fromClaim(json_encode([
      ['cpr' => '240348-2210', 'name' => 'Birthe Jensen', 'scopes' => ['klage', 'digital_post_copy']],
      ['cpr' => '123', 'name' => 'Too short', 'scopes' => ['klage']],
      ['cpr' => '0101011234', 'name' => '', 'scopes' => ['klage']],
      ['cpr' => '0101011234', 'name' => 'No scopes', 'scopes' => []],
      ['cpr' => '0101011234', 'name' => 'Bad scope', 'scopes' => ['<script>']],
      'not an entry',
    ]));
  }

  /**
   * Only well-formed entries survive, normalised.
   */
  public function testFromClaimIsStrict(): void {
    $reps = $this->parsed();
    $this->assertCount(1, $reps);
    $this->assertSame(self::BIRTHE, $reps[0]['cpr']);
    $this->assertSame('fuldmagt', $reps[0]['basis']);
    $this->assertSame([], Representation::fromClaim('not json'));
    $this->assertSame([], Representation::fromClaim(['cpr' => self::BIRTHE]), 'An object is not a list');
    $this->assertSame([], Representation::fromClaim(NULL));
  }

  /**
   * The public view never carries a full CPR.
   */
  public function testPublicViewIsMasked(): void {
    $view = Representation::publicView($this->parsed());
    $this->assertSame('240348-XXXX', $view[0]['cpr']);
    $this->assertSame(0, $view[0]['index']);
    $this->assertStringNotContainsString(self::BIRTHE, json_encode($view));
  }

  /**
   * An index resolves only within the representation's scopes.
   */
  public function testFindRespectsScope(): void {
    $reps = $this->parsed();
    $this->assertSame(self::BIRTHE, Representation::find($reps, 0, 'klage')['cpr']);
    $this->assertSame(self::BIRTHE, Representation::find($reps, '0', 'klage')['cpr']);
    $this->assertNull(Representation::find($reps, 0, 'merudgifter'), 'Out of scope');
    $this->assertNull(Representation::find($reps, 1, 'klage'), 'No such entry');
    $this->assertNull(Representation::find($reps, '0; DROP', 'klage'));
    $this->assertNull(Representation::find($reps, -1, 'klage'));
  }

  /**
   * A subject CPR is covered only within scope.
   */
  public function testCovering(): void {
    $reps = $this->parsed();
    $this->assertNotNull(Representation::covering($reps, self::BIRTHE, 'klage'));
    $this->assertNull(Representation::covering($reps, self::BIRTHE, 'sygedagpenge'));
    $this->assertNull(Representation::covering($reps, '0101011234', 'klage'));
  }

}
