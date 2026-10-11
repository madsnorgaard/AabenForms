<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Privacy;

use Drupal\Core\TempStore\SharedTempStore;

/**
 * A shared tempstore that redacts PII on every write.
 *
 * Used only for the ECA replay store (collection eca_process_debugger), which
 * is readable across users while replay is armed (issue #74).
 */
class RedactingSharedTempStore extends SharedTempStore {

  use RedactsDebuggerPayloads;

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
    parent::set($key, $this->redactPayload($value));
  }

  /**
   * {@inheritdoc}
   */
  public function setIfNotExists($key, $value) {
    return parent::setIfNotExists($key, $this->redactPayload($value));
  }

  /**
   * {@inheritdoc}
   */
  public function setIfOwner($key, $value) {
    return parent::setIfOwner($key, $this->redactPayload($value));
  }

  /**
   * Sets the redactor; called by the factory right after construction.
   */
  public function setRedactor(PiiRedactor $redactor): void {
    $this->redactor = $redactor;
  }

}
