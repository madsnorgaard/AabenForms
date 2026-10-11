<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Privacy;

/**
 * Redacts ECA replay payloads on their way into a tempstore.
 *
 * The ECA token browser (eca.token_browser) stores replay history as a
 * gzcompressed JSON string with a two-byte marker. The payload is opened,
 * redacted and resealed in the same format so the Workflow Modeler can still
 * read it. A payload that cannot be opened is replaced with an empty history:
 * fail closed, never store what could not be inspected.
 */
trait RedactsDebuggerPayloads {

  /**
   * The marker the ECA token browser prefixes compressed payloads with.
   */
  protected static string $compressPrefix = "\x1f\x9d";

  /**
   * The redactor.
   */
  protected PiiRedactor $redactor;

  /**
   * Returns a redacted copy of a value about to be written to the store.
   *
   * @param mixed $value
   *   The value the token browser wants to store.
   *
   * @return mixed
   *   The value with CPR numbers and secrets removed.
   */
  protected function redactPayload(mixed $value): mixed {
    if (!is_string($value) || !str_starts_with($value, self::$compressPrefix)) {
      return $this->redactor->redact($value);
    }
    $json = @gzuncompress(substr($value, strlen(self::$compressPrefix)));
    $decoded = $json === FALSE ? NULL : json_decode($json, TRUE);
    $data = is_array($decoded) ? $this->redactor->redactArray($decoded) : [];
    $encoded = json_encode($data);
    $compressed = $encoded === FALSE ? FALSE : gzcompress($encoded);
    return self::$compressPrefix . ($compressed === FALSE ? gzcompress('[]') : $compressed);
  }

}
