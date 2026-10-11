<?php

namespace Drupal\aabenforms_mitid\Service;

use Drupal\aabenforms_core\Identity\Representation;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for extracting CPR and person data from MitID authentication tokens.
 *
 * This is the PRIMARY CPR access method for ÅbenForms, replacing SF1520
 * for citizen-facing workflows. Benefits:
 * - No external API calls needed
 * - Real-time verified data
 * - GDPR compliant (authenticated = consent)
 * - Works for all new clients (no SF1520 dependency)
 */
class MitIdCprExtractor {

  /**
   * The audit logger.
   *
   * @var \Drupal\aabenforms_core\Service\AuditLogger
   */
  protected AuditLogger $auditLogger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a MitIdCprExtractor.
   *
   * @param \Drupal\aabenforms_core\Service\AuditLogger $audit_logger
   *   The audit logger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AuditLogger $audit_logger,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->auditLogger = $audit_logger;
    $this->logger = $logger_factory->get('aabenforms_mitid');
  }

  /**
   * Extracts CPR number from MitID ID token.
   *
   * @param string $id_token
   *   The MitID ID token (JWT).
   *
   * @return string|null
   *   The CPR number, or NULL if not present.
   *
   * @throws \InvalidArgumentException
   *   If token format is invalid.
   */
  public function extractCpr(string $id_token): ?string {
    $claims = $this->parseJwt($id_token);

    // Extract CPR from claims
    // MitID provides CPR in multiple possible claim names:
    // - 'cpr' (standard)
    // - 'dk:gov:saml:attribute:CprNumberIdentifier' (SAML bridge)
    // - 'mitid.cpr' (some implementations)
    $cpr = $claims['cpr']
      ?? $claims['dk:gov:saml:attribute:CprNumberIdentifier']
      ?? $claims['mitid.cpr']
      ?? NULL;

    if ($cpr) {
      // Clean CPR (remove hyphens if present)
      $cpr = preg_replace('/[^0-9]/', '', $cpr);

      // Audit log the CPR extraction.
      $this->auditLogger->logCprLookup(
        $cpr,
        'mitid_token_extraction',
        'success',
        [
          'method' => 'mitid_claims',
          'assurance_level' => $claims['acr'] ?? 'unknown',
          'mitid_uuid' => $claims['sub'] ?? NULL,
        ]
      );

      $this->logger->info('CPR extracted from MitID token: {cpr} (assurance: {acr})', [
      // Masked in logs.
        'cpr' => substr($cpr, 0, 6) . 'XXXX',
        'acr' => $claims['acr'] ?? 'unknown',
      ]);
    }
    else {
      $this->logger->warning('CPR not found in MitID token claims. Available claims: {claims}', [
        'claims' => implode(', ', array_keys($claims)),
      ]);
    }

    return $cpr;
  }

  /**
   * Gets full person data from MitID claims.
   *
   * @param string $id_token
   *   The MitID ID token.
   *
   * @return array
   *   Person data extracted from MitID claims.
   */
  public function extractPersonData(string $id_token): array {
    $claims = $this->parseJwt($id_token);

    $personData = [
      // Identity.
      'cpr' => $this->extractCpr($id_token),
      'mitid_uuid' => $claims['sub'] ?? NULL,

      // Name.
      'name' => $claims['name'] ?? NULL,
      'given_name' => $claims['given_name'] ?? NULL,
      'family_name' => $claims['family_name'] ?? NULL,

      // Additional data.
      'birthdate' => $claims['birthdate'] ?? NULL,
      'email' => $claims['email'] ?? NULL,

      // Address (mock IdP only - real MitID/NemLog-in does not issue these
      // claims; absent claims fall through as NULL and the frontend falls
      // back to manual entry).
      'street' => $claims['street'] ?? NULL,
      'postal_code' => $claims['postal_code'] ?? NULL,
      'city' => $claims['city'] ?? NULL,
      'municipality_code' => $claims['municipality_code'] ?? NULL,

      // Digital Post exemption (mock IdP only). Real MitID issues no such
      // claim; in production the authority consults the Digital Post
      // kontaktregister. Carried so demo flows can route letters to paper.
      'digital_post_exempt' => filter_var($claims['digital_post_exempt'] ?? FALSE, FILTER_VALIDATE_BOOLEAN),

      // Acting on behalf of others (mock IdP `fuldmagt` claim, standing in
      // for NemLog-in Digital Fuldmagt). Strictly validated; see
      // Representation. Never taken from the client.
      'representations' => Representation::fromClaim($claims['fuldmagt'] ?? NULL),

      // Authentication metadata.
    // NSIS level (substantial/high)
      'assurance_level' => $claims['acr'] ?? NULL,
      'auth_time' => $claims['auth_time'] ?? NULL,
      'issuer' => $claims['iss'] ?? NULL,

      // Business MitID (if applicable)
      'cvr' => $claims['cvr'] ?? $claims['dk:gov:saml:attribute:CvrNumberIdentifier'] ?? NULL,
      'business_name' => $claims['organization_name'] ?? NULL,
    ];

    // Log person data extraction (without sensitive details)
    $this->logger->info('Person data extracted from MitID: {name}, assurance: {acr}', [
      'name' => $personData['name'] ?? 'N/A',
      'acr' => $personData['assurance_level'] ?? 'unknown',
    ]);

    return $personData;
  }

  /**
   * Returns a single named claim from the id_token's payload.
   *
   * @param string $id_token
   *   Compact JWS/JWT.
   * @param string $claim
   *   Name of the claim to read (e.g. "nonce", "sub", "acr").
   *
   * @return mixed
   *   The claim value, or NULL if the token is malformed or the claim
   *   isn't present.
   */
  public function extractClaim(string $id_token, string $claim): mixed {
    try {
      $claims = $this->parseJwt($id_token);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    return $claims[$claim] ?? NULL;
  }

  /**
   * Parses a compact JWS/JWT and returns the decoded payload claims.
   *
   * @param string $jwt
   *   The JWT token string.
   *
   * @return array
   *   Decoded claims.
   *
   * @throws \InvalidArgumentException
   *   If JWT format is invalid.
   */
  protected function parseJwt(string $jwt): array {
    $parts = explode('.', $jwt);

    if (count($parts) !== 3) {
      throw new \InvalidArgumentException('Invalid JWT format. Expected 3 parts (header.payload.signature).');
    }

    // Decode payload (middle part)
    $payload = $this->base64UrlDecode($parts[1]);
    $claims = json_decode($payload, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \InvalidArgumentException('Invalid JWT payload: ' . json_last_error_msg());
    }

    return $claims ?? [];
  }

  /**
   * Base64 URL-safe decode.
   *
   * @param string $data
   *   The base64url encoded data.
   *
   * @return string
   *   The decoded data.
   */
  protected function base64UrlDecode(string $data): string {
    // Replace URL-safe characters.
    $base64 = strtr($data, '-_', '+/');

    // Add padding if needed.
    $padding = strlen($base64) % 4;
    if ($padding > 0) {
      $base64 .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($base64);
  }

  /**
   * Validates MitID token (basic checks).
   *
   * @param string $id_token
   *   The ID token.
   *
   * @return bool
   *   TRUE if token passes basic validation, FALSE otherwise.
   */
  public function validateToken(string $id_token): bool {
    try {
      $claims = $this->parseJwt($id_token);

      // Check required claims.
      $requiredClaims = ['iss', 'sub', 'aud', 'exp', 'iat'];
      foreach ($requiredClaims as $claim) {
        if (!isset($claims[$claim])) {
          $this->logger->warning('Missing required claim in MitID token: {claim}', ['claim' => $claim]);
          return FALSE;
        }
      }

      // Check expiration.
      $exp = $claims['exp'] ?? 0;
      if ($exp < time()) {
        $this->logger->warning('MitID token has expired (exp: {exp}, now: {now})', [
          'exp' => $exp,
          'now' => time(),
        ]);
        return FALSE;
      }

      // Check issued at (not in future)
      $iat = $claims['iat'] ?? 0;
      // Allow 60s clock skew.
      if ($iat > time() + 60) {
        $this->logger->warning('MitID token issued in future (iat: {iat}, now: {now})', [
          'iat' => $iat,
          'now' => time(),
        ]);
        return FALSE;
      }

      return TRUE;

    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('MitID token validation failed: {error}', ['error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Gets NSIS assurance level from token.
   *
   * @param string $id_token
   *   The ID token.
   *
   * @return string
   *   The assurance level ('low', 'substantial', 'high', 'unknown').
   */
  public function getAssuranceLevel(string $id_token): string {
    try {
      $claims = $this->parseJwt($id_token);
      $acr = $claims['acr'] ?? 'unknown';

      // Map MitID / NemLog-in ACR values to NSIS levels. NemLog-in asserts the
      // NSIS LoA URIs; the eIDAS URIs and bare words cover brokers/mocks.
      // https://digst.dk/it-loesninger/standarder/nsis/
      $mapping = [
        'https://data.gov.dk/concept/core/nsis/loa/Low' => 'low',
        'https://data.gov.dk/concept/core/nsis/loa/Substantial' => 'substantial',
        'https://data.gov.dk/concept/core/nsis/loa/High' => 'high',
        'http://eidas.europa.eu/LoA/low' => 'low',
        'http://eidas.europa.eu/LoA/substantial' => 'substantial',
        'http://eidas.europa.eu/LoA/high' => 'high',
        'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport' => 'low',
        'low' => 'low',
        'substantial' => 'substantial',
        'high' => 'high',
      ];

      // Fail closed: an unrecognised acr is 'unknown', never 'substantial'.
      return $mapping[$acr] ?? 'unknown';

    }
    catch (\InvalidArgumentException $e) {
      return 'unknown';
    }
  }

}
