<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Service;

/**
 * Builds absolute URLs into the citizen frontend from an allowlisted origin.
 *
 * The origin comes only from the deployment (CORS_ALLOW_ORIGIN, the same
 * value MitIdController::sanitizeReturnUrl trusts), never from request input,
 * so a redirect built here cannot be steered off-site. Paths are fixed by the
 * caller; the query is encoded here.
 */
class FrontendUrl {

  /**
   * Constructs the builder.
   *
   * @param string|null $origin
   *   Override for tests; NULL reads CORS_ALLOW_ORIGIN from the environment.
   */
  public function __construct(protected ?string $origin = NULL) {
  }

  /**
   * Returns the validated frontend origin, or NULL when none is configured.
   *
   * CORS_ALLOW_ORIGIN may hold a comma-separated list; the first entry is the
   * primary frontend. It must be a bare http(s) origin: scheme, host and an
   * optional port, nothing else (no path, query, fragment or credentials).
   */
  public function origin(): ?string {
    $raw = $this->origin ?? (string) (getenv('CORS_ALLOW_ORIGIN') ?: '');
    $first = trim(explode(',', $raw)[0]);
    if ($first === '' || $first === '*' || str_contains($first, '\\')) {
      return NULL;
    }
    $parts = parse_url($first);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return NULL;
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return NULL;
    }
    foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
      if (isset($parts[$forbidden])) {
        return NULL;
      }
    }
    if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
      return NULL;
    }
    if (!preg_match('/^[a-z0-9.-]+$/i', $parts['host'])) {
      return NULL;
    }
    $origin = $scheme . '://' . strtolower($parts['host']);
    if (isset($parts['port'])) {
      $origin .= ':' . (int) $parts['port'];
    }
    return $origin;
  }

  /**
   * Builds an absolute frontend URL.
   *
   * @param string $path
   *   A fixed, caller-supplied path starting with a single "/".
   * @param array $query
   *   Query parameters (scalar values only).
   *
   * @return string|null
   *   The URL, or NULL when no valid origin is configured or the path is not
   *   a plain local path.
   */
  public function build(string $path, array $query = []): ?string {
    $origin = $this->origin();
    if ($origin === NULL || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
      return NULL;
    }
    $url = $origin . $path;
    if ($query !== []) {
      $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
  }

}
