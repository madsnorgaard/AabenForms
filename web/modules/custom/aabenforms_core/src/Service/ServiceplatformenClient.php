<?php

namespace Drupal\aabenforms_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\aabenforms_core\Exception\ServiceplatformenException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * SOAP client for Danish government Serviceplatformen SF1500 services.
 *
 * Modernized from itk-dev/serviceplatformen with improvements:
 * - Retry logic for transient failures (connection errors, 408/503/504)
 * - Circuit breaker and short connect timeout, so an upstream outage fails
 *   fast instead of holding PHP-FPM workers (#72)
 * - Structured exception handling (ServiceplatformenException)
 * - Drupal logger integration
 * - Configurable per-environment service URLs
 * - Response validation and error extraction.
 *
 * Supports:
 * - SF1520: CPR person lookup
 * - SF1530: CVR company lookup
 * - SF1601: Digital Post (NgDP)
 * - SF6006: Familie+ CPR lookup (family relations and custody)
 */
class ServiceplatformenClient {

  /**
   * Maximum attempts per request, the first one included.
   */
  protected const MAX_RETRIES = 2;

  /**
   * Retry delay in seconds (exponential backoff).
   */
  protected const RETRY_DELAY = 1;

  /**
   * Seconds to establish the connection.
   *
   * A healthy endpoint connects well within this; a dead one must not hold a
   * worker for long.
   */
  protected const CONNECT_TIMEOUT = 3;

  /**
   * Seconds for the whole request, connection included.
   */
  protected const TIMEOUT = 15;

  /**
   * Read-only lookups, safe to send again.
   *
   * SF1601 sends a letter: a retry after a timeout could deliver it twice, so
   * it is never retried here.
   */
  protected const IDEMPOTENT_SERVICES = ['SF1520', 'SF1530', 'SF6006'];

  /**
   * Default cache expiration (15 minutes).
   */
  protected const DEFAULT_CACHE_EXPIRATION = 900;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The circuit breaker, NULL only where a test builds the client by hand.
   *
   * @var \Drupal\aabenforms_core\Service\ServiceplatformenCircuitBreaker|null
   */
  protected ?ServiceplatformenCircuitBreaker $circuitBreaker;

  /**
   * Constructs a ServiceplatformenClient.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\aabenforms_core\Service\ServiceplatformenCircuitBreaker|null $circuit_breaker
   *   The circuit breaker.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
    ?ServiceplatformenCircuitBreaker $circuit_breaker = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('aabenforms_core');
    $this->httpClient = $http_client;
    $this->circuitBreaker = $circuit_breaker;
  }

  /**
   * Executes a SOAP request to Serviceplatformen.
   *
   * @param string $service
   *   The service name (e.g., 'SF1520', 'SF1530', 'SF1601').
   * @param string $operation
   *   The operation name (e.g., 'PersonLookup', 'CompanyLookup').
   * @param array $params
   *   The request parameters.
   * @param array $options
   *   Additional options:
   *   - no_cache (bool): Skip cache and force fresh request.
   *   - cache_expiration (int): Cache lifetime in seconds.
   *
   * @return array
   *   The parsed SOAP response.
   *
   * @throws \Drupal\aabenforms_core\Exception\ServiceplatformenException
   *   If the request fails after all retries.
   */
  public function request(string $service, string $operation, array $params, array $options = []): array {
    $noCache = $options['no_cache'] ?? FALSE;
    $cacheExpiration = $options['cache_expiration'] ?? self::DEFAULT_CACHE_EXPIRATION;

    // Try cache first (unless disabled).
    if (!$noCache) {
      $cacheKey = $this->getCacheKey($service, $operation, $params);
      $cached = $this->cache->get($cacheKey);

      if ($cached && $cached->data) {
        $this->logger->debug('Serviceplatformen cache hit: {service}::{operation}', [
          'service' => $service,
          'operation' => $operation,
        ]);
        return $cached->data;
      }
    }

    // Execute request with retry logic.
    $response = $this->executeWithRetry($service, $operation, $params);

    // Cache successful response.
    if (!$noCache) {
      $this->cache->set(
        $cacheKey,
        $response,
        $this->calculateCacheExpiration($cacheExpiration)
      );
    }

    return $response;
  }

  /**
   * Executes SOAP request with automatic retry on transient failures.
   *
   * @param string $service
   *   The service name.
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   The request parameters.
   * @param int $attempt
   *   Current attempt number (internal use).
   *
   * @return array
   *   The parsed response.
   *
   * @throws \Drupal\aabenforms_core\Exception\ServiceplatformenException
   *   If all retries fail.
   */
  protected function executeWithRetry(string $service, string $operation, array $params, int $attempt = 1): array {
    if ($this->circuitBreaker && !$this->circuitBreaker->isAvailable($service)) {
      $this->logger->warning('Serviceplatformen circuit open, failing fast: {service}::{operation}', [
        'service' => $service,
        'operation' => $operation,
      ]);
      throw new ServiceplatformenException(
        'Serviceplatformen is temporarily unavailable (circuit open)',
        $service,
        $operation,
        retryable: FALSE,
        code: 503,
      );
    }

    try {
      $response = $this->execute($service, $operation, $params);
      $this->circuitBreaker?->recordSuccess($service);
      return $response;
    }
    catch (ServiceplatformenException $e) {
      // Only availability failures count; a SOAP fault means it answered.
      if ($e->isRetryable() && $this->circuitBreaker?->recordFailure($service)) {
        $this->logger->error('Serviceplatformen circuit opened for {service} after repeated failures', [
          'service' => $service,
        ]);
      }

      // Retry transient errors, but never a timeout (a slow upstream will
      // not be faster a second later) and never a non-idempotent send.
      if ($e->isRetryable()
        && !$this->isTimeout($e)
        && in_array($service, self::IDEMPOTENT_SERVICES, TRUE)
        && $attempt < self::MAX_RETRIES) {
        // Exponential backoff.
        $delay = self::RETRY_DELAY * pow(2, $attempt - 1);

        $this->logger->warning('Serviceplatformen request failed (attempt {attempt}/{max}), retrying in {delay}s: {error}', [
          'service' => $service,
          'operation' => $operation,
          'attempt' => $attempt,
          'max' => self::MAX_RETRIES,
          'delay' => $delay,
          'error' => $e->getMessage(),
        ]);

        sleep($delay);
        return $this->executeWithRetry($service, $operation, $params, $attempt + 1);
      }

      // Non-retryable or max retries exceeded.
      $this->logger->error('Serviceplatformen request failed permanently: {error}', [
        'service' => $service,
        'operation' => $operation,
        'error' => $e->getMessage(),
      ]);

      throw $e;
    }
  }

  /**
   * Executes a single SOAP request.
   *
   * @param string $service
   *   The service name.
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   The request parameters.
   *
   * @return array
   *   The parsed response.
   *
   * @throws \Drupal\aabenforms_core\Exception\ServiceplatformenException
   *   If the request fails.
   */
  protected function execute(string $service, string $operation, array $params): array {
    $url = $this->getServiceUrl($service);
    $soapEnvelope = $this->buildSoapEnvelope($service, $operation, $params);

    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Content-Type' => 'application/soap+xml; charset=utf-8',
          'SOAPAction' => $operation,
        ],
        'body' => $soapEnvelope,
        'timeout' => self::TIMEOUT,
        'connect_timeout' => self::CONNECT_TIMEOUT,
      ]);

      $body = (string) $response->getBody();
      return $this->parseResponse($body);

    }
    catch (ConnectException $e) {
      // Connection timeout - retryable.
      throw new ServiceplatformenException(
        'Connection to Serviceplatformen failed: ' . $e->getMessage(),
        $service,
        $operation,
        retryable: TRUE,
        code: 503,
        previous: $e
      );
    }
    catch (RequestException $e) {
      // HTTP error - check if retryable.
      $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
      // Timeout, service unavailable, gateway timeout.
      $retryable = in_array($statusCode, [408, 503, 504]);

      throw new ServiceplatformenException(
        'Serviceplatformen request failed: ' . $e->getMessage(),
        $service,
        $operation,
        retryable: $retryable,
        code: $statusCode,
        previous: $e
      );
    }
    catch (\Exception $e) {
      // Generic error - not retryable.
      throw new ServiceplatformenException(
        'Unexpected error calling Serviceplatformen: ' . $e->getMessage(),
        $service,
        $operation,
        retryable: FALSE,
        code: 500,
        previous: $e
      );
    }
  }

  /**
   * Whether the failure was a timeout rather than a refused connection.
   *
   * @param \Drupal\aabenforms_core\Exception\ServiceplatformenException $e
   *   The failure.
   *
   * @return bool
   *   TRUE for a cURL timeout or an HTTP 408/504.
   */
  protected function isTimeout(ServiceplatformenException $e): bool {
    if (in_array($e->getCode(), [408, 504], TRUE)) {
      return TRUE;
    }
    $previous = $e->getPrevious();
    if ($previous instanceof ConnectException) {
      // CURLE_OPERATION_TIMEDOUT.
      return ($previous->getHandlerContext()['errno'] ?? NULL) === 28
        || stripos($previous->getMessage(), 'timed out') !== FALSE;
    }
    return FALSE;
  }

  /**
   * Builds SOAP envelope for the request.
   *
   * @param string $service
   *   The service name.
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   The request parameters.
   *
   * @return string
   *   The SOAP XML envelope.
   */
  protected function buildSoapEnvelope(string $service, string $operation, array $params): string {
    return match($service) {
      'SF1520' => $this->buildSf1520Envelope($operation, $params),
      'SF1530' => $this->buildSf1530Envelope($operation, $params),
      'SF1601' => $this->buildSf1601Envelope($operation, $params),
      'SF6006' => $this->buildSf6006Envelope($operation, $params),
      default => throw new \InvalidArgumentException("Unknown service: {$service}"),
    };
  }

  /**
   * Builds SOAP envelope for SF1520 (CPR person lookup).
   *
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   Request parameters.
   *
   * @return string
   *   The SOAP envelope XML.
   */
  protected function buildSf1520Envelope(string $operation, array $params): string {
    $config = $this->configFactory->get('aabenforms_core.settings');
    $username = $config->get('serviceplatformen.username') ?? '';
    $password = $config->get('serviceplatformen.password') ?? '';

    $cpr = $params['cpr'] ?? '';

    // Escape all values to prevent XML injection.
    $username = htmlspecialchars($username, ENT_XML1, 'UTF-8');
    $password = htmlspecialchars($password, ENT_XML1, 'UTF-8');
    $cpr = htmlspecialchars($cpr, ENT_XML1, 'UTF-8');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:ns="http://serviceplatformen.dk/cprlookup/1">
  <soap:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>{$username}</wsse:Username>
        <wsse:Password>{$password}</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soap:Header>
  <soap:Body>
    <ns:PersonLookupRequest>
      <ns:PNR>{$cpr}</ns:PNR>
    </ns:PersonLookupRequest>
  </soap:Body>
</soap:Envelope>
XML;

    return $xml;
  }

  /**
   * Builds SOAP envelope for SF1530 (CVR company lookup).
   *
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   Request parameters.
   *
   * @return string
   *   The SOAP envelope XML.
   */
  protected function buildSf1530Envelope(string $operation, array $params): string {
    $config = $this->configFactory->get('aabenforms_core.settings');
    $username = $config->get('serviceplatformen.username') ?? '';
    $password = $config->get('serviceplatformen.password') ?? '';

    $cvr = $params['cvr'] ?? '';

    // Escape all values to prevent XML injection.
    $username = htmlspecialchars($username, ENT_XML1, 'UTF-8');
    $password = htmlspecialchars($password, ENT_XML1, 'UTF-8');
    $cvr = htmlspecialchars($cvr, ENT_XML1, 'UTF-8');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:ns="http://serviceplatformen.dk/cvrlookup/1">
  <soap:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>{$username}</wsse:Username>
        <wsse:Password>{$password}</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soap:Header>
  <soap:Body>
    <ns:CompanyLookupRequest>
      <ns:CVR>{$cvr}</ns:CVR>
    </ns:CompanyLookupRequest>
  </soap:Body>
</soap:Envelope>
XML;

    return $xml;
  }

  /**
   * Builds SOAP envelope for SF1601 (Digital Post).
   *
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   Request parameters.
   *
   * @return string
   *   The SOAP envelope XML.
   */
  protected function buildSf1601Envelope(string $operation, array $params): string {
    $config = $this->configFactory->get('aabenforms_core.settings');
    $username = $config->get('serviceplatformen.username') ?? '';
    $password = $config->get('serviceplatformen.password') ?? '';

    $cpr = $params['cpr'] ?? '';
    $subject = $params['subject'] ?? '';
    $content = $params['content'] ?? '';
    $messageId = $params['message_id'] ?? uniqid('msg_');

    // Escape all values to prevent XML injection.
    $username = htmlspecialchars($username, ENT_XML1, 'UTF-8');
    $password = htmlspecialchars($password, ENT_XML1, 'UTF-8');
    $messageId = htmlspecialchars($messageId, ENT_XML1, 'UTF-8');
    $cpr = htmlspecialchars($cpr, ENT_XML1, 'UTF-8');
    $subject = htmlspecialchars($subject, ENT_XML1, 'UTF-8');
    // Content is in CDATA but still escape CDATA end sequences.
    $content = str_replace(']]>', ']]]]><![CDATA[>', $content);

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:ns="http://serviceplatformen.dk/digitalpost/1">
  <soap:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>{$username}</wsse:Username>
        <wsse:Password>{$password}</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soap:Header>
  <soap:Body>
    <ns:SendMessageRequest>
      <ns:MessageID>{$messageId}</ns:MessageID>
      <ns:Recipient>
        <ns:CPR>{$cpr}</ns:CPR>
      </ns:Recipient>
      <ns:Subject>{$subject}</ns:Subject>
      <ns:Content><![CDATA[{$content}]]></ns:Content>
    </ns:SendMessageRequest>
  </soap:Body>
</soap:Envelope>
XML;

    return $xml;
  }

  /**
   * Builds SOAP envelope for SF6006 (Familie+ CPR lookup).
   *
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   Request parameters.
   *
   * @return string
   *   The SOAP envelope XML.
   */
  protected function buildSf6006Envelope(string $operation, array $params): string {
    $config = $this->configFactory->get('aabenforms_core.settings');
    $username = $config->get('serviceplatformen.username') ?? '';
    $password = $config->get('serviceplatformen.password') ?? '';

    $cpr = $params['cpr'] ?? '';

    // Escape all values to prevent XML injection.
    $username = htmlspecialchars($username, ENT_XML1, 'UTF-8');
    $password = htmlspecialchars($password, ENT_XML1, 'UTF-8');
    $cpr = htmlspecialchars($cpr, ENT_XML1, 'UTF-8');

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:ns="http://serviceplatformen.dk/familielookup/1">
  <soap:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>{$username}</wsse:Username>
        <wsse:Password>{$password}</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soap:Header>
  <soap:Body>
    <ns:FamilyLookupRequest>
      <ns:PNR>{$cpr}</ns:PNR>
    </ns:FamilyLookupRequest>
  </soap:Body>
</soap:Envelope>
XML;

    return $xml;
  }

  /**
   * Parses SOAP response XML.
   *
   * @param string $xml
   *   The SOAP response XML.
   *
   * @return array
   *   The parsed response data.
   *
   * @throws \Drupal\aabenforms_core\Exception\ServiceplatformenException
   *   If parsing fails or SOAP fault detected.
   */
  protected function parseResponse(string $xml): array {
    try {
      // Enable internal error handling for better diagnostics.
      $use_errors = libxml_use_internal_errors(TRUE);
      libxml_clear_errors();

      $doc = new \DOMDocument();
      // Load XML with security hardening flags (disable network access).
      $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR);

      // Restore previous error handling state.
      libxml_use_internal_errors($use_errors);

      if (!$loaded) {
        $errors = libxml_get_errors();
        $error_messages = array_map(fn($error) => $error->message, $errors);
        libxml_clear_errors();
        throw new \RuntimeException('Failed to parse SOAP XML response: ' . implode(', ', $error_messages));
      }

      // Check for SOAP fault.
      $faults = $doc->getElementsByTagName('Fault');
      if ($faults->length > 0) {
        $faultString = $doc->getElementsByTagName('Reason')->item(0)?->textContent
                    ?? $doc->getElementsByTagName('faultstring')->item(0)?->textContent
                    ?? 'Unknown SOAP fault';
        throw new \RuntimeException('SOAP Fault: ' . $faultString);
      }

      // Parse response body - detect service type from namespace.
      $body = $doc->getElementsByTagName('Body')->item(0);
      if (!$body) {
        throw new \RuntimeException('No SOAP Body found in response');
      }

      // Check for CPR lookup response.
      if ($doc->getElementsByTagName('PersonLookupResponse')->length > 0) {
        return $this->parseSf1520Response($doc);
      }

      // Check for CVR lookup response.
      if ($doc->getElementsByTagName('CompanyLookupResponse')->length > 0) {
        return $this->parseSf1530Response($doc);
      }

      // Check for Digital Post response.
      if ($doc->getElementsByTagName('SendMessageResponse')->length > 0) {
        return $this->parseSf1601Response($doc);
      }

      // Check for family lookup response.
      if ($doc->getElementsByTagName('FamilyLookupResponse')->length > 0) {
        return $this->parseSf6006Response($doc);
      }

      // Unknown response format.
      return ['success' => TRUE, 'data' => []];

    }
    catch (\Exception $e) {
      throw new ServiceplatformenException(
        'Failed to parse Serviceplatformen response: ' . $e->getMessage(),
        'unknown',
        'unknown',
        retryable: FALSE,
        code: 500,
        previous: $e
      );
    }
  }

  /**
   * Parses SF1520 (CPR) response.
   *
   * @param \DOMDocument $doc
   *   The response DOM document.
   *
   * @return array
   *   Parsed person data.
   */
  protected function parseSf1520Response(\DOMDocument $doc): array {
    $data = [
      'success' => TRUE,
      'service' => 'SF1520',
      'person' => [],
    ];

    // Extract person data from response.
    $firstName = $doc->getElementsByTagName('FirstName')->item(0)?->textContent ?? '';
    $lastName = $doc->getElementsByTagName('LastName')->item(0)?->textContent ?? '';
    $address = $doc->getElementsByTagName('Address')->item(0)?->textContent ?? '';
    $postalCode = $doc->getElementsByTagName('PostalCode')->item(0)?->textContent ?? '';
    $city = $doc->getElementsByTagName('City')->item(0)?->textContent ?? '';
    $cpr = $doc->getElementsByTagName('CPR')->item(0)?->textContent ?? '';

    if ($firstName || $lastName) {
      $data['person'] = [
        'cpr' => $cpr,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'full_name' => trim("{$firstName} {$lastName}"),
        'address' => $address,
        'postal_code' => $postalCode,
        'city' => $city,
      ];
    }

    return $data;
  }

  /**
   * Parses SF1530 (CVR) response.
   *
   * @param \DOMDocument $doc
   *   The response DOM document.
   *
   * @return array
   *   Parsed company data.
   */
  protected function parseSf1530Response(\DOMDocument $doc): array {
    $data = [
      'success' => TRUE,
      'service' => 'SF1530',
      'company' => [],
    ];

    // Extract company data from response.
    $cvr = $doc->getElementsByTagName('CVR')->item(0)?->textContent ?? '';
    $name = $doc->getElementsByTagName('CompanyName')->item(0)?->textContent ?? '';
    $address = $doc->getElementsByTagName('Address')->item(0)?->textContent ?? '';
    $postalCode = $doc->getElementsByTagName('PostalCode')->item(0)?->textContent ?? '';
    $city = $doc->getElementsByTagName('City')->item(0)?->textContent ?? '';
    $status = $doc->getElementsByTagName('Status')->item(0)?->textContent ?? '';

    if ($name || $cvr) {
      $data['company'] = [
        'cvr' => $cvr,
        'name' => $name,
        'address' => $address,
        'postal_code' => $postalCode,
        'city' => $city,
        'status' => $status,
      ];
    }

    return $data;
  }

  /**
   * Parses SF1601 (Digital Post) response.
   *
   * @param \DOMDocument $doc
   *   The response DOM document.
   *
   * @return array
   *   Parsed send status.
   */
  protected function parseSf1601Response(\DOMDocument $doc): array {
    $data = [
      'success' => TRUE,
      'service' => 'SF1601',
      'status' => [],
    ];

    // Extract send status from response.
    $messageId = $doc->getElementsByTagName('MessageID')->item(0)?->textContent ?? '';
    $status = $doc->getElementsByTagName('Status')->item(0)?->textContent ?? '';
    $statusCode = $doc->getElementsByTagName('StatusCode')->item(0)?->textContent ?? '';

    $data['status'] = [
      'message_id' => $messageId,
      'status' => $status,
      'status_code' => $statusCode,
      'sent' => ($status === 'OK' || $statusCode === '200'),
    ];

    return $data;
  }

  /**
   * Parses SF6006 (Familie+) response.
   *
   * The response describes the requested person plus their family relations:
   * a Children list (when the person is an adult) and a top-level Guardians
   * list (the person's own custody holders, when the person is a child).
   * Each Child carries its own nested Guardians so custody can be verified
   * per child without follow-up lookups.
   *
   * @param \DOMDocument $doc
   *   The response DOM document.
   *
   * @return array
   *   Parsed family data with keys 'person', 'children' and 'guardians'.
   */
  protected function parseSf6006Response(\DOMDocument $doc): array {
    $data = [
      'success' => TRUE,
      'service' => 'SF6006',
      'person' => [],
      'children' => [],
      'guardians' => [],
    ];

    $response = $doc->getElementsByTagName('FamilyLookupResponse')->item(0);
    if (!$response instanceof \DOMElement) {
      return $data;
    }

    $person = $response->getElementsByTagName('Person')->item(0);
    if ($person instanceof \DOMElement) {
      $data['person'] = [
        'cpr' => $this->childText($person, 'CPR'),
        'first_name' => $this->childText($person, 'FirstName'),
        'last_name' => $this->childText($person, 'LastName'),
        'birth_date' => $this->childText($person, 'BirthDate'),
      ];
    }

    foreach ($response->getElementsByTagName('Child') as $child) {
      $firstName = $this->childText($child, 'FirstName');
      $lastName = $this->childText($child, 'LastName');
      $data['children'][] = [
        'cpr' => $this->childText($child, 'CPR'),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'full_name' => trim("{$firstName} {$lastName}"),
        'birth_date' => $this->childText($child, 'BirthDate'),
        'protection' => $this->childText($child, 'AddressProtection') === 'true',
        'guardians' => $this->parseGuardians($child),
      ];
    }

    // Top-level guardians: custody holders of the requested person itself.
    // Only direct children of the response element count; guardians nested
    // inside Child elements belong to those children.
    foreach ($response->childNodes as $node) {
      if ($node instanceof \DOMElement && $node->tagName === 'Guardians') {
        $data['guardians'] = $this->parseGuardians($node);
      }
    }

    return $data;
  }

  /**
   * Parses Guardian elements inside a container element.
   *
   * @param \DOMElement $container
   *   The element holding Guardian children (a Child or Guardians element).
   *
   * @return array[]
   *   Guardian records with cpr, type, full_name and same_address keys.
   */
  protected function parseGuardians(\DOMElement $container): array {
    $guardians = [];
    foreach ($container->getElementsByTagName('Guardian') as $guardian) {
      $guardians[] = [
        'cpr' => $this->childText($guardian, 'CPR'),
        'type' => (int) $this->childText($guardian, 'GuardianType'),
        'full_name' => $this->childText($guardian, 'FullName'),
        'same_address' => $this->childText($guardian, 'SameAddress') !== 'false',
      ];
    }
    return $guardians;
  }

  /**
   * Returns the text content of the first named descendant element.
   *
   * @param \DOMElement $element
   *   The element to search within.
   * @param string $tag
   *   The descendant tag name.
   *
   * @return string
   *   The text content, or '' when absent.
   */
  protected function childText(\DOMElement $element, string $tag): string {
    return $element->getElementsByTagName($tag)->item(0)?->textContent ?? '';
  }

  /**
   * Gets the service URL from configuration.
   *
   * @param string $service
   *   The service name (SF1520, SF1530, SF1601, SF6006).
   *
   * @return string
   *   The service endpoint URL.
   */
  protected function getServiceUrl(string $service): string {
    $config = $this->configFactory->get('aabenforms_core.settings');
    $urls = $config->get('serviceplatformen.urls') ?? [];

    if (!isset($urls[$service])) {
      throw new \InvalidArgumentException("Unknown Serviceplatformen service: {$service}");
    }

    return $urls[$service];
  }

  /**
   * Generates cache key for the request.
   *
   * @param string $service
   *   The service name.
   * @param string $operation
   *   The operation name.
   * @param array $params
   *   The request parameters.
   *
   * @return string
   *   The cache key.
   */
  protected function getCacheKey(string $service, string $operation, array $params): string {
    $hash = hash('sha256', json_encode($params));
    return "aabenforms_core:serviceplatformen:{$service}:{$operation}:{$hash}";
  }

  /**
   * Calculates cache expiration timestamp.
   *
   * @param int $seconds
   *   Cache lifetime in seconds.
   *
   * @return int
   *   Unix timestamp when cache should expire.
   */
  protected function calculateCacheExpiration(int $seconds): int {
    return time() + $seconds;
  }

}
