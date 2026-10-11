<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Retention;

/**
 * Thrown when a purge is attempted on data that must be kept.
 */
final class RetentionRefusedException extends \RuntimeException {

  public function __construct(public readonly RetentionVerdict $verdict) {
    parent::__construct(sprintf('Purge refused: %s.', $verdict->reason));
  }

}
