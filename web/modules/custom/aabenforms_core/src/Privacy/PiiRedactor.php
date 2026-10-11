<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Privacy;

/**
 * Redacts CPR numbers and capability secrets before data is persisted.
 *
 * One small, dependency-free rule set shared by every store that keeps
 * request-derived data beyond the request: the workflow trace table, the
 * execution collector and the ECA replay (token browser) tempstore. It is
 * deliberately over-eager - a debug or evidence store never needs a raw CPR or
 * a live session handle, so a false positive costs nothing and a miss leaks
 * PII.
 *
 * Rules:
 * - Any 10-digit CPR (DDMMYY-XXXX or DDMMYYXXXX, day 01-31 or 61-91 for
 *   replacement numbers, month 01-12) inside a string becomes 'DDMMYY-XXXX'.
 * - Values under a sensitive key (tokens, passwords, MitID workflow/session
 *   handles, client IP) become '[redacted]', whatever their type.
 * - ECA normalized token entries ({token: 'a:b:password', value: ...}) are
 *   treated as keyed by the last segment of their token path.
 * - MitID workflow handles (wf_...) and secret-looking query parameters in
 *   URLs are masked inside free text.
 */
final class PiiRedactor {

  /**
   * The replacement for a masked CPR number.
   */
  public const CPR_MASK = 'DDMMYY-XXXX';

  /**
   * The replacement for a redacted secret value.
   */
  public const REDACTED = '[redacted]';

  /**
   * Keys whose values are secrets or bearer capabilities (lowercase, exact).
   *
   * Exact matching on purpose: ECA action config uses many *_token keys
   * (cpr_token, result_token) that hold token NAMES, not secrets.
   */
  public const SENSITIVE_KEYS = [
    'access_token',
    'client_ip',
    'client_secret',
    'code_verifier',
    'id_token',
    'id_token_hint',
    'ip',
    'mitid_session_id',
    'mitid_workflow_id',
    'nonce',
    'pass',
    'password',
    'refresh_token',
    'secret',
    'session',
    'session_id',
    'token',
    'workflow_id',
  ];

  /**
   * CPR: 6 date digits, optional hyphen, 4 digits, not inside a longer number.
   */
  private const CPR_PATTERN = '/(?<!\d)(?:0[1-9]|[12]\d|3[01]|6[1-9]|[78]\d|9[01])(?:0[1-9]|1[0-2])\d{2}-?\d{4}(?!\d)/';

  /**
   * A MitID workflow handle as minted by the MitID rail (wf_ + 16+ chars).
   */
  private const WORKFLOW_HANDLE_PATTERN = '/\bwf_[A-Za-z0-9_\-]{16,}/';

  /**
   * Secret-bearing query parameters inside URLs or free text.
   */
  private const QUERY_SECRET_PATTERN = '/([?&](?:session|session_id|workflow_id|token|code|state|id_token|access_token|refresh_token)=)[^&#\s"\']+/i';

  /**
   * Redacts a value of any shape.
   *
   * @param mixed $value
   *   A scalar, array or object graph (stdClass is handled, other objects are
   *   returned untouched).
   *
   * @return mixed
   *   The redacted value of the same shape.
   */
  public function redact(mixed $value): mixed {
    if (is_string($value)) {
      return $this->redactString($value);
    }
    if (is_array($value)) {
      return $this->redactArray($value);
    }
    if ($value instanceof \stdClass) {
      $copy = clone $value;
      foreach (get_object_vars($copy) as $key => $item) {
        $copy->{$key} = $this->isSensitiveKey((string) $key) ? self::REDACTED : $this->redact($item);
      }
      return $copy;
    }
    return $value;
  }

  /**
   * Masks CPR numbers, workflow handles and URL secrets in a string.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The masked text.
   */
  public function redactString(string $text): string {
    $text = preg_replace(self::CPR_PATTERN, self::CPR_MASK, $text) ?? self::REDACTED;
    $text = preg_replace(self::QUERY_SECRET_PATTERN, '$1' . self::REDACTED, $text) ?? self::REDACTED;
    return preg_replace(self::WORKFLOW_HANDLE_PATTERN, 'wf_' . self::REDACTED, $text) ?? self::REDACTED;
  }

  /**
   * Redacts an array recursively.
   *
   * @param array $data
   *   The data.
   *
   * @return array
   *   The redacted data; keys are kept, sensitive values replaced.
   */
  public function redactArray(array $data): array {
    // An ECA token browser entry ({label, token, value|data}) carries its name
    // in 'token' as a token path, so the path decides whether it is secret.
    $eca_entry = isset($data['label'], $data['token']) && is_string($data['token'])
      && preg_match('/^\[?[\w\-]+(?::[^\s:\]]+)*\]?$/', $data['token']);
    $eca_secret = $eca_entry && $this->isSensitiveKey($this->lastTokenSegment($data['token']));

    $out = [];
    foreach ($data as $key => $item) {
      $key_string = (string) $key;
      if ($eca_entry && $key_string === 'token') {
        $out[$key] = $this->redactString($item);
      }
      elseif ($eca_secret && ($key_string === 'value' || $key_string === 'data')) {
        $out[$key] = self::REDACTED;
      }
      elseif ($this->isSensitiveKey($key_string)) {
        $out[$key] = self::REDACTED;
      }
      else {
        $out[$key] = $this->redact($item);
      }
    }
    return $out;
  }

  /**
   * Whether a key names a secret or capability.
   *
   * @param string $key
   *   The array key.
   *
   * @return bool
   *   TRUE when its value must not be stored.
   */
  public function isSensitiveKey(string $key): bool {
    return in_array(strtolower($key), self::SENSITIVE_KEYS, TRUE);
  }

  /**
   * Returns the last segment of an ECA token path ("[a:b:c]" -> "c").
   */
  private function lastTokenSegment(string $token): string {
    $parts = explode(':', trim($token, '[]'));
    return (string) end($parts);
  }

}
