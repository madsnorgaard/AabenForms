<?php

namespace Drupal\aabenforms_core\Identity;

/**
 * Contract for the flow-scoped verified-identity session store.
 *
 * A session is keyed by an unguessable, short-lived workflow_id bearer handle
 * (not a Drupal user account). Both identity rails - MitID OIDC and NemLog-in
 * OIOSAML 3 - write through this contract, and every downstream consumer (the
 * ECA gate, the webform intake, the CPR extractor) reads through it, so the
 * two rails are fully interchangeable. The canonical implementation is aliased
 * as the `aabenforms_identity.session_manager` service.
 */
interface SessionManagerInterface {

  /**
   * Stores verified-identity session data for a workflow instance.
   *
   * @param string $workflow_id
   *   The workflow instance / bearer id.
   * @param array $session_data
   *   The flat session data (typically VerifiedIdentity::toSessionArray()).
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function storeSession(string $workflow_id, array $session_data): bool;

  /**
   * Retrieves session data for a workflow instance.
   *
   * @param string $workflow_id
   *   The workflow instance / bearer id.
   *
   * @return array|null
   *   The session data, or NULL if not found or expired.
   */
  public function getSession(string $workflow_id): ?array;

  /**
   * Deletes session data for a workflow instance.
   *
   * @param string $workflow_id
   *   The workflow instance / bearer id.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteSession(string $workflow_id): bool;

  /**
   * Checks whether a valid (unexpired) session exists for a workflow.
   *
   * @param string $workflow_id
   *   The workflow instance / bearer id.
   *
   * @return bool
   *   TRUE if a valid session exists, FALSE otherwise.
   */
  public function hasValidSession(string $workflow_id): bool;

  /**
   * Gets the CPR number from a workflow session.
   *
   * @param string $workflow_id
   *   The workflow instance / bearer id.
   *
   * @return string|null
   *   The CPR number, or NULL if not available.
   */
  public function getCprFromSession(string $workflow_id): ?string;

  /**
   * Gets the person data from a workflow session.
   *
   * @param string $workflow_id
   *   The workflow instance / bearer id.
   *
   * @return array|null
   *   The person data, or NULL if not available.
   */
  public function getPersonDataFromSession(string $workflow_id): ?array;

}
