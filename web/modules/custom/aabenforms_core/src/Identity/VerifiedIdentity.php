<?php

namespace Drupal\aabenforms_core\Identity;

/**
 * Immutable value object for a verified citizen identity.
 *
 * This is the provider-neutral currency of the identity layer: an OIDC login
 * (MitID via the Keycloak/private-sector rail) and a SAML login (NemLog-in
 * OIOSAML 3, the production kommune rail) both terminate in a VerifiedIdentity,
 * which is then flattened into the session store the whole platform reads.
 *
 * The session array shape (cpr, name, given_name, family_name, birthdate,
 * email, assurance_level, mitid_uuid) predates this object; toSessionArray()
 * reproduces it verbatim so a SAML rail can be added without touching a single
 * downstream reader (the ECA gate, the CPR extractor, getPersonDataFromSession).
 */
final class VerifiedIdentity {

  /**
   * NSIS assurance ranking (unknown < low < substantial < high, fail-closed).
   *
   * The single source of truth for assurance ordering across the platform;
   * MitIdOidcClient and MitIdValidateAction apply the same ranking.
   */
  public const ASSURANCE_RANKS = [
    'unknown' => 0,
    'low' => 1,
    'substantial' => 2,
    'high' => 3,
  ];

  /**
   * Constructs a VerifiedIdentity.
   *
   * @param string $cpr
   *   The 10-digit CPR number of the authenticated citizen.
   * @param string $assuranceLevel
   *   The NSIS level: 'low', 'substantial', 'high' or 'unknown'.
   * @param string $provider
   *   The provider id that asserted this identity (e.g. 'mitid_oidc',
   *   'nemlogin_saml').
   * @param string|null $subject
   *   The provider subject identifier (OIDC sub / SAML NameID / MitID UUID).
   * @param string|null $name
   *   The full name, when the IdP issues it.
   * @param string|null $givenName
   *   The given name, when issued.
   * @param string|null $familyName
   *   The family name, when issued.
   * @param string|null $birthdate
   *   The birthdate, when issued.
   * @param string|null $email
   *   The email, when issued.
   * @param string|null $issuer
   *   The asserting IdP entity id / issuer, when known.
   * @param int|null $authTime
   *   The authentication instant as a Unix timestamp, when known.
   */
  public function __construct(
    public readonly string $cpr,
    public readonly string $assuranceLevel,
    public readonly string $provider,
    public readonly ?string $subject = NULL,
    public readonly ?string $name = NULL,
    public readonly ?string $givenName = NULL,
    public readonly ?string $familyName = NULL,
    public readonly ?string $birthdate = NULL,
    public readonly ?string $email = NULL,
    public readonly ?string $issuer = NULL,
    public readonly ?int $authTime = NULL,
  ) {}

  /**
   * Rank of this identity's assurance level (fail-closed to 0 for unknown).
   *
   * @return int
   *   0 (unknown) to 3 (high).
   */
  public function assuranceRank(): int {
    return self::ASSURANCE_RANKS[strtolower($this->assuranceLevel)] ?? 0;
  }

  /**
   * Whether this identity meets a required NSIS assurance level.
   *
   * @param string $required
   *   The required level ('low'|'substantial'|'high'). An empty string means
   *   no requirement (always satisfied).
   *
   * @return bool
   *   TRUE when this identity's level is at least the requirement.
   */
  public function meetsAssurance(string $required): bool {
    $required = strtolower(trim($required));
    if ($required === '') {
      return TRUE;
    }
    $need = self::ASSURANCE_RANKS[$required] ?? 2;
    return $this->assuranceRank() >= $need;
  }

  /**
   * Flattens the identity into the platform's session array shape.
   *
   * The keys match exactly what MitIdSessionManager stores and
   * getPersonDataFromSession reads, so both identity rails are interchangeable
   * to every downstream consumer. Null person fields are omitted so a real
   * MitID/NemLog-in login (which issues no address/name claims) does not stamp
   * empty strings over absent data.
   *
   * @return array
   *   The flat session data (person fields + provider metadata).
   */
  public function toSessionArray(): array {
    $data = [
      'cpr' => $this->cpr,
      'assurance_level' => $this->assuranceLevel,
      'identity_provider' => $this->provider,
    ];
    // The legacy key downstream readers use for the subject identifier.
    if ($this->subject !== NULL) {
      $data['mitid_uuid'] = $this->subject;
    }
    $optional = [
      'name' => $this->name,
      'given_name' => $this->givenName,
      'family_name' => $this->familyName,
      'birthdate' => $this->birthdate,
      'email' => $this->email,
      'issuer' => $this->issuer,
    ];
    foreach ($optional as $key => $value) {
      if ($value !== NULL && $value !== '') {
        $data[$key] = $value;
      }
    }
    if ($this->authTime !== NULL) {
      $data['authenticated_at'] = $this->authTime;
    }
    return $data;
  }

  /**
   * Reconstructs a VerifiedIdentity from a stored session array.
   *
   * @param array $session
   *   A session array as produced by toSessionArray() or the legacy OIDC flow.
   *
   * @return self
   *   The identity value object.
   */
  public static function fromSessionArray(array $session): self {
    return new self(
      cpr: (string) ($session['cpr'] ?? ''),
      assuranceLevel: (string) ($session['assurance_level'] ?? 'unknown'),
      provider: (string) ($session['identity_provider'] ?? 'mitid_oidc'),
      subject: $session['mitid_uuid'] ?? NULL,
      name: $session['name'] ?? NULL,
      givenName: $session['given_name'] ?? NULL,
      familyName: $session['family_name'] ?? NULL,
      birthdate: $session['birthdate'] ?? NULL,
      email: $session['email'] ?? NULL,
      issuer: $session['issuer'] ?? NULL,
      authTime: isset($session['authenticated_at']) ? (int) $session['authenticated_at'] : NULL,
    );
  }

}
