<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Identity;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;

/**
 * Hands a verified session to a cross-origin SPA without a URL capability.
 *
 * The session id is a bearer capability and must never travel in a URL
 * (access logs, history, Referer). After login the IdP callback instead
 * appends a one-time code. The SPA redeems it with a POST that carries the
 * secret behind the challenge it sent at login (the PKCE pattern, RFC 7636,
 * S256 only):
 *  - the code alone is worthless: a code copied from a log or a Referer
 *    cannot be redeemed without the verifier, which never left the browser;
 *  - the code is stored only as a hash, lives 60 seconds and is deleted on
 *    the first redeem attempt, right or wrong, so the verifier cannot be
 *    brute-forced against it.
 * Shared by every login rail (MitID OIDC, NemLog-in SAML).
 */
class LoginHandoff {

  /**
   * Key-value collection holding pending handoffs.
   */
  private const COLLECTION = 'aabenforms_login_handoff';

  /**
   * Lifetime of a code, in seconds.
   */
  private const TTL = 60;

  /**
   * A base64url SHA-256 digest (the S256 challenge) is 43 characters.
   */
  private const CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

  /**
   * RFC 7636 verifier: 43-128 unreserved characters.
   */
  private const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}$/';

  public function __construct(
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
  ) {
  }

  /**
   * Whether a client challenge is well formed (S256, base64url).
   */
  public static function isValidChallenge(mixed $challenge): bool {
    return is_string($challenge) && preg_match(self::CHALLENGE_PATTERN, $challenge) === 1;
  }

  /**
   * Issues a one-time code for a verified session.
   *
   * @param string $workflowId
   *   The session id to hand over.
   * @param string $challenge
   *   The client's S256 challenge from the login request.
   *
   * @return string
   *   The code to append to the return URL.
   */
  public function issue(string $workflowId, string $challenge): string {
    if (!self::isValidChallenge($challenge)) {
      throw new \InvalidArgumentException('Invalid client challenge.');
    }
    $code = self::base64url(random_bytes(32));
    $this->store()->setWithExpire(self::key($code), [
      'workflow_id' => $workflowId,
      'challenge' => $challenge,
    ], self::TTL);
    return $code;
  }

  /**
   * Redeems a code once. Any attempt consumes it.
   *
   * @param mixed $code
   *   The code from the return URL.
   * @param mixed $verifier
   *   The secret behind the challenge.
   *
   * @return string|null
   *   The session id, or NULL when the code is unknown, expired, already
   *   used, or the verifier does not match.
   */
  public function redeem(mixed $code, mixed $verifier): ?string {
    if (!is_string($code) || preg_match('/^[A-Za-z0-9_-]{43}$/', $code) !== 1) {
      return NULL;
    }
    $store = $this->store();
    $key = self::key($code);
    $pending = $store->get($key);
    // Single use, whatever the outcome.
    $store->delete($key);
    if (!is_array($pending) || !is_string($verifier) || preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
      return NULL;
    }
    $expected = self::base64url(hash('sha256', $verifier, TRUE));
    if (!hash_equals((string) ($pending['challenge'] ?? ''), $expected)) {
      return NULL;
    }
    return is_string($pending['workflow_id'] ?? NULL) ? $pending['workflow_id'] : NULL;
  }

  /**
   * Appends the code to a return URL.
   */
  public static function appendCode(string $returnUrl, string $code): string {
    return $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'code=' . rawurlencode($code);
  }

  /**
   * The store.
   */
  protected function store() {
    return $this->keyValueExpirable->get(self::COLLECTION);
  }

  /**
   * The storage key: only a hash of the code is stored.
   */
  protected static function key(string $code): string {
    return hash('sha256', $code);
  }

  /**
   * Base64url without padding.
   */
  protected static function base64url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

}
