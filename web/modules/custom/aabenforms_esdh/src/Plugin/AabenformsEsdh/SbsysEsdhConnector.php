<?php

declare(strict_types=1);

namespace Drupal\aabenforms_esdh\Plugin\AabenformsEsdh;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_esdh\Attribute\EsdhConnector;
use Drupal\aabenforms_esdh\EsdhConnectorBase;
use Drupal\aabenforms_esdh\Model\EsdhResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Live SBSYS connector (community-owned ESDH, ~45 kommuner) via SBSIP REST.
 *
 * Transport: SBSIP (SBSYS IntegrationsPlatform) REST with an OAuth2
 * client-credentials token. The sequence, per SBSIP: obtain a bearer token,
 * idempotency-search by a stable external key (never create twice), then create
 * the case FROM A CASE TEMPLATE (SBSIP cannot create a case without a template
 * id, so per-case-type template ids live in config). Documents/parts are a
 * separable follow-on; this minimal path journalises the case itself.
 *
 * Contract (like every connector): NEVER throws - all failure modes are an
 * EsdhResult. A 5xx/timeout is transient (the caller retries and does not close
 * the case); a 4xx/validation is permanent. Idempotency + the transient flag
 * mean a re-fired flow is safe.
 *
 * Live only when configured (base URL + OAuth2 creds + template ids); otherwise
 * it returns a permanent rejection, so the demo/default path is unaffected.
 */
#[EsdhConnector(
  id: 'sbsys',
  label: new TranslatableMarkup('SBSYS (via SBSIP REST)'),
)]
final class SbsysEsdhConnector extends EsdhConnectorBase implements ContainerFactoryPluginInterface {

  /**
   * The ESDH system id recorded on the case.
   */
  private const SYSTEM = 'sbsys';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ClientInterface $httpClient,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    /** @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory */
    $loggerFactory = $container->get('logger.factory');
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('key.repository'),
      $container->get('config.factory'),
      $loggerFactory->get('aabenforms_esdh'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function journaliseCase(AabenformsCase $case, array $documents = []): EsdhResult {
    $config = $this->configFactory->get('aabenforms_esdh.settings');
    $baseUrl = rtrim((string) $config->get('sbsys_base_url'), '/');
    $clientId = (string) $config->get('sbsys_client_id');
    $secret = $this->readSecret((string) $config->get('sbsys_client_secret_key'));

    if ($baseUrl === '' || $clientId === '' || $secret === '') {
      return EsdhResult::rejected(self::SYSTEM, 'SBSYS is not configured (needs SBSIP base URL, client id and secret).', FALSE);
    }

    $summary = $this->caseSummary($case);
    $templateId = $this->templateFor($config, $summary['case_type']);
    if ($templateId === '') {
      return EsdhResult::rejected(self::SYSTEM, sprintf('No SBSYS case template configured for case type "%s".', $summary['case_type']), FALSE);
    }

    // A stable, PII-free external key so a re-run finds the existing case.
    $externalKey = 'aabenforms:' . $case->uuid();

    try {
      $token = $this->fetchToken($baseUrl, $config, $clientId, $secret);

      $existing = $this->searchExisting($baseUrl, $token, $externalKey);
      if ($existing !== NULL && $existing !== '') {
        return EsdhResult::journalised(self::SYSTEM, $existing, TRUE);
      }

      $reference = $this->createCase($baseUrl, $token, $templateId, $externalKey, $summary);
      if ($reference === '') {
        return EsdhResult::rejected(self::SYSTEM, 'SBSYS created a case but returned no reference.', TRUE);
      }
      return EsdhResult::journalised(self::SYSTEM, $reference, FALSE);
    }
    catch (RequestException $e) {
      $code = $e->getResponse()?->getStatusCode() ?? 0;
      // 5xx / no-response (timeout, connection) are retry-able; 4xx are not.
      $transient = $code === 0 || $code >= 500;
      $this->logger->warning('SBSYS journalise failed for case @id: HTTP @code @msg', [
        '@id' => $case->id(),
        '@code' => $code,
        '@msg' => $e->getMessage(),
      ]);
      return EsdhResult::rejected(
        self::SYSTEM,
        sprintf('SBSYS %s (HTTP %d).', $transient ? 'transport error' : 'rejected the request', $code),
        $transient,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('SBSYS journalise error for case @id: @msg', [
        '@id' => $case->id(),
        '@msg' => $e->getMessage(),
      ]);
      // Unknown errors are treated as transient so a flow never closes on them.
      return EsdhResult::rejected(self::SYSTEM, 'SBSYS error: ' . $e->getMessage(), TRUE);
    }
  }

  /**
   * Obtains an OAuth2 client-credentials bearer token.
   */
  private function fetchToken(string $baseUrl, $config, string $clientId, string $secret): string {
    $tokenUrl = (string) ($config->get('sbsys_token_url') ?: $baseUrl . '/api/token');
    $response = $this->httpClient->request('POST', $tokenUrl, [
      'form_params' => [
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $secret,
      ],
      'timeout' => 30,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE);
    $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
    if ($token === '') {
      throw new \RuntimeException('SBSYS token response carried no access_token.');
    }
    return $token;
  }

  /**
   * Searches for an already-journalised case by its external key.
   *
   * @return string|null
   *   The existing SBSYS reference, or NULL when none exists.
   */
  private function searchExisting(string $baseUrl, string $token, string $externalKey): ?string {
    $response = $this->httpClient->request('GET', $baseUrl . '/api/sag/search', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
      'query' => ['externalKey' => $externalKey],
      'timeout' => 30,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE);
    $results = is_array($data) ? ($data['results'] ?? $data) : [];
    if (is_array($results) && $results !== []) {
      $first = reset($results);
      $ref = is_array($first) ? ($first['sagsnummer'] ?? $first['reference'] ?? '') : '';
      return $ref !== '' ? (string) $ref : NULL;
    }
    return NULL;
  }

  /**
   * Creates a case from the configured template and returns its reference.
   */
  private function createCase(string $baseUrl, string $token, string $templateId, string $externalKey, array $summary): string {
    $response = $this->httpClient->request('POST', $baseUrl . '/api/v10/sag/template', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
      'json' => [
        'templateId' => $templateId,
        'externalKey' => $externalKey,
        'titel' => $summary['title'],
        'kleEmne' => $summary['kle_emne'],
        'handlekommune' => $summary['handlekommune'],
      ],
      'timeout' => 30,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      return '';
    }
    return (string) ($data['sagsnummer'] ?? $data['reference'] ?? $data['id'] ?? '');
  }

  /**
   * Resolves the SBSYS template id for a case type from config.
   */
  private function templateFor($config, string $caseType): string {
    $templates = $config->get('sbsys_templates');
    if (is_array($templates) && isset($templates[$caseType])) {
      return (string) $templates[$caseType];
    }
    return '';
  }

  /**
   * Reads the client secret from its key entry, tolerating an unset key.
   */
  private function readSecret(string $keyName): string {
    if ($keyName === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($keyName);
    return $key ? (string) $key->getKeyValue() : '';
  }

}
