<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Enrolment;

/**
 * Demo pupil enrolment rail, keyed on the DemoFamilyRepository children.
 *
 * Stands in for STIL elevdata until a data agreement and integration with
 * STIL exist. Always reports demo mode, so nothing built on it can be
 * presented as live. School names resolve through the institution registry
 * (aabenforms_institution) when installed, falling back to the fixture names
 * from aabenforms_institution/fixtures/aarhus_demo_institutions.csv.
 */
final class DemoPupilEnrolment implements PupilEnrolmentLookupInterface {

  /**
   * Demo enrolments keyed by child CPR.
   */
  private const ENROLMENTS = [
    // Emil (DemoFamilyRepository, shared custody Freja/Lars).
    '0109182345' => ['institution_number' => '751101', 'name' => 'Frederiksbjerg Skole', 'grade' => '2. klasse'],
    // Oliver (15+, shared custody Freja/Lars).
    '2005102345' => ['institution_number' => '751102', 'name' => 'Katrinebjergskolen', 'grade' => '9. klasse'],
    // Alma (sole custody Sofie).
    '1203192345' => ['institution_number' => '751103', 'name' => 'Møllevangskolen', 'grade' => '1. klasse'],
  ];

  /**
   * Constructs the demo rail.
   *
   * @param object|null $institutionRegistry
   *   The aabenforms_institution registry, when installed. Typed loosely:
   *   core must not depend on the institution module's classes.
   */
  public function __construct(
    private readonly ?object $institutionRegistry = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function currentInstitution(string $childCpr): ?array {
    $enrolment = self::ENROLMENTS[preg_replace('/\D/', '', $childCpr) ?? ''] ?? NULL;
    if ($enrolment === NULL) {
      return NULL;
    }
    if ($this->institutionRegistry !== NULL && method_exists($this->institutionRegistry, 'findByNumber')) {
      try {
        $institution = $this->institutionRegistry->findByNumber($enrolment['institution_number']);
        if ($institution !== NULL && method_exists($institution, 'label') && (string) $institution->label() !== '') {
          $enrolment['name'] = (string) $institution->label();
        }
      }
      catch (\Exception) {
        // Registry unavailable (e.g. entity schema not installed): keep the
        // fixture name.
      }
    }
    return $enrolment;
  }

  /**
   * {@inheritdoc}
   */
  public function isDemoMode(): bool {
    return TRUE;
  }

}
