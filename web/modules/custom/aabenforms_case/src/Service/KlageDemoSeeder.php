<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Service;

use Drupal\aabenforms_case\Entity\AabenformsCase;
use Drupal\aabenforms_core\Service\AuditLogger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformInterface;

/**
 * Seeds the decided (afslag) case the klage demo scenario appeals.
 *
 * Demo scaffolding behind `drush aabenforms:demo:seed-klage-case`. Kept as a
 * service so the behaviour (idempotent, lawful lifecycle walk, --reset when
 * the case has been spent) is kernel-testable without Drush I/O.
 */
class KlageDemoSeeder {

  /**
   * Birthe Jensen (see aabenforms_mitid DemoPersonas), the appellant.
   */
  public const PERSONA_CPR = '2403482210';

  /**
   * The case area the seeded decision belongs to.
   */
  public const CASE_TYPE = 'merudgifter';

  /**
   * Result statuses.
   */
  public const STATUS_CREATED = 'created';
  public const STATUS_EXISTS = 'exists';
  public const STATUS_RESET = 'reset';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FristClock $fristClock,
    protected CaseLookup $caseLookup,
    protected AuditLogger $auditLogger,
    protected TimeInterface $time,
  ) {}

  /**
   * Ensures Birthe has a decided afslag merudgifter case to appeal.
   *
   * - No such case: create one (status "created").
   * - One exists: do nothing (status "exists"), whether or not it is still
   *   appealable - unless $reset is TRUE and none of them is appealable any
   *   more (paaklaget / lukket), in which case a fresh one is created
   *   (status "reset") so the scenario can be run again. An appealable case
   *   is never duplicated, even with $reset.
   *
   * The case is left in "afgoerelse": that is the only state the lawful
   * lifecycle allows an appeal from; "lukket" is terminal.
   *
   * @param int $daysAgo
   *   How many days ago the decision was made.
   * @param bool $reset
   *   Create a fresh case when the existing one(s) are no longer appealable.
   *
   * @return array{status:string, case_id:int, appealable:bool, afgoerelse_dato:int|null, klagefrist:int|null, previous_case_id:int|null}
   *   What happened and the case the scenario should use.
   *
   * @throws \RuntimeException
   *   When the merudgifter webform is not installed.
   */
  public function seed(int $daysAgo = 10, bool $reset = FALSE): array {
    $existing = array_values(array_filter(
      $this->caseLookup->findByCpr(self::PERSONA_CPR),
      static fn (array $c): bool => $c['case_type'] === self::CASE_TYPE && $c['afgoerelse_type'] === 'afslag',
    ));
    $appealable = array_values(array_filter($existing, static fn (array $c): bool => $c['appealable']));

    if ($appealable !== []) {
      return $this->result(self::STATUS_EXISTS, $appealable[0], NULL);
    }
    if ($existing !== [] && !$reset) {
      return $this->result(self::STATUS_EXISTS, $existing[0], NULL);
    }

    $created = $this->createCase(max(0, $daysAgo));
    $status = $existing === [] ? self::STATUS_CREATED : self::STATUS_RESET;
    return $this->result($status, $this->caseLookup->summarize($created), $existing[0]['case_id'] ?? NULL);
  }

  /**
   * Creates the submission and walks the case to an afslag decision.
   */
  protected function createCase(int $daysAgo): AabenformsCase {
    $webform = $this->entityTypeManager->getStorage('webform')->load(self::CASE_TYPE);
    if (!$webform instanceof WebformInterface) {
      throw new \RuntimeException(sprintf('The "%s" webform is not installed; enable the aabenforms_case config first.', self::CASE_TYPE));
    }

    $now = $this->time->getRequestTime();
    $decided = $now - ($daysAgo * 86400);
    $received = $decided - (20 * 86400);

    // The application behind the case. The presave hook encrypts the CPR at
    // rest; the case only ever carries its hash. A bound ECA flow fires on
    // this insert but denies at its MitID gate (no session), so it opens no
    // competing case.
    $submission = $this->entityTypeManager->getStorage('webform_submission')->create([
      'webform_id' => self::CASE_TYPE,
      'in_draft' => FALSE,
      'data' => [
        'applicant_cpr' => self::PERSONA_CPR,
        'funktionsnedsaettelse' => 'Nedsat gangfunktion og behov for hjælpemidler i hjemmet (demo).',
        'maanedlig_merudgift' => 1850,
      ],
    ]);
    $submission->save();

    /** @var \Drupal\aabenforms_case\Entity\AabenformsCase $case */
    $case = $this->entityTypeManager->getStorage('aabenforms_case')->create([
      'title' => 'Merudgifter - ansøgning om dækning af merudgifter (demo)',
      'case_type' => self::CASE_TYPE,
      'status' => 'modtaget',
      'submission_ref' => $submission->id(),
      'modtagelsesdato' => $received,
      'frist_due' => $this->fristClock->computeDue($received, self::CASE_TYPE),
      'subject_cpr_hash' => $this->caseLookup->subjectHashFromSubmission($submission) ?? CaseLookup::hashCpr(self::PERSONA_CPR),
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

    return $case;
  }

  /**
   * Shapes the seed() result from a case summary.
   */
  protected function result(string $status, array $summary, ?int $previous): array {
    $case = $this->entityTypeManager->getStorage('aabenforms_case')->load($summary['case_id']);
    $klagefrist = $case instanceof AabenformsCase ? $case->get('klagefrist')->value : NULL;
    return [
      'status' => $status,
      'case_id' => (int) $summary['case_id'],
      'appealable' => (bool) $summary['appealable'],
      'afgoerelse_dato' => $case instanceof AabenformsCase ? $case->getAfgoerelseDato() : NULL,
      'klagefrist' => $klagefrist === NULL ? NULL : (int) $klagefrist,
      'previous_case_id' => $previous,
    ];
  }

}
