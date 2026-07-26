<?php

namespace Drupal\Tests\aabenforms_digital_post\Unit\Service;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_digital_post\DigitalPost\RecipientResolution;
use Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the guardian recipient resolver (the under-15/15+ rule).
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver
 * @group aabenforms_digital_post
 */
class GuardianRecipientResolverTest extends UnitTestCase {

  /**
   * Mock family lookup.
   *
   * @var \Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $familyLookup;

  /**
   * The resolver under test.
   *
   * @var \Drupal\aabenforms_digital_post\Service\GuardianRecipientResolver
   */
  protected GuardianRecipientResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->familyLookup = $this->createMock(FamilyRelationsLookupInterface::class);

    // Fixed "today": 2026-07-26.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn((int) (new \DateTimeImmutable('2026-07-26T12:00:00+02:00'))->format('U'));

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $this->resolver = new GuardianRecipientResolver($this->familyLookup, $time, $loggerFactory);
  }

  /**
   * A child under 15 resolves to each registered custody holder.
   *
   * @covers ::resolveForChild
   */
  public function testUnder15ResolvesToBothGuardians(): void {
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2018-09-01'));
    $this->familyLookup->method('guardiansOf')->willReturn([
      ['cpr' => '0101904521', 'type' => 3, 'full_name' => 'Freja Nielsen', 'same_address' => TRUE],
      ['cpr' => '0803755210', 'type' => 4, 'full_name' => 'Lars Andersen', 'same_address' => FALSE],
    ]);

    $resolution = $this->resolver->resolveForChild('0109182345');

    $this->assertSame(RecipientResolution::RULE_GUARDIANS, $resolution->rule);
    $this->assertSame(['0101904521', '0803755210'], $resolution->cprs());
    $this->assertSame('guardian', $resolution->recipients[0]['role']);
  }

  /**
   * A 15+ pupil receives the post directly; guardians are never queried.
   *
   * @covers ::resolveForChild
   */
  public function testPupilAged15PlusReceivesDirectly(): void {
    // Born 2010-05-20: 16 years old on 2026-07-26.
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2010-05-20'));
    $this->familyLookup->expects($this->never())->method('guardiansOf');

    $resolution = $this->resolver->resolveForChild('2005102345');

    $this->assertSame(RecipientResolution::RULE_PUPIL, $resolution->rule);
    $this->assertSame(['2005102345'], $resolution->cprs());
    $this->assertSame('pupil', $resolution->recipients[0]['role']);
  }

  /**
   * The 15th birthday itself flips the rule to direct delivery.
   *
   * @covers ::resolveForChild
   */
  public function testExactly15YearsOldReceivesDirectly(): void {
    // Born 2011-07-26: turns exactly 15 on the fixed "today".
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2011-07-26'));

    $resolution = $this->resolver->resolveForChild('2607112345');

    $this->assertSame(RecipientResolution::RULE_PUPIL, $resolution->rule);
  }

  /**
   * One day before the 15th birthday, guardians still receive the post.
   *
   * @covers ::resolveForChild
   */
  public function testDayBefore15thBirthdayGoesToGuardians(): void {
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2011-07-27'));
    $this->familyLookup->method('guardiansOf')->willReturn([
      ['cpr' => '0101904521', 'type' => 3, 'full_name' => 'Freja Nielsen', 'same_address' => TRUE],
    ]);

    $resolution = $this->resolver->resolveForChild('2707112345');

    $this->assertSame(RecipientResolution::RULE_GUARDIANS, $resolution->rule);
  }

  /**
   * Sole custody yields exactly one recipient.
   *
   * @covers ::resolveForChild
   */
  public function testSoleCustodySingleRecipient(): void {
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2019-03-12'));
    $this->familyLookup->method('guardiansOf')->willReturn([
      ['cpr' => '2506924015', 'type' => 3, 'full_name' => 'Sofie Hansen', 'same_address' => TRUE],
    ]);

    $resolution = $this->resolver->resolveForChild('1203192345');

    $this->assertSame(RecipientResolution::RULE_GUARDIANS, $resolution->rule);
    $this->assertSame(['2506924015'], $resolution->cprs());
  }

  /**
   * Unknown birth date fails closed to RULE_NONE.
   *
   * @covers ::resolveForChild
   */
  public function testUnknownBirthDateFailsClosed(): void {
    $this->familyLookup->method('birthDateOf')->willReturn(NULL);

    $resolution = $this->resolver->resolveForChild('9999999999');

    $this->assertSame(RecipientResolution::RULE_NONE, $resolution->rule);
    $this->assertFalse($resolution->hasRecipients());
  }

  /**
   * A child without registered custody holders fails closed.
   *
   * @covers ::resolveForChild
   */
  public function testNoGuardiansFailsClosed(): void {
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2018-09-01'));
    $this->familyLookup->method('guardiansOf')->willReturn([]);

    $resolution = $this->resolver->resolveForChild('0109182345');

    $this->assertSame(RecipientResolution::RULE_NONE, $resolution->rule);
  }

  /**
   * Registry failure resolves to RULE_NONE instead of throwing.
   *
   * @covers ::resolveForChild
   */
  public function testRegistryErrorFailsClosed(): void {
    $this->familyLookup->method('birthDateOf')->willThrowException(new \RuntimeException('down'));

    $resolution = $this->resolver->resolveForChild('0109182345');

    $this->assertSame(RecipientResolution::RULE_NONE, $resolution->rule);
    $this->assertFalse($resolution->hasRecipients());
  }

  /**
   * Malformed CPR input fails closed without touching the registry.
   *
   * @covers ::resolveForChild
   */
  public function testMalformedCprFailsClosed(): void {
    $this->familyLookup->expects($this->never())->method('birthDateOf');

    $resolution = $this->resolver->resolveForChild('12345');

    $this->assertSame(RecipientResolution::RULE_NONE, $resolution->rule);
  }

  /**
   * Duplicate guardian CPRs are deduplicated.
   *
   * @covers ::resolveForChild
   */
  public function testDuplicateGuardiansDeduplicated(): void {
    $this->familyLookup->method('birthDateOf')->willReturn(new \DateTimeImmutable('2018-09-01'));
    $this->familyLookup->method('guardiansOf')->willReturn([
      ['cpr' => '0101904521', 'type' => 3, 'full_name' => 'Freja Nielsen', 'same_address' => TRUE],
      ['cpr' => '0101904521', 'type' => 5, 'full_name' => 'Freja Nielsen', 'same_address' => TRUE],
    ]);

    $resolution = $this->resolver->resolveForChild('0109182345');

    $this->assertSame(['0101904521'], $resolution->cprs());
  }

}
