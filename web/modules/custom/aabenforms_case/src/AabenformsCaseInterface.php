<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Provides an interface for the ÅbenForms Case entity.
 */
interface AabenformsCaseInterface extends ContentEntityInterface, EntityChangedInterface, RevisionLogInterface {

  /**
   * Gets the casework area / case type (e.g. "underretning").
   */
  public function getCaseType(): string;

  /**
   * Gets the lawful lifecycle status machine name.
   */
  public function getStatus(): string;

  /**
   * Sets the lifecycle status machine name.
   */
  public function setStatus(string $status): static;

  /**
   * Gets the deadline (frist) due timestamp, or NULL when unset.
   */
  public function getFristDue(): ?int;

  /**
   * Gets the receipt-date timestamp (deadline clock start), or NULL.
   */
  public function getModtagelsesdato(): ?int;

  /**
   * Gets the referenced webform submission id, or NULL.
   */
  public function getSubmissionId(): ?string;

  /**
   * Gets the SHA-256 hash of the applicant CPR (lookup key only), or NULL.
   */
  public function getSubjectCprHash(): ?string;

  /**
   * Sets the SHA-256 hash of the applicant CPR. NULL or '' clears it.
   */
  public function setSubjectCprHash(?string $hash): static;

  /**
   * Gets the decision (afgoerelse) timestamp, or NULL when not decided.
   */
  public function getAfgoerelseDato(): ?int;

}
