<?php

declare(strict_types=1);

namespace Drupal\aabenforms_institution\Drush;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aabenforms_institution\Entity\AabenformsInstitution;
use Drupal\aabenforms_institution\Service\InstitutionRegistry;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the institution registry.
 *
 * Af:inst:seed  import institutions from a CSV extract
 * af:inst:list  show the registry.
 */
final class InstitutionCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly InstitutionRegistry $registry,
  ) {
    parent::__construct();
  }

  /**
   * Seed the institution registry from a CSV file.
   *
   * The expected columns mirror the bundled demo fixture:
   * institution_number,name,type,district,leader_name,leader_email,
   * parent_number,active. Existing rows (matched on institution_number)
   * are updated, new ones created; parents are linked in a second pass so
   * row order does not matter. Data source for a real municipality: the
   * active-institutions extract from data.stil.dk (Institutionsregisteret).
   *
   * @command aabenforms:institution:seed
   * @aliases af:inst:seed
   * @option file Path to the CSV file. Defaults to the bundled Aarhus demo fixture.
   * @usage drush af:inst:seed
   *   Seed the bundled Aarhus demo institutions.
   * @usage drush af:inst:seed --file=/tmp/institutions.csv
   *   Seed from a data.stil.dk extract.
   */
  public function seed(array $options = ['file' => NULL]): void {
    $file = $options['file'] ?? \Drupal::service('extension.list.module')->getPath('aabenforms_institution') . '/fixtures/aarhus_demo_institutions.csv';
    if (!is_readable($file)) {
      throw new \RuntimeException(sprintf('CSV file not readable: %s', $file));
    }

    $handle = fopen($file, 'r');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Could not open: %s', $file));
    }

    $header = fgetcsv($handle, escape: '\\');
    if ($header === FALSE) {
      fclose($handle);
      throw new \RuntimeException('CSV file is empty.');
    }

    $storage = $this->entityTypeManager->getStorage('aabenforms_institution');
    $parentLinks = [];
    $created = 0;
    $updated = 0;

    while (($row = fgetcsv($handle, escape: '\\')) !== FALSE) {
      // Skip blank lines (fgetcsv yields [NULL]) and ragged rows instead of
      // crashing mid-import and losing the whole parent-linking pass.
      if ($row === [NULL] || count($row) !== count($header)) {
        if ($row !== [NULL]) {
          $this->logger()->warning(sprintf('Skipped ragged CSV row (%d columns, expected %d).', count($row), count($header)));
        }
        continue;
      }
      $data = array_combine($header, $row);
      $number = trim((string) $data['institution_number']);
      if ($number === '') {
        continue;
      }

      $institution = $this->registry->findByNumber($number);
      if ($institution === NULL) {
        $institution = $storage->create(['institution_number' => $number]);
        $created++;
      }
      else {
        $updated++;
      }

      $institution->set('name', trim((string) $data['name']));
      $institution->set('type', trim((string) $data['type']) ?: AabenformsInstitution::TYPE_SKOLE);
      $institution->set('district', trim((string) ($data['district'] ?? '')));
      $institution->set('leader_name', trim((string) ($data['leader_name'] ?? '')));
      $institution->set('leader_email', trim((string) ($data['leader_email'] ?? '')));
      $institution->set('active', trim((string) ($data['active'] ?? '1')) !== '0');
      $institution->save();

      $parentNumber = trim((string) ($data['parent_number'] ?? ''));
      if ($parentNumber !== '') {
        $parentLinks[$number] = $parentNumber;
      }
      else {
        // A re-import whose row no longer names a parent must clear a stale
        // link, not silently keep the old hierarchy.
        $institution->set('parent_institution', NULL);
        $institution->save();
      }
    }
    fclose($handle);

    // Second pass: link parents now that every row exists. Numeric string
    // array keys degrade to ints in PHP, so cast both back to strings.
    foreach ($parentLinks as $childNumber => $parentNumber) {
      $child = $this->registry->findByNumber((string) $childNumber);
      $parent = $this->registry->findByNumber((string) $parentNumber);
      if ($child !== NULL && $parent !== NULL) {
        $child->set('parent_institution', $parent->id());
        $child->save();
      }
      else {
        $this->logger()->warning(sprintf('Parent %s for institution %s not found; link skipped.', $parentNumber, $childNumber));
      }
    }

    $this->logger()->success(sprintf('Institutions seeded: %d created, %d updated.', $created, $updated));
  }

  /**
   * List the institution registry.
   *
   * @command aabenforms:institution:list
   * @aliases af:inst:list
   * @field-labels
   *   number: Number
   *   name: Name
   *   type: Type
   *   district: District
   *   leader: Leader email
   *   active: Active
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   The registry rows.
   */
  public function listInstitutions() {
    $rows = [];
    foreach ($this->registry->listActive() as $institution) {
      $rows[] = [
        'number' => $institution->getInstitutionNumber(),
        'name' => $institution->label(),
        'type' => $institution->getType(),
        'district' => $institution->getDistrict(),
        'leader' => $institution->getLeaderEmail(),
        'active' => $institution->isActive() ? 'yes' : 'no',
      ];
    }
    return new RowsOfFields($rows);
  }

}
