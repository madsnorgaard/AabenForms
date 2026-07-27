<?php

namespace Drupal\aabenforms_workflows\Service;

use Drupal\Core\PrivateKey;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and validating secure approval tokens.
 *
 * Provides HMAC-based token generation for parent approval links
 * to prevent tampering and unauthorized access.
 */
class ApprovalTokenService {

  /**
   * Token expiration time in seconds (14 days).
   *
   * Matches the national co-signature precedent: the borger.dk Omsorgs- og
   * ansvarserklaering gives the co-signer a 14-day window to sign via the
   * Digital Post link.
   */
  const TOKEN_EXPIRATION = 1209600;

  /**
   * Allowed clock skew when validating a token timestamp (1 hour).
   *
   * A future timestamp is rejected as out-of-range, but we allow a small
   * grace window so a slightly fast client clock or a token minted on a
   * peer node with a tiny NTP drift still validates.
   */
  const MAX_CLOCK_SKEW = 3600;

  /**
   * The private key service.
   *
   * @var \Drupal\Core\PrivateKey
   */
  protected PrivateKey $privateKey;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an ApprovalTokenService object.
   *
   * @param \Drupal\Core\PrivateKey $private_key
   *   The private key service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(PrivateKey $private_key, $logger_factory) {
    $this->privateKey = $private_key;
    $this->logger = $logger_factory->get('aabenforms_workflows');
  }

  /**
   * Generates a secure token for approval link.
   *
   * @param int $submission_id
   *   The webform submission ID.
   * @param int $parent_number
   *   The parent number (1 or 2).
   * @param int|null $timestamp
   *   Optional timestamp. Defaults to current time.
   *
   * @return string
   *   The generated token with timestamp.
   */
  public function generateToken(int $submission_id, int $parent_number, ?int $timestamp = NULL): string {
    $timestamp = $timestamp ?? time();
    $data = "{$submission_id}:{$parent_number}:{$timestamp}";
    $hash = $this->generateHash($data);

    $this->logger->info('Generated approval token for submission @sid, parent @parent', [
      '@sid' => $submission_id,
      '@parent' => $parent_number,
    ]);

    return base64_encode("{$hash}:{$timestamp}");
  }

  /**
   * Validates a token for approval link.
   *
   * @param int $submission_id
   *   The webform submission ID.
   * @param int $parent_number
   *   The parent number (1 or 2).
   * @param string $token
   *   The token to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function validateToken(int $submission_id, int $parent_number, string $token): bool {
    try {
      // Strict decode: rejects whitespace and non-base64 characters that
      // would otherwise round-trip silently.
      //
      // Format-failure logs below are notice-level: they fire on any
      // garbage URL hit (typo, mangled email link, scanner). The
      // ParentApprovalController already logs a single warning per
      // failed validation with the request context attached, so
      // doubling up at warning would just spam the channel.
      $decoded = base64_decode($token, TRUE);
      if ($decoded === FALSE || $decoded === '') {
        $this->logger->notice('Invalid token format for submission @sid', [
          '@sid' => $submission_id,
        ]);
        return FALSE;
      }

      // Guard the destructure: a token without ':' would raise undefined
      // array key/offset warnings on unconditional list-assignment, and a
      // missing or empty timestamp could end up coercing to (int) 0
      // ("valid in 1970") if not rejected first.
      $parts = explode(':', $decoded, 2);
      if (count($parts) !== 2) {
        $this->logger->notice('Malformed token (no separator) for submission @sid', [
          '@sid' => $submission_id,
        ]);
        return FALSE;
      }
      [$hash, $timestamp_raw] = $parts;

      // Range-check the timestamp: must be numeric, positive, and not
      // implausibly far in the future. MAX_CLOCK_SKEW of slack.
      if (!is_numeric($timestamp_raw)) {
        $this->logger->notice('Non-numeric timestamp in token for submission @sid', [
          '@sid' => $submission_id,
        ]);
        return FALSE;
      }
      $timestamp = (int) $timestamp_raw;
      $now = time();
      if ($timestamp <= 0 || $timestamp > $now + self::MAX_CLOCK_SKEW) {
        $this->logger->notice('Out-of-range timestamp in token for submission @sid', [
          '@sid' => $submission_id,
        ]);
        return FALSE;
      }

      // Check expiration.
      if ($now - $timestamp > self::TOKEN_EXPIRATION) {
        $this->logger->info('Expired token for submission @sid, parent @parent', [
          '@sid' => $submission_id,
          '@parent' => $parent_number,
        ]);
        return FALSE;
      }

      // Verify hash.
      $data = "{$submission_id}:{$parent_number}:{$timestamp}";
      $expected_hash = $this->generateHash($data);

      if (!hash_equals($expected_hash, $hash)) {
        $this->logger->warning('Token validation failed for submission @sid, parent @parent', [
          '@sid' => $submission_id,
          '@parent' => $parent_number,
        ]);
        return FALSE;
      }

      $this->logger->info('Token validated successfully for submission @sid, parent @parent', [
        '@sid' => $submission_id,
        '@parent' => $parent_number,
      ]);

      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Token validation error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Generates HMAC hash for data.
   *
   * @param string $data
   *   The data to hash.
   *
   * @return string
   *   The HMAC hash.
   */
  protected function generateHash(string $data): string {
    $key = $this->privateKey->get();
    return hash_hmac('sha256', $data, $key);
  }

  /**
   * Extracts timestamp from token.
   *
   * @param string $token
   *   The token.
   *
   * @return int|null
   *   The timestamp or NULL if invalid.
   */
  public function getTokenTimestamp(string $token): ?int {
    try {
      $decoded = base64_decode($token, TRUE);
      if ($decoded === FALSE || $decoded === '') {
        return NULL;
      }
      $parts = explode(':', $decoded, 2);
      if (count($parts) !== 2) {
        return NULL;
      }
      [, $timestamp_raw] = $parts;
      if (!is_numeric($timestamp_raw)) {
        return NULL;
      }
      $timestamp = (int) $timestamp_raw;
      return $timestamp > 0 ? $timestamp : NULL;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Checks if a token is genuinely expired.
   *
   * Returns TRUE only when the token's timestamp resolves AND is older
   * than TOKEN_EXPIRATION. Malformed tokens (no timestamp at all) are
   * NOT expired - they are malformed; isTokenMalformed() reports that
   * separately so the controller can render different UX for each case.
   *
   * @param string $token
   *   The token to inspect.
   *
   * @return bool
   *   TRUE only if the token has a parseable, in-range timestamp that is
   *   older than TOKEN_EXPIRATION seconds.
   */
  public function isTokenExpired(string $token): bool {
    $timestamp = $this->getTokenTimestamp($token);
    if ($timestamp === NULL) {
      return FALSE;
    }
    return (time() - $timestamp) > self::TOKEN_EXPIRATION;
  }

  /**
   * Checks if a token is malformed (cannot be parsed).
   *
   * Returns TRUE when the token fails any of the structural checks:
   * non-base64, no separator, non-numeric or out-of-range timestamp.
   * This is distinct from "expired" (well-formed but past) and from
   * "tampered" (well-formed, in-date, but HMAC mismatch).
   *
   * Lets the controller render a citizen-friendly "this link is invalid,
   * check the URL or contact us" message instead of the misleading
   * "expired link" UX, which suggests the citizen just needs a fresher
   * link when in fact their URL is corrupted.
   *
   * @param string $token
   *   The token to inspect.
   *
   * @return bool
   *   TRUE if the token cannot be parsed into a (hash, timestamp) pair
   *   with a sensible numeric timestamp.
   */
  public function isTokenMalformed(string $token): bool {
    return $this->getTokenTimestamp($token) === NULL;
  }

}
