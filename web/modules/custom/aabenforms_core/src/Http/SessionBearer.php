<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a MitID workflow session id from the Authorization header.
 *
 * The session id is a capability: it must travel as
 * "Authorization: Bearer <session>", never in a URL where it lands in
 * access logs, browser history and Referer headers.
 */
final class SessionBearer {

  /**
   * The bearer token from the Authorization header, or ''.
   */
  public static function fromRequest(Request $request): string {
    $header = (string) $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_.\-]{8,200})$/', trim($header), $m)) {
      return '';
    }
    return $m[1];
  }

}
