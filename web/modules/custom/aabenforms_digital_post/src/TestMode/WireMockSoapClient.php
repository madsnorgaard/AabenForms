<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\TestMode;

use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Result;
use Drupal\aabenforms_digital_post\Memo\MemoBuilder;
use Drupal\aabenforms_digital_post\Service\Sf1601ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Posts a real MeMo XML Digital Post to a WireMock endpoint for integration.
 *
 * Builds the actual SF1601 kombi_request MeMo payload via MemoBuilder and
 * POSTs it as application/xml to the KombiPostAfsend endpoint - the same XML a
 * live send would post, minus the certificate/SOAP transport (which lives in
 * SF1601::kombiPostAfsend and is cert-gated). The WireMock stub matches the
 * MeMo body via matchesXPath and returns a templated receipt, giving a
 * cert-free end-to-end integration test of real message construction.
 *
 * WireMock URL defaults to http://wiremock:8080 which matches the DDEV
 * container alias. Set aabenforms_digital_post.settings.wiremock_url to
 * point elsewhere when running outside DDEV.
 */
final class WireMockSoapClient implements Sf1601ClientInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly MemoBuilder $memoBuilder,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function send(DigitalPost $post, string $transactionId): Result {
    $base = (string) $this->configFactory
      ->get('aabenforms_digital_post.settings')
      ->get('wiremock_url');
    if ($base === '') {
      return Result::failure(
        transactionId: $transactionId,
        reasonCode: Result::REASON_VALIDATION,
        message: 'aabenforms_digital_post.settings:wiremock_url is empty.',
      );
    }
    $url = rtrim($base, '/') . '/service/KombiPostAfsend_1/kombi';
    try {
      $xml = $this->buildXml($post);
    }
    catch (\Throwable $e) {
      $this->logger->error('Digital Post MeMo build failed: @msg', ['@msg' => $e->getMessage()]);
      return Result::failure(
        transactionId: $transactionId,
        reasonCode: Result::REASON_VALIDATION,
        message: 'MeMo build failed: ' . $e->getMessage(),
      );
    }
    $headers = [
      'Content-Type' => 'application/xml',
      'Accept' => 'application/xml',
      'X-Transaction-Id' => $transactionId,
      'X-SF1601-Type' => $post->type,
    ];
    // The size-rejection test scenario is keyed off a header now (the old JSON
    // meta.force_too_large matcher can't match an XML body).
    if (!empty($post->meta['force_too_large'])) {
      $headers['X-Force-Too-Large'] = '1';
    }
    try {
      $response = $this->httpClient->request('POST', $url, [
        'body' => $xml,
        'headers' => $headers,
        'http_errors' => FALSE,
        'timeout' => 10,
      ]);
      $status = $response->getStatusCode();
      $raw = (string) $response->getBody();
      if ($status >= 200 && $status < 300) {
        $this->logger->info('Digital Post WireMock send: tx=@tx status=@s', ['@tx' => $transactionId, '@s' => $status]);
        return Result::success(
          transactionId: $transactionId,
          message: sprintf('wiremock: HTTP %d', $status),
          rawResponse: $raw,
        );
      }
      return Result::failure(
        transactionId: $transactionId,
        reasonCode: $status >= 500 ? Result::REASON_TRANSPORT : Result::REASON_VALIDATION,
        message: sprintf('wiremock returned HTTP %d', $status),
        rawResponse: $raw,
      );
    }
    catch (GuzzleException $e) {
      $this->logger->error('Digital Post WireMock transport error: @msg', ['@msg' => $e->getMessage()]);
      return Result::failure(
        transactionId: $transactionId,
        reasonCode: Result::REASON_TRANSPORT,
        message: 'wiremock transport error: ' . $e->getMessage(),
      );
    }
  }

  /**
   * Builds the MeMo kombi_request XML, silencing vendor load-time deprecations.
   */
  private function buildXml(DigitalPost $post): string {
    $previous = error_reporting();
    error_reporting($previous & ~E_DEPRECATED);
    try {
      return $this->memoBuilder->buildKombiRequestXml($post);
    }
    finally {
      error_reporting($previous);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function modeLabel(): string {
    return 'wiremock';
  }

}
