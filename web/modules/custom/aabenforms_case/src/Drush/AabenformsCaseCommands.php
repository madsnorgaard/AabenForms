<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Drush;

use Drupal\aabenforms_case\Service\CaseLookup;
use Drupal\aabenforms_case\Service\FristClock;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\Entity\Webform;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ÅbenForms case demo harness.
 */
final class AabenformsCaseCommands extends DrushCommands {

  /**
   * The persona the klage scenario is built around (see DemoPersonas).
   */
  private const KAREN_CPR = '2403482210';

  /**
   * The case area the seeded decision belongs to.
   */
  private const CASE_TYPE = 'merudgifter';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FristClock $fristClock,
    private readonly CaseLookup $caseLookup,
    private readonly AuditLogger $auditLogger,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Seed a decided (afslag) merudgifter case for Karen so klage has a target.
   *
   * Creates, for the demo persona Karen Christensen, a merudgifter case that
   * went modtaget -> oplyst -> afgoerelse with afslag, decided 10 days ago,
   * with the klagefrist computed by the FristClock from that date. The case
   * is left in "afgoerelse" (decided), which is the only state the lawful
   * lifecycle allows an appeal from - a "lukket" case is terminal and could
   * not be appealed. Idempotent: a second run finds the existing case by
   * Karen's subject hash and does nothing.
   *
   * @param array $options
   *   Command options.
   *
   * @command aabenforms:demo:seed-klage-case
   * @aliases af:seed-klage
   * @option days-ago How many days ago the decision was made (default 10).
   * @usage drush aabenforms:demo:seed-klage-case
   *   Seed the case and print its id and klagefrist.
   */
  public function seedKlageCase(array $options = ['days-ago' => 10]): int {
    $daysAgo = max(0, (int) $options['days-ago']);

    foreach ($this->caseLookup->findByCpr(self::KAREN_CPR) as $existing) {
      if ($existing['case_type'] === self::CASE_TYPE && $existing['afgoerelse_type'] === 'afslag') {
        $this->io()->success(dt('Karen already has a decided merudgifter case: #@id (klagefrist @frist). Nothing to do.', [
          '@id' => $existing['case_id'],
          '@frist' => $existing['klagefrist'] ?? '-',
        ]));
        return self::EXIT_SUCCESS;
      }
    }

    $webform = Webform::load(self::CASE_TYPE);
    if ($webform === NULL) {
      $this->logger()->error(dt('The "@id" webform is not installed; enable aabenforms_case config first.', ['@id' => self::CASE_TYPE]));
      return self::EXIT_FAILURE;
    }

    $now = $this->time->getRequestTime();
    $decided = $now - ($daysAgo * 86400);
    $received = $decided - (20 * 86400);

    try {
      // The application behind the case. The presave hook encrypts the CPR
      // at rest; the case only ever carries its hash.
      $submission = $this->entityTypeManager->getStorage('webform_submission')->create([
        'webform_id' => self::CASE_TYPE,
        'in_draft' => FALSE,
        'data' => [
          'applicant_cpr' => self::KAREN_CPR,
          'funktionsnedsaettelse' => 'Nedsat gangfunktion og behov for hjælpemidler i hjemmet (demo).',
          'maanedlig_merudgift' => 1850,
        ],
      ]);
      $submission->save();

      $storage = $this->entityTypeManager->getStorage('aabenforms_case');
      /** @var \Drupal\aabenforms_case\Entity\AabenformsCase $case */
      $case = $storage->create([
        'title' => 'Merudgifter - ansøgning om dækning af merudgifter (demo)',
        'case_type' => self::CASE_TYPE,
        'status' => 'modtaget',
        'submission_ref' => $submission->id(),
        'modtagelsesdato' => $received,
        'frist_due' => $this->fristClock->computeDue($received, self::CASE_TYPE),
        'subject_cpr_hash' => $this->caseLookup->subjectHashFromSubmission($submission) ?? CaseLookup::hashCpr(self::KAREN_CPR),
      ]);
      $case->setNewRevision(TRUE);
      $case->setRevisionLogMessage('Sag oprettet fra indsendelse (demo-seed).');
      $case->save();

      // Walk the lawful lifecycle so the revision trail looks like real
      // casework: oplyst, then an adverse decision with klagevejledning.
      $case->setStatus('oplyst');
      $case->setRevisionLogMessage('Sagen er oplyst: lægefaglig dokumentation modtaget (demo-seed).');
      $case->save();

      $case->set('afgoerelse_type', 'afslag');
      $case->set('afgoerelse_dato', $decided);
      $case->set('partshoering_state', 'afsluttet');
      $case->set('klagefrist', $this->fristClock->computeKlagefrist($decided, 4));
      $case->setStatus('afgoerelse');
      $case->setRevisionLogMessage('Afgørelse: afslag. Klagevejledning: Afgørelsen kan påklages til Ankestyrelsen inden 4 uger (demo-seed).');
      $case->save();

      $this->auditLogger->log('case_decision', (string) $case->id(), 'afslag', 'success', [
        'demo_seed' => TRUE,
        'command' => 'aabenforms:demo:seed-klage-case',
      ]);
    }
    catch (\Throwable $e) {
      $this->logger()->error(dt('Seeding failed: @message', ['@message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }

    $this->io()->success(dt('Seeded case #@id for Karen Christensen: @type, afslag decided @decided, klagefrist @frist.', [
      '@id' => $case->id(),
      '@type' => self::CASE_TYPE,
      '@decided' => date('Y-m-d', $decided),
      '@frist' => date('Y-m-d', (int) $case->get('klagefrist')->value),
    ]));
    $this->io()->writeln(dt('Seed a MitID session for Karen with: drush aabenforms:seed-mitid-session karen'));
    $this->io()->writeln(dt('Then: curl "$BASE/api/prefill/klage?session=<workflow id>"'));
    return self::EXIT_SUCCESS;
  }

}
