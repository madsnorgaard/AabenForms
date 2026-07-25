<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_esdh\Unit;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_esdh\Model\EsdhResult;
use Drupal\aabenforms_esdh\Plugin\AabenformsEsdh\SbsysEsdhConnector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

/**
 * Tests the live SBSYS connector's SBSIP sequence and result mapping.
 *
 * The HTTP transport is a Guzzle MockHandler (no live SBSIP), so the test
 * exercises the connector's own logic: not-configured / no-template rejections,
 * the token -> search -> create happy path, idempotency on a search hit, and
 * the transient (5xx) vs permanent (4xx) failure mapping.
 *
 * @coversDefaultClass \Drupal\aabenforms_esdh\Plugin\AabenformsEsdh\SbsysEsdhConnector
 * @group aabenforms_esdh
 */
class SbsysEsdhConnectorTest extends UnitTestCase {

  /**
   * A permanent rejection when SBSYS is unconfigured.
   *
   * @covers ::journaliseCase
   */
  public function testNotConfiguredIsPermanentRejection(): void {
    $connector = $this->connector([new Response(200, [], '{}')], configured: FALSE);
    $result = $connector->journaliseCase($this->case());
    $this->assertSame(EsdhResult::STATUS_REJECTED, $result->status);
    $this->assertFalse($result->transient);
  }

  /**
   * A permanent rejection when no template is configured for the case type.
   *
   * @covers ::journaliseCase
   */
  public function testMissingTemplateIsPermanentRejection(): void {
    $connector = $this->connector([], templates: ['other_type' => 'T1']);
    $result = $connector->journaliseCase($this->case());
    $this->assertSame(EsdhResult::STATUS_REJECTED, $result->status);
    $this->assertFalse($result->transient);
  }

  /**
   * Token -> search(miss) -> create yields a journalised (created) result.
   *
   * @covers ::journaliseCase
   */
  public function testHappyPathCreatesCase(): void {
    $connector = $this->connector([
      new Response(200, [], json_encode(['access_token' => 'tok'])),
      new Response(200, [], json_encode(['results' => []])),
      new Response(201, [], json_encode(['sagsnummer' => 'SAG-2026-42'])),
    ]);
    $result = $connector->journaliseCase($this->case());
    $this->assertTrue($result->isJournalised());
    $this->assertSame(EsdhResult::STATUS_CREATED, $result->status);
    $this->assertSame('SAG-2026-42', $result->reference);
    $this->assertSame('sbsys', $result->esdhSystem);
  }

  /**
   * A search hit short-circuits to a journalised (exists) result - idempotent.
   *
   * @covers ::journaliseCase
   */
  public function testIdempotentSearchHit(): void {
    $connector = $this->connector([
      new Response(200, [], json_encode(['access_token' => 'tok'])),
      new Response(200, [], json_encode(['results' => [['sagsnummer' => 'SAG-EXISTS']]])),
    ]);
    $result = $connector->journaliseCase($this->case());
    $this->assertSame(EsdhResult::STATUS_EXISTS, $result->status);
    $this->assertSame('SAG-EXISTS', $result->reference);
  }

  /**
   * A 5xx is a transient rejection (the caller retries, never closes the case).
   *
   * @covers ::journaliseCase
   */
  public function testServerErrorIsTransient(): void {
    $connector = $this->connector([
      new Response(200, [], json_encode(['access_token' => 'tok'])),
      new Response(503, [], 'busy'),
    ]);
    $result = $connector->journaliseCase($this->case());
    $this->assertSame(EsdhResult::STATUS_REJECTED, $result->status);
    $this->assertTrue($result->transient);
  }

  /**
   * A 4xx is a permanent rejection.
   *
   * @covers ::journaliseCase
   */
  public function testClientErrorIsPermanent(): void {
    $connector = $this->connector([
      new Response(200, [], json_encode(['access_token' => 'tok'])),
      new Response(400, [], 'bad request'),
    ]);
    $result = $connector->journaliseCase($this->case());
    $this->assertSame(EsdhResult::STATUS_REJECTED, $result->status);
    $this->assertFalse($result->transient);
  }

  /**
   * Builds a connector whose HTTP transport replays the queued responses.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   The queued responses.
   * @param bool $configured
   *   Whether base URL / client id / secret are set.
   * @param array $templates
   *   The per-case-type template map (defaults to one for 'merudgifter').
   */
  private function connector(array $responses, bool $configured = TRUE, array $templates = ['merudgifter' => 'TPL-1']): SbsysEsdhConnector {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['sbsys_base_url', $configured ? 'https://sbsysapi.example.dk' : ''],
      ['sbsys_token_url', ''],
      ['sbsys_client_id', $configured ? 'client-1' : ''],
      ['sbsys_client_secret_key', $configured ? 'sbsys_secret' : ''],
      ['sbsys_templates', $templates],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('aabenforms_esdh.settings')->willReturn($config);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('s3cr3t');
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturn($key);

    return new SbsysEsdhConnector(
      [],
      'sbsys',
      ['label' => 'SBSYS'],
      $client,
      $keyRepository,
      $configFactory,
      new NullLogger(),
    );
  }

  /**
   * A mocked case exposing the fields caseSummary() reads.
   */
  private function case(): AabenformsCase {
    $case = $this->createMock(AabenformsCase::class);
    $case->method('getCaseType')->willReturn('merudgifter');
    $case->method('uuid')->willReturn('11111111-2222-3333-4444-555555555555');
    $case->method('id')->willReturn('1');
    $case->method('get')->willReturnCallback(function (string $field) {
      $values = [
        'title' => 'Merudgifter SEL 41',
        'kle_emne' => '32.18.04',
        'handlekommune' => '0751',
        'journal_ref' => '',
      ];
      return (object) ['value' => $values[$field] ?? ''];
    });
    return $case;
  }

}
