<?php

namespace Drupal\Tests\aabenforms_core\Unit\Family;

use Drupal\aabenforms_core\Family\DemoFamilyRepository;
use Drupal\aabenforms_core\Family\GuardianType;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the demo family scenarios backing custody-aware flows.
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Family\DemoFamilyRepository
 * @group aabenforms_core
 */
class DemoFamilyRepositoryTest extends UnitTestCase {

  /**
   * The repository under test.
   *
   * @var \Drupal\aabenforms_core\Family\DemoFamilyRepository
   */
  protected DemoFamilyRepository $repository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->repository = new DemoFamilyRepository();
  }

  /**
   * Freja holds custody of two children, shared with Lars.
   *
   * @covers ::childrenOf
   */
  public function testSharedCustodyParentSeesBothChildren(): void {
    $children = $this->repository->childrenOf('0101904521');
    $this->assertCount(2, $children);

    $emil = $children[0];
    $this->assertSame('Emil Nielsen Andersen', $emil['full_name']);
    $this->assertCount(2, $emil['guardians']);

    $guardianCprs = array_column($emil['guardians'], 'cpr');
    $this->assertContains('0101904521', $guardianCprs);
    $this->assertContains('0803755210', $guardianCprs);
  }

  /**
   * The separated father sees the same children with a different address.
   *
   * @covers ::childrenOf
   */
  public function testSeparatedFatherSharesCustodyFromOtherAddress(): void {
    $children = $this->repository->childrenOf('0803755210');
    $this->assertCount(2, $children);

    foreach ($children[0]['guardians'] as $guardian) {
      if ($guardian['cpr'] === '0803755210') {
        $this->assertSame(GuardianType::FATHER, $guardian['type']);
        $this->assertFalse($guardian['same_address']);
      }
    }
  }

  /**
   * Sofie holds sole custody of one child.
   *
   * @covers ::childrenOf
   * @covers ::guardiansOf
   */
  public function testSoleCustody(): void {
    $children = $this->repository->childrenOf('2506924015');
    $this->assertCount(1, $children);
    $this->assertSame('Alma Hansen', $children[0]['full_name']);

    $guardians = $this->repository->guardiansOf('1203192345');
    $this->assertCount(1, $guardians);
    $this->assertSame('2506924015', $guardians[0]['cpr']);
  }

  /**
   * An adult without children gets an empty list.
   *
   * @covers ::childrenOf
   */
  public function testAdultWithoutChildren(): void {
    $this->assertSame([], $this->repository->childrenOf('1502856234'));
  }

  /**
   * Birth dates support the under-15 Digital Post rule boundary.
   *
   * @covers ::birthDateOf
   */
  public function testBirthDates(): void {
    $emil = $this->repository->birthDateOf('0109182345');
    $this->assertSame('2018-09-01', $emil?->format('Y-m-d'));

    // Oliver is the 15+ scenario: born 2010, over 15 from 2025 on.
    $oliver = $this->repository->birthDateOf('2005102345');
    $this->assertSame('2010-05-20', $oliver?->format('Y-m-d'));

    $this->assertNull($this->repository->birthDateOf('9999999999'));
  }

  /**
   * Unknown child CPRs yield no guardians.
   *
   * @covers ::guardiansOf
   */
  public function testUnknownChildHasNoGuardians(): void {
    $this->assertSame([], $this->repository->guardiansOf('9999999999'));
  }

}
