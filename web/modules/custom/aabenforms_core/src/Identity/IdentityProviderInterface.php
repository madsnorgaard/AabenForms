<?php

namespace Drupal\aabenforms_core\Identity;

/**
 * Contract for a citizen-identity provider.
 *
 * An identity provider drives an authentication protocol (OIDC or SAML) to its
 * end and terminates in a verified session: it validates whatever its protocol
 * hands back, produces a VerifiedIdentity, and persists it through a
 * SessionManagerInterface keyed by the workflow_id bearer handle. Two
 * implementations exist - MitID OIDC (demo / private-sector rail) and NemLog-in
 * OIOSAML 3 (production kommune rail) - and they are interchangeable because
 * they share both this contract and the session store.
 */
interface IdentityProviderInterface {

  /**
   * The stable provider id stamped onto every identity this provider asserts.
   *
   * Recorded on the session (VerifiedIdentity::$provider) and in the audit
   * trail so a login can always be attributed to the rail that produced it.
   *
   * @return string
   *   A stable machine id, e.g. 'mitid_oidc' or 'nemlogin_saml'.
   */
  public function getProviderId(): string;

}
