<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Family;

/**
 * Canonical demo family scenarios for custody-aware flows.
 *
 * Extends the demo persona set (see aabenforms_mitid DemoPersonas; the adult
 * CPRs below are the same synthetic numbers) with the three family
 * constellations every school/family flow must handle:
 *
 * - Shared custody, separated parents: Freja Nielsen and Lars Andersen hold
 *   joint custody of Emil (born 2018). Lars lives at another address, so
 *   decision letters must go out twice (the 21 March 2022 Digital Post rule)
 *   and co-signature flows have a real second signer.
 * - Sole custody: Sofie Hansen is the only custody holder of Alma (born
 *   2019). Flows must skip the co-guardian branch entirely.
 * - Pupil aged 15+: Emil's brother Oliver (born 2010) is over 15, so Digital
 *   Post about Oliver goes to Oliver himself, not the parents.
 * - No minor children: Mikkel Jensen has none, exercising the empty branch.
 * - Adult child: Mikkel is the son of Birthe Jensen (born 1948, Digital
 *   Post exempt). This is a plain family relation with NO custody semantics:
 *   it never appears in childrenOf()/guardiansOf(), which only answer
 *   foraeldremyndighed questions. It exists so a relative-assists scenario
 *   (Mikkel helping Birthe with a klage) has a registered relation to show,
 *   see adultChildrenOf() and parentsOf().
 *
 * This is demo/test scaffolding only: it backs the family lookup service when
 * no Serviceplatformen certificate is provisioned, and the WireMock SF6006
 * mappings in .ddev/mocks/wiremock mirror exactly this data.
 */
final class DemoFamilyRepository {

  private const FREJA = '0101904521';

  private const LARS = '0803755210';

  private const SOFIE = '2506924015';

  private const BIRTHE = '2403482210';

  private const MIKKEL = '1502856234';

  /**
   * Demo children keyed by the child's CPR.
   */
  private const CHILDREN = [
    // Shared custody, parents at different addresses, child under 15.
    '0109182345' => [
      'cpr' => '0109182345',
      'first_name' => 'Emil',
      'last_name' => 'Nielsen Andersen',
      'full_name' => 'Emil Nielsen Andersen',
      'birth_date' => '2018-09-01',
      'protection' => FALSE,
      'guardians' => [
        [
          'cpr' => self::FREJA,
          'type' => GuardianType::MOTHER,
          'full_name' => 'Freja Nielsen',
          'same_address' => TRUE,
        ],
        [
          'cpr' => self::LARS,
          'type' => GuardianType::FATHER,
          'full_name' => 'Lars Andersen',
          'same_address' => FALSE,
        ],
      ],
    ],
    // Shared custody, pupil aged 15+ (Digital Post goes to the pupil).
    '2005102345' => [
      'cpr' => '2005102345',
      'first_name' => 'Oliver',
      'last_name' => 'Nielsen Andersen',
      'full_name' => 'Oliver Nielsen Andersen',
      'birth_date' => '2010-05-20',
      'protection' => FALSE,
      'guardians' => [
        [
          'cpr' => self::FREJA,
          'type' => GuardianType::MOTHER,
          'full_name' => 'Freja Nielsen',
          'same_address' => TRUE,
        ],
        [
          'cpr' => self::LARS,
          'type' => GuardianType::FATHER,
          'full_name' => 'Lars Andersen',
          'same_address' => FALSE,
        ],
      ],
    ],
    // Sole custody.
    '1203192345' => [
      'cpr' => '1203192345',
      'first_name' => 'Alma',
      'last_name' => 'Hansen',
      'full_name' => 'Alma Hansen',
      'birth_date' => '2019-03-12',
      'protection' => FALSE,
      'guardians' => [
        [
          'cpr' => self::SOFIE,
          'type' => GuardianType::MOTHER,
          'full_name' => 'Sofie Hansen',
          'same_address' => TRUE,
        ],
      ],
    ],
  ];

  /**
   * Adult children keyed by the parent's CPR.
   *
   * Deliberately a separate table from CHILDREN: these records carry no
   * guardians and no custody, so no custody-aware code path can ever pick
   * them up by accident.
   */
  private const ADULT_CHILDREN = [
    self::BIRTHE => [
      [
        'cpr' => self::MIKKEL,
        'first_name' => 'Mikkel',
        'last_name' => 'Jensen',
        'full_name' => 'Mikkel Jensen',
        'birth_date' => '1985-02-15',
        'protection' => FALSE,
        'relation' => 'son',
      ],
    ],
  ];

  /**
   * Returns the children the given adult holds custody of.
   *
   * @param string $parentCpr
   *   The adult's CPR number.
   *
   * @return array[]
   *   Child records (see FamilyRelationsLookupInterface::childrenOf()).
   */
  public function childrenOf(string $parentCpr): array {
    $children = [];
    foreach (self::CHILDREN as $child) {
      foreach ($child['guardians'] as $guardian) {
        if ($guardian['cpr'] === $parentCpr && GuardianType::isCustodial($guardian['type'])) {
          $children[] = $child;
          break;
        }
      }
    }
    return $children;
  }

  /**
   * Returns the custody holders of the given child.
   *
   * @param string $childCpr
   *   The child's CPR number.
   *
   * @return array[]
   *   Guardian records, empty when the CPR is not a known demo child.
   */
  public function guardiansOf(string $childCpr): array {
    return self::CHILDREN[$childCpr]['guardians'] ?? [];
  }

  /**
   * Returns the child's birth date, or NULL when unknown.
   *
   * @param string $childCpr
   *   The child's CPR number.
   *
   * @return \DateTimeImmutable|null
   *   The birth date, or NULL.
   */
  public function birthDateOf(string $childCpr): ?\DateTimeImmutable {
    $date = self::CHILDREN[$childCpr]['birth_date'] ?? NULL;
    return $date === NULL ? NULL : new \DateTimeImmutable($date);
  }

  /**
   * Returns the adult (18+) children of the given person.
   *
   * No custody semantics: an adult child has no guardians, so these records
   * are never returned by childrenOf() and never grant anything. Use for
   * relative-assists scenarios only.
   *
   * @param string $parentCpr
   *   The parent's CPR number.
   *
   * @return array[]
   *   Adult child records with keys cpr, first_name, last_name, full_name,
   *   birth_date, protection, relation ('son' or 'daughter').
   */
  public function adultChildrenOf(string $parentCpr): array {
    return self::ADULT_CHILDREN[$parentCpr] ?? [];
  }

  /**
   * Returns the parents of an adult person, where the demo data knows them.
   *
   * The inverse of adultChildrenOf(): lets a scenario starting from the adult
   * child (Mikkel) find the parent being assisted (Birthe).
   *
   * @param string $childCpr
   *   The adult child's CPR number.
   *
   * @return string[]
   *   The parents' CPR numbers, empty when unknown.
   */
  public function parentsOf(string $childCpr): array {
    $parents = [];
    foreach (self::ADULT_CHILDREN as $parentCpr => $children) {
      foreach ($children as $child) {
        if ($child['cpr'] === $childCpr) {
          $parents[] = (string) $parentCpr;
        }
      }
    }
    return $parents;
  }

}
