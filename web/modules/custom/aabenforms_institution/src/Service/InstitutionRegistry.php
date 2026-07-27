<?php

declare(strict_types=1);

namespace Drupal\aabenforms_institution\Service;

use Drupal\aabenforms_institution\Entity\AabenformsInstitution;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Query facade over the institution registry.
 *
 * Wraps the entity storage so workflow code depends on a small, mockable
 * surface instead of entity queries scattered through actions.
 */
class InstitutionRegistry {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Loads an institution by its 6-digit institutionsnummer.
   *
   * @param string $institutionNumber
   *   The institutionsnummer.
   *
   * @return \Drupal\aabenforms_institution\Entity\AabenformsInstitution|null
   *   The institution, or NULL when unknown.
   */
  public function findByNumber(string $institutionNumber): ?AabenformsInstitution {
    $institutionNumber = trim($institutionNumber);
    if ($institutionNumber === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('aabenforms_institution');
    $ids = $storage->getQuery()
      ->condition('institution_number', $institutionNumber)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $institution = $storage->load(reset($ids));
    return $institution instanceof AabenformsInstitution ? $institution : NULL;
  }

  /**
   * Returns active institutions of a type, sorted by name.
   *
   * @param string|null $type
   *   One of the AabenformsInstitution::TYPE_* constants, or NULL for all.
   *
   * @return \Drupal\aabenforms_institution\Entity\AabenformsInstitution[]
   *   The active institutions.
   */
  public function listActive(?string $type = NULL): array {
    $storage = $this->entityTypeManager->getStorage('aabenforms_institution');
    $query = $storage->getQuery()
      ->condition('active', TRUE)
      ->accessCheck(FALSE)
      ->sort('name');
    if ($type !== NULL) {
      $query->condition('type', $type);
    }
    $ids = $query->execute();
    return $ids === [] ? [] : array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn ($e) => $e instanceof AabenformsInstitution,
    ));
  }

  /**
   * Resolves the review-task recipient for an institution.
   *
   * Walks up the parent chain when the institution itself has no leader
   * email, so a school without a registered leader escalates to its
   * administrative parent instead of dropping the task.
   *
   * @param string $institutionNumber
   *   The institutionsnummer.
   *
   * @return string
   *   The leader email, or '' when neither the institution nor any parent
   *   carries one.
   */
  public function leaderEmailFor(string $institutionNumber): string {
    $institution = $this->findByNumber($institutionNumber);
    $depth = 0;
    while ($institution !== NULL && $depth < 5) {
      $email = $institution->getLeaderEmail();
      if ($email !== '') {
        return $email;
      }
      $parent = $institution->get('parent_institution')->entity;
      $institution = $parent instanceof AabenformsInstitution ? $parent : NULL;
      $depth++;
    }
    return '';
  }

}
