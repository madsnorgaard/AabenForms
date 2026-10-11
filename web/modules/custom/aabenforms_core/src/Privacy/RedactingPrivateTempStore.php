<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Privacy;

use Drupal\Core\TempStore\PrivateTempStore;

/**
 * A private tempstore that redacts PII on every write.
 *
 * Used only for the ECA replay store (collection eca_process_debugger).
 */
class RedactingPrivateTempStore extends PrivateTempStore {

  use RedactsDebuggerPayloads;

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
    parent::set($key, $this->redactPayload($value));
  }

  /**
   * Sets the redactor; called by the factory right after construction.
   */
  public function setRedactor(PiiRedactor $redactor): void {
    $this->redactor = $redactor;
  }

}
