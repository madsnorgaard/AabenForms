<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Identity;

use Drupal\aabenforms_core\Exception\ServiceplatformenException;
use Drupal\aabenforms_core\Exception\SubmissionIntegrityException;
use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Identity\SubmissionIdentityBinder;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webform\WebformInterface;

/**
 * @coversDefaultClass \Drupal\aabenforms_core\Identity\SubmissionIdentityBinder
 * @group aabenforms_core
 */
final class SubmissionIdentityBinderTest extends UnitTestCase {

  private const FREJA = '1505881234';
  private const LARS = '0803751234';
  private const EMIL = '0109181234';
  private const OLIVER = '1203211234';
  private const ALMA = '2207191234';
  private const STRANGER = '0101011234';

  /**
   * Audit rows written: [action, purpose, status, context].
   *
   * @var array[]
   */
  private array $audited = [];

  /**
   * Whether the family lookup fails.
   */
  private bool $registerDown = FALSE;

  /**
   * Builds the binder with a fake register and a recording audit logger.
   */
  private function binder(): SubmissionIdentityBinder {
    $cprAccess = $this->getMockBuilder(CprAccess::class)
      ->disableOriginalConstructor()
      ->onlyMethods([])
      ->getMock();

    $family = $this->createMock(FamilyRelationsLookupInterface::class);
    $family->method('childrenOf')->willReturnCallback(function (string $cpr): array {
      if ($this->registerDown) {
        throw new ServiceplatformenException('down', 'SF6006', 'childrenOf');
      }
      $freja = ['cpr' => self::FREJA, 'full_name' => 'Freja Nielsen'];
      $lars = ['cpr' => self::LARS, 'full_name' => 'Lars Andersen'];
      return $cpr === self::FREJA ? [
        ['cpr' => self::EMIL, 'full_name' => 'Emil Nielsen Andersen', 'guardians' => [$freja, $lars]],
        ['cpr' => self::OLIVER, 'full_name' => 'Oliver Nielsen Andersen', 'guardians' => [$freja, $lars]],
        ['cpr' => self::ALMA, 'full_name' => 'Alma Nielsen', 'guardians' => [$freja]],
      ] : [];
    });

    $audit = $this->getMockBuilder(AuditLogger::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['log'])
      ->getMock();
    $audit->method('log')->willReturnCallback(function (string $action, string $identifier, string $purpose, string $status, array $context): int {
      $this->assertStringNotContainsString(self::FREJA, json_encode($context), 'No CPR in the audit context');
      $this->audited[] = [$action, $purpose, $status, $context];
      return 1;
    });

    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    return new SubmissionIdentityBinder($cprAccess, $family, $audit, $loggers);
  }

  /**
   * A school-transfer-shaped webform.
   */
  private function webform(bool $custody = TRUE): WebformInterface {
    $elements = [
      'applicant_cpr' => ['#type' => 'cpr_field'],
      'child_name' => ['#type' => 'textfield'],
      'child_cpr' => ['#type' => 'cpr_field'],
      'parent2_cpr' => ['#type' => 'cpr_field'],
    ];
    if ($custody) {
      $elements['custody_status'] = ['#type' => 'radios'];
    }
    $webform = $this->createMock(WebformInterface::class);
    $webform->method('getElementsDecodedAndFlattened')->willReturn($elements);
    $webform->method('id')->willReturn($custody ? 'school_transfer' : 'merudgifter');
    return $webform;
  }

  /**
   * Binds and returns the result or the rejection reason.
   */
  private function bind(array $data, bool $custody = TRUE): array|string {
    try {
      return $this->binder()->bind($this->webform($custody), self::FREJA, $data, 'wf_test');
    }
    catch (SubmissionIntegrityException $e) {
      return $e->reason . ':' . $e->httpStatus;
    }
  }

  /**
   * Emil (joint custody with Lars) as the SPA submits the child: masked, by name.
   */
  private function emil(string $custody): array {
    return ['child_cpr' => '010918-XXXX', 'child_name' => 'Emil Nielsen Andersen', 'custody_status' => $custody];
  }

  /**
   * The applicant is always the verified person.
   */
  public function testApplicantIsTheSessionPerson(): void {
    foreach (['', '150588-XXXX', '1505881234', '150588-1234'] as $submitted) {
      $result = $this->bind(['applicant_cpr' => $submitted] + $this->emil('joint_agreed'));
      $this->assertSame(self::FREJA, $result['applicant_cpr'], "Submitted '$submitted'");
    }

    $this->assertSame('identity_mismatch:403', $this->bind(['applicant_cpr' => self::STRANGER]));
    $this->assertSame('submit_integrity_rejected', $this->audited[0][0]);
    $this->assertSame(['reason' => 'identity_mismatch', 'workflow_id' => 'wf_test'], $this->audited[0][3]);
  }

  /**
   * The child comes from the applicant's own custody children only.
   */
  public function testChildAndOtherParentFromTheRegister(): void {
    $result = $this->bind([
      'applicant_cpr' => '',
      'child_name' => 'Emil Nielsen Andersen',
      'child_cpr' => '010918-XXXX',
      'parent2_cpr' => self::STRANGER,
      'custody_status' => 'joint_agreed',
    ]);
    $this->assertSame(self::EMIL, $result['child_cpr']);
    $this->assertSame(self::LARS, $result['parent2_cpr'], 'The other parent always comes from the register');

    // A child outside the applicant's custody, masked or not, cannot be named.
    $this->assertSame('child_unresolved:422', $this->bind(['child_cpr' => self::STRANGER, 'custody_status' => 'sole']));
    $this->assertSame('child_unresolved:422', $this->bind(['child_cpr' => '010101-XXXX', 'custody_status' => 'sole']));
    // Without a custody gate an unresolved child is left for the flow to deny.
    $this->assertSame(self::STRANGER, $this->bind(['child_cpr' => self::STRANGER], FALSE)['child_cpr']);
  }

  /**
   * Custody is the register's, not the client's.
   */
  public function testCustodyFromTheRegister(): void {
    // Joint custody claimed as sole: rejected, so the co-sign cannot be skipped.
    $this->assertSame('custody_mismatch:422', $this->bind($this->emil('sole')));

    $agreed = $this->bind($this->emil('joint_agreed'));
    $this->assertSame('joint_agreed', $agreed['custody_status']);
    $unknown = $this->bind($this->emil('whatever'));
    $this->assertSame('joint_disagreed', $unknown['custody_status'], 'No agreement is assumed');

    // Sole custody in the register wins over any joint claim.
    $sole = $this->bind([
      'child_cpr' => '220719-XXXX',
      'custody_status' => 'joint_agreed',
      'parent2_cpr' => self::STRANGER,
    ]);
    $this->assertSame('sole', $sole['custody_status']);
    $this->assertSame('', $sole['parent2_cpr']);
  }

  /**
   * Without the register a custody-gated form fails closed.
   */
  public function testRegisterDownFailsClosed(): void {
    $this->registerDown = TRUE;
    $this->assertSame('register_unavailable:503', $this->bind($this->emil('joint_agreed')));
    // A form without a custody gate keeps the old behaviour.
    $this->assertSame('010918-XXXX', $this->bind(['child_cpr' => '010918-XXXX'], FALSE)['child_cpr']);
  }

  /**
   * Masked twins (same prefix) need the name to resolve exactly one.
   */
  public function testMaskedChildNeedsUniqueMatch(): void {
    $twin = fn (string $name): array => [
      'child_cpr' => '120321-XXXX',
      'child_name' => $name,
      'custody_status' => 'joint_agreed',
    ];
    $this->assertSame(self::OLIVER, $this->bind($twin('oliver nielsen andersen'))['child_cpr']);
    $this->assertSame('child_unresolved:422', $this->bind($twin('Someone Else')));
  }

}
