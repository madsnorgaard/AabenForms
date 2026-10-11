<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Privacy;

use Drupal\aabenforms_core\Privacy\PiiRedactor;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the PII redactor used at every persistence boundary (#74).
 *
 * @coversDefaultClass \Drupal\aabenforms_core\Privacy\PiiRedactor
 * @group aabenforms_core
 */
class PiiRedactorTest extends UnitTestCase {

  /**
   * The redactor under test.
   */
  protected PiiRedactor $redactor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->redactor = new PiiRedactor();
  }

  /**
   * CPR numbers are masked in both notations, inside free text.
   *
   * @covers ::redactString
   * @dataProvider cprProvider
   */
  public function testCprIsMasked(string $input, string $expected): void {
    $this->assertSame($expected, $this->redactor->redactString($input));
  }

  /**
   * Data provider for testCprIsMasked().
   */
  public static function cprProvider(): array {
    return [
      'plain' => ['0101011234', 'DDMMYY-XXXX'],
      'hyphenated' => ['010101-1234', 'DDMMYY-XXXX'],
      'in text' => ['Opslag for 2512489996 fejlede', 'Opslag for DDMMYY-XXXX fejlede'],
      'replacement number day 61-91' => ['6101011234', 'DDMMYY-XXXX'],
      'two in one string' => ['0101011234 og 311299-4321', 'DDMMYY-XXXX og DDMMYY-XXXX'],
      'json value' => ['{"cpr":"0101011234"}', '{"cpr":"DDMMYY-XXXX"}'],
    ];
  }

  /**
   * Numbers that cannot be a CPR are left alone.
   *
   * @covers ::redactString
   * @dataProvider nonCprProvider
   */
  public function testNonCprIsKept(string $input): void {
    $this->assertSame($input, $this->redactor->redactString($input));
  }

  /**
   * Data provider for testNonCprIsKept().
   */
  public static function nonCprProvider(): array {
    return [
      'case id' => ['Sag #42 journaliseret.'],
      'phone' => ['+45 12345678'],
      'unix timestamp (month 60)' => ['1760123456'],
      'eleven digits' => ['01010112345'],
      'month 13' => ['0113011234'],
      'cvr' => ['CVR 12345678'],
    ];
  }

  /**
   * Values under secret keys are replaced whatever their type.
   *
   * @covers ::redactArray
   * @covers ::isSensitiveKey
   */
  public function testSensitiveKeysAreRedacted(): void {
    $input = [
      'id_token' => 'eyJhbGciOi.payload.sig',
      'access_token' => 'abc',
      'refresh_token' => 'def',
      'Password' => 'hunter2',
      'secret' => ['nested' => 'x'],
      'code_verifier' => 'v',
      'workflow_id' => 'wf_abcdefghijklmnopqrstuvwxyz',
      'session_id' => 'sess',
      'ip' => '203.0.113.9',
      'cpr_token' => 'cpr',
      'name' => 'Freja',
    ];
    $out = $this->redactor->redactArray($input);
    $secret_keys = [
      'id_token',
      'access_token',
      'refresh_token',
      'Password',
      'secret',
      'code_verifier',
      'workflow_id',
      'session_id',
      'ip',
    ];
    foreach ($secret_keys as $key) {
      $this->assertSame(PiiRedactor::REDACTED, $out[$key], $key);
    }
    // A *_token key in ECA config holds a token NAME, not a secret.
    $this->assertSame('cpr', $out['cpr_token']);
    $this->assertSame('Freja', $out['name']);
  }

  /**
   * Nested data is walked and CPR numbers masked at any depth.
   *
   * @covers ::redact
   */
  public function testNestedRedaction(): void {
    $object = new \stdClass();
    $object->data = ['person' => ['cpr' => '0101011234']];
    $object->password = 'x';
    $out = $this->redactor->redact(['a' => [['b' => $object]], 'n' => 1760123456]);
    $this->assertSame('DDMMYY-XXXX', $out['a'][0]['b']->data['person']['cpr']);
    $this->assertSame(PiiRedactor::REDACTED, $out['a'][0]['b']->password);
    $this->assertSame(1760123456, $out['n']);
    // The input object is not mutated.
    $this->assertSame('0101011234', $object->data['person']['cpr']);
  }

  /**
   * ECA token browser entries are judged by their token path.
   *
   * @covers ::redactArray
   */
  public function testEcaTokenEntries(): void {
    $secret = $this->redactor->redactArray([
      'label' => 'Access Token',
      'token' => 'mitid:access_token',
      'value' => 'eyJraWQ',
    ]);
    $this->assertSame('mitid:access_token', $secret['token']);
    $this->assertSame(PiiRedactor::REDACTED, $secret['value']);

    $plain = $this->redactor->redactArray([
      'label' => 'Cpr',
      'token' => 'entity:values:cpr',
      'value' => '010101-1234',
    ]);
    $this->assertSame('entity:values:cpr', $plain['token']);
    $this->assertSame('DDMMYY-XXXX', $plain['value']);
  }

  /**
   * Session handles and URL secrets are masked inside free text.
   *
   * @covers ::redactString
   */
  public function testCapabilitiesInText(): void {
    $this->assertSame(
      '/api/prefill/klage?session=[redacted]&x=1',
      $this->redactor->redactString('/api/prefill/klage?session=wf_abc123&x=1'),
    );
    $this->assertSame(
      '/mitid/callback?code=[redacted]&state=[redacted]',
      $this->redactor->redactString('/mitid/callback?code=c0de&state=st4te'),
    );
    $this->assertSame(
      'Session wf_[redacted] seeded',
      $this->redactor->redactString('Session wf_AbCdEf0123456789xyzXYZ seeded'),
    );
  }

}
