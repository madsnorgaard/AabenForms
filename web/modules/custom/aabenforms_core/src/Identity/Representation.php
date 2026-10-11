<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Identity;

/**
 * Acting on behalf of another citizen (handle på vegne af), from the IdP.
 *
 * A representation says: the authenticated person (the actor) may act for
 * the represented person (the subject) on a basis, within scopes. It only
 * ever comes from the verified IdP assertion stored in the session, never
 * from the client. The client can only point at an entry by index; every
 * check re-reads the session.
 *
 * Source today: the mock IdP's `fuldmagt` claim, a JSON list of
 * {cpr, name, scopes}, standing in for NemLog-in Digital Fuldmagt (in
 * production the OIOSAML attribute Privileges_intermediate, whose
 * PrivilegeGroup scope is the fuldmagtsgiver's CPR, #79). Scopes are the
 * webform ids the representation covers, plus `digital_post_copy` when the
 * actor may receive a copy of letters to the subject.
 */
final class Representation {

  /**
   * Basis: a digital power of attorney (digital fuldmagt).
   */
  public const BASIS_FULDMAGT = 'fuldmagt';

  /**
   * Scope that lets the actor receive copies of letters to the subject.
   */
  public const SCOPE_POST_COPY = 'digital_post_copy';

  /**
   * At most this many representations are kept per session.
   */
  private const MAX = 20;

  /**
   * Parses and strictly validates the IdP claim.
   *
   * @param mixed $claim
   *   The `fuldmagt` claim: a JSON string or an already decoded list.
   *
   * @return array[]
   *   Entries with keys cpr (10 digits), name, basis, scopes. Invalid entries
   *   are dropped, never repaired.
   */
  public static function fromClaim(mixed $claim): array {
    if (is_string($claim)) {
      $claim = json_decode($claim, TRUE);
    }
    if (!is_array($claim) || !array_is_list($claim)) {
      return [];
    }
    $out = [];
    foreach (array_slice($claim, 0, self::MAX) as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $cpr = preg_replace('/\D/', '', (string) ($entry['cpr'] ?? '')) ?? '';
      $name = trim((string) ($entry['name'] ?? ''));
      $scopes = array_values(array_filter(
        is_array($entry['scopes'] ?? NULL) ? $entry['scopes'] : [],
        static fn ($s): bool => is_string($s) && preg_match('/^[a-z0-9_]{1,64}$/', $s) === 1,
      ));
      if (strlen($cpr) !== 10 || $name === '' || mb_strlen($name) > 200 || $scopes === []) {
        continue;
      }
      $out[] = [
        'cpr' => $cpr,
        'name' => $name,
        'basis' => self::BASIS_FULDMAGT,
        'scopes' => $scopes,
      ];
    }
    return $out;
  }

  /**
   * What the citizen-facing API may show: no full CPR.
   *
   * @return array[]
   *   Entries with index, name, cpr (masked DDMMYY-XXXX), basis, scopes.
   */
  public static function publicView(array $representations): array {
    $view = [];
    foreach (array_values($representations) as $i => $r) {
      $view[] = [
        'index' => $i,
        'name' => (string) ($r['name'] ?? ''),
        'cpr' => substr((string) ($r['cpr'] ?? ''), 0, 6) . '-XXXX',
        'basis' => (string) ($r['basis'] ?? ''),
        'scopes' => array_values((array) ($r['scopes'] ?? [])),
      ];
    }
    return $view;
  }

  /**
   * The representation at an index, if it covers the scope.
   *
   * @param array $representations
   *   The session's representations.
   * @param mixed $index
   *   The index the client pointed at.
   * @param string $scope
   *   The webform id (or other scope) the action needs.
   *
   * @return array|null
   *   The full entry (with CPR), or NULL.
   */
  public static function find(array $representations, mixed $index, string $scope): ?array {
    if (!is_int($index) && !(is_string($index) && ctype_digit($index))) {
      return NULL;
    }
    $entry = array_values($representations)[(int) $index] ?? NULL;
    return is_array($entry) && in_array($scope, (array) ($entry['scopes'] ?? []), TRUE) ? $entry : NULL;
  }

  /**
   * The representation for a given subject CPR, if it covers the scope.
   */
  public static function covering(array $representations, string $subjectCpr, string $scope): ?array {
    foreach ($representations as $entry) {
      if (is_array($entry)
        && hash_equals((string) ($entry['cpr'] ?? ''), $subjectCpr)
        && in_array($scope, (array) ($entry['scopes'] ?? []), TRUE)) {
        return $entry;
      }
    }
    return NULL;
  }

}
