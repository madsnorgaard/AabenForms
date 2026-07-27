<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_workflows\Unit\Service;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drupal\aabenforms_workflows\Service\ParentCprVerifier;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the parent-approval CPR-match security gate.
 *
 * Locks in the four-way result protocol (match / mismatch / missing MitID CPR
 * / missing expected CPR), the digit-only normalisation that lets a hyphenated
 * input compare equal to a raw input, and the audit-log shape that hashes
 * both CPRs on mismatch so an investigator can detect "same wrong CPR being
 * retried" without the raw values ever landing in the audit table.
 *
 * Issue #54.
 *
 * @coversDefaultClass \Drupal\aabenforms_workflows\Service\ParentCprVerifier
 * @group aabenforms_workflows
 */
class ParentCprVerifierTest extends UnitTestCase {

  /**
   * The verifier under test.
   */
  protected ParentCprVerifier $verifier;

  /**
   * Mock MitID session manager.
   *
   * @var \Drupal\aabenforms_mitid\Service\MitIdSessionManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $sessionManager;

  /**
   * Mock audit logger.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $auditLogger;

  /**
   * Mock logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mock family/custody lookup.
   *
   * @var \Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $familyLookup;

  /**
   * Webform ids the custody gate applies to in the current test.
   *
   * @var array
   */
  protected array $custodyGatedForms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->sessionManager = $this->createMock(MitIdSessionManager::class);
    $this->auditLogger = $this->createMock(AuditLogger::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    // CprAccess mock: reveal() simulates decryption by stripping an AFENC1:
    // prefix, so tests can pass either plaintext or "encrypted" CPR.
    $cprAccess = $this->createMock(CprAccess::class);
    $cprAccess->method('reveal')->willReturnCallback(
      static fn (string $v): string => str_starts_with($v, 'AFENC1:') ? substr($v, 7) : $v
    );

    $this->familyLookup = $this->createMock(FamilyRelationsLookupInterface::class);

    // Config factory: custody_gated_forms reads $this->custodyGatedForms so
    // individual tests can switch the gate on for a form id.
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      fn ($key) => $key === 'custody_gated_forms' ? $this->custodyGatedForms : NULL
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $this->verifier = new ParentCprVerifier(
      $this->sessionManager,
      $this->auditLogger,
      $logger_factory,
      $cprAccess,
      $this->familyLookup,
      $configFactory,
    );
  }

  /**
   * The stored parent CPR, encrypted at rest, is decrypted before comparison.
   *
   * @covers ::verify
   */
  public function testVerifyDecryptsStoredCprBeforeMatch(): void {
    $this->sessionManager->method('getCprFromSession')->willReturn('0101001234');
    $this->assertEquals(
      ParentCprVerifier::RESULT_MATCH,
      // Stored value is encrypted; without reveal() this would never match.
      $this->verifier->verify($this->submission(1, 'AFENC1:0101001234'), 1, 'wf-enc'),
    );
  }

  /**
   * Builds a webform submission mock returning the given parent_<N>_cpr.
   */
  protected function submission(int $parent_number, ?string $cpr, int $sid = 42, string $uuid = 'sub-uuid-x'): WebformSubmissionInterface {
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getElementData')
      ->willReturnCallback(function (string $field) use ($parent_number, $cpr) {
        return $field === "parent{$parent_number}_cpr" ? $cpr : NULL;
      });
    $submission->method('id')->willReturn($sid);
    $submission->method('uuid')->willReturn($uuid);
    return $submission;
  }

  /**
   * @covers ::verify
   */
  public function testVerifyReturnsMatchOnEqualDigits(): void {
    $this->sessionManager->method('getCprFromSession')
      ->with('wf-1')
      ->willReturn('0101001234');

    $this->logger->expects($this->once())->method('info');
    $this->auditLogger->expects($this->once())
      ->method('logCprLookup')
      ->with('0101001234', 'parent_approval_cpr_match', 'success', $this->anything());

    $this->assertSame(
      ParentCprVerifier::RESULT_MATCH,
      $this->verifier->verify($this->submission(1, '0101001234'), 1, 'wf-1'),
    );
  }

  /**
   * Hyphenated input on one side and digits-only on the other still match.
   *
   * @covers ::verify
   * @covers ::normaliseCpr
   */
  public function testVerifyReturnsMatchAfterNormalisation(): void {
    $this->sessionManager->method('getCprFromSession')->willReturn('010100-1234');

    $this->auditLogger->expects($this->once())
      ->method('logCprLookup')
      ->with('0101001234', 'parent_approval_cpr_match', 'success', $this->anything());

    $this->assertSame(
      ParentCprVerifier::RESULT_MATCH,
      $this->verifier->verify($this->submission(2, '0101001234'), 2, 'wf-2'),
    );
  }

  /**
   * Mismatch: both CPRs present but different - audit log records hashes only.
   *
   * @covers ::verify
   */
  public function testVerifyReturnsMismatchAndLogsHashedAudit(): void {
    $this->sessionManager->method('getCprFromSession')->willReturn('1212129999');

    $captured_context = NULL;
    $this->auditLogger->expects($this->once())
      ->method('logCprLookup')
      ->willReturnCallback(
        function ($cpr, $purpose, $status, $context) use (&$captured_context) {
          $captured_context = ['cpr' => $cpr, 'purpose' => $purpose, 'status' => $status, 'context' => $context];
        }
      );
    $this->logger->expects($this->once())->method('warning');

    $this->assertSame(
      ParentCprVerifier::RESULT_MISMATCH,
      $this->verifier->verify($this->submission(1, '0101001234'), 1, 'wf-3'),
    );

    $this->assertSame('parent_approval_cpr_mismatch', $captured_context['purpose']);
    $this->assertSame('failure', $captured_context['status']);
    // Hashed values land in the context; raw CPRs must NEVER appear there.
    $this->assertSame(hash('sha256', '0101001234'), $captured_context['context']['expected_hash']);
    $this->assertSame(hash('sha256', '1212129999'), $captured_context['context']['asserted_hash']);
    $this->assertArrayNotHasKey('expected', $captured_context['context']);
    $this->assertArrayNotHasKey('asserted', $captured_context['context']);
  }

  /**
   * MitID session has no CPR claim - upstream IdP failure.
   *
   * @covers ::verify
   */
  public function testVerifyReturnsMissingMitidCprWhenSessionEmpty(): void {
    $this->sessionManager->method('getCprFromSession')->willReturn(NULL);

    $this->logger->expects($this->once())->method('warning');
    $this->auditLogger->expects($this->once())
      ->method('logWorkflowAccess')
      ->with('wf-4', 'parent_approval_cpr_missing', 'failure', $this->anything());

    $this->assertSame(
      ParentCprVerifier::RESULT_MISSING_MITID_CPR,
      $this->verifier->verify($this->submission(1, '0101001234'), 1, 'wf-4'),
    );
  }

  /**
   * Submission lacks parent_<N>_cpr - configuration error.
   *
   * @covers ::verify
   */
  public function testVerifyReturnsMissingExpectedCprWhenSubmissionLacksField(): void {
    $this->sessionManager->method('getCprFromSession')->willReturn('0101001234');

    $this->logger->expects($this->once())->method('error');
    $this->auditLogger->expects($this->once())
      ->method('logCprLookup')
      ->with('0101001234', 'parent_approval_cpr_missing_expected', 'failure', $this->anything());

    // submission(1, NULL) returns NULL for parent1_cpr.
    $this->assertSame(
      ParentCprVerifier::RESULT_MISSING_EXPECTED_CPR,
      $this->verifier->verify($this->submission(1, NULL), 1, 'wf-5'),
    );
  }

  /**
   * Empty asserted CPR routes to missing-MitID, not to mismatch.
   *
   * An empty session value is treated as "no claim" rather than a competing
   * claim that disagrees with the parent CPR, matching the controller's
   * documented UX path.
   *
   * @covers ::verify
   */
  public function testVerifyTreatsEmptyAssertedAsMissing(): void {
    $this->sessionManager->method('getCprFromSession')->willReturn('');

    $this->auditLogger->expects($this->once())
      ->method('logWorkflowAccess')
      ->with($this->anything(), 'parent_approval_cpr_missing', $this->anything(), $this->anything());

    $this->assertSame(
      ParentCprVerifier::RESULT_MISSING_MITID_CPR,
      $this->verifier->verify($this->submission(1, '0101001234'), 1, 'wf-6'),
    );
  }

  /**
   * @covers ::normaliseCpr
   */
  public function testNormaliseCprStripsHyphens(): void {
    $this->assertSame('0101001234', $this->verifier->normaliseCpr('010100-1234'));
  }

  /**
   * @covers ::normaliseCpr
   */
  public function testNormaliseCprStripsWhitespace(): void {
    $this->assertSame('0101001234', $this->verifier->normaliseCpr(' 0101 00 1234 '));
  }

  /**
   * Leading zeros are preserved - CPRs starting with '0101' are valid.
   *
   * @covers ::normaliseCpr
   */
  public function testNormaliseCprPreservesLeadingZeros(): void {
    $cpr = '0101501234';
    $normalised = $this->verifier->normaliseCpr($cpr);
    $this->assertSame($cpr, $normalised);
    $this->assertSame('0', $normalised[0]);
  }

  /**
   * @covers ::normaliseCpr
   */
  public function testNormaliseCprReturnsEmptyForOnlyNonDigits(): void {
    $this->assertSame('', $this->verifier->normaliseCpr('---'));
    $this->assertSame('', $this->verifier->normaliseCpr(''));
  }

  /**
   * Result constants are stable strings; the controller switches on them.
   */
  public function testResultConstantsAreStable(): void {
    $this->assertSame('match', ParentCprVerifier::RESULT_MATCH);
    $this->assertSame('mismatch', ParentCprVerifier::RESULT_MISMATCH);
    $this->assertSame('missing_mitid_cpr', ParentCprVerifier::RESULT_MISSING_MITID_CPR);
    $this->assertSame('missing_expected_cpr', ParentCprVerifier::RESULT_MISSING_EXPECTED_CPR);
  }

  /**
   * Builds a submission mock for a custody-gated form with a child CPR.
   */
  protected function custodySubmission(string $webformId, ?string $childCpr, string $parentCpr = '0101001234'): WebformSubmissionInterface {
    $webform = $this->createMock(WebformInterface::class);
    $webform->method('id')->willReturn($webformId);
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getElementData')
      ->willReturnCallback(static function (string $field) use ($childCpr, $parentCpr) {
        return match ($field) {
          'parent1_cpr' => $parentCpr,
          'child_cpr' => $childCpr,
          default => NULL,
        };
      });
    $submission->method('getWebform')->willReturn($webform);
    $submission->method('id')->willReturn(77);
    $submission->method('uuid')->willReturn('sub-uuid-custody');
    return $submission;
  }

  /**
   * A gated form blocks a matching approver without registered custody.
   *
   * @covers ::verify
   */
  public function testCustodyGateBlocksNonCustodyHolder(): void {
    $this->custodyGatedForms = ['school_transfer'];
    $this->sessionManager->method('getCprFromSession')->willReturn('0101001234');
    $this->familyLookup->method('hasCustody')
      ->with('0101001234', '0109182345')
      ->willReturn(FALSE);

    $this->assertSame(
      ParentCprVerifier::RESULT_NO_CUSTODY,
      $this->verifier->verify($this->custodySubmission('school_transfer', '0109182345'), 1, 'wf-custody'),
    );
  }

  /**
   * A gated form passes a registered custody holder through to MATCH.
   *
   * @covers ::verify
   */
  public function testCustodyGatePassesCustodyHolder(): void {
    $this->custodyGatedForms = ['school_transfer'];
    $this->sessionManager->method('getCprFromSession')->willReturn('0101001234');
    $this->familyLookup->method('hasCustody')->willReturn(TRUE);

    $this->assertSame(
      ParentCprVerifier::RESULT_MATCH,
      $this->verifier->verify($this->custodySubmission('school_transfer', '0109182345'), 1, 'wf-custody'),
    );
  }

  /**
   * Non-gated forms never consult the custody registry.
   *
   * @covers ::verify
   */
  public function testNonGatedFormSkipsCustodyCheck(): void {
    $this->custodyGatedForms = ['some_other_form'];
    $this->sessionManager->method('getCprFromSession')->willReturn('0101001234');
    $this->familyLookup->expects($this->never())->method('hasCustody');

    $this->assertSame(
      ParentCprVerifier::RESULT_MATCH,
      $this->verifier->verify($this->custodySubmission('school_transfer', '0109182345'), 1, 'wf-custody'),
    );
  }

  /**
   * A gated form without a child CPR fails closed (no silent skip).
   *
   * @covers ::verify
   */
  public function testGatedFormWithoutChildCprFailsClosed(): void {
    $this->custodyGatedForms = ['school_transfer'];
    $this->sessionManager->method('getCprFromSession')->willReturn('0101001234');
    $this->familyLookup->expects($this->never())->method('hasCustody');

    $this->assertSame(
      ParentCprVerifier::RESULT_NO_CUSTODY,
      $this->verifier->verify($this->custodySubmission('school_transfer', NULL), 1, 'wf-custody'),
    );
    // A digit-less child CPR is equally unusable and must also fail closed.
    $this->assertSame(
      ParentCprVerifier::RESULT_NO_CUSTODY,
      $this->verifier->verify($this->custodySubmission('school_transfer', 'N/A'), 1, 'wf-custody'),
    );
  }

}
