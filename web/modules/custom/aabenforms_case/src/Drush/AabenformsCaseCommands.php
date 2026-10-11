<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Drush;

use Drupal\aabenforms_case\Service\KlageDemoSeeder;
use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ÅbenForms case demo harness.
 */
final class AabenformsCaseCommands extends DrushCommands {

  public function __construct(
    private readonly KlageDemoSeeder $seeder,
    private readonly DemoEnvironment $demoEnvironment,
  ) {
    parent::__construct();
  }

  /**
   * Seed a decided (afslag) merudgifter case for Birthe so klage has a target.
   *
   * Creates, for the demo persona Birthe Jensen, a merudgifter case that
   * went modtaget -> oplyst -> afgoerelse with afslag, decided 10 days ago,
   * with the klagefrist computed by the FristClock from that date. The case
   * is left in "afgoerelse" (decided), which is the only state the lawful
   * lifecycle allows an appeal from - a "lukket" case is terminal and could
   * not be appealed. Idempotent: a second run finds the existing case by
   * Birthe's subject hash and does nothing. Once the scenario has been run
   * (the case is paaklaget or lukket), --reset seeds a fresh one.
   *
   * @param array $options
   *   Command options.
   *
   * @command aabenforms:demo:seed-klage-case
   * @aliases af:seed-klage
   * @option days-ago How many days ago the decision was made (default 10).
   * @option reset Create a fresh case when the existing one is no longer
   *   appealable (paaklaget / lukket). Never duplicates an appealable case.
   * @usage drush aabenforms:demo:seed-klage-case
   *   Seed the case and print its id and klagefrist.
   * @usage drush aabenforms:demo:seed-klage-case --reset
   *   After running the klage scenario, seed a fresh case to run it again.
   */
  public function seedKlageCase(array $options = ['days-ago' => 10, 'reset' => FALSE]): int {
    if (!$this->demoEnvironment->isDemoAllowed()) {
      $this->io()->error($this->demoEnvironment->refusalMessage('aabenforms:demo:seed-klage-case'));
      return self::EXIT_FAILURE;
    }
    try {
      $result = $this->seeder->seed((int) $options['days-ago'], (bool) $options['reset']);
    }
    catch (\Throwable $e) {
      $this->logger()->error(dt('Seeding failed: @message', ['@message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }

    $frist = $result['klagefrist'] === NULL ? '-' : date('Y-m-d', $result['klagefrist']);
    switch ($result['status']) {
      case KlageDemoSeeder::STATUS_EXISTS:
        if ($result['appealable']) {
          $this->io()->success(dt('Birthe already has a decided merudgifter case: #@id (klagefrist @frist). Nothing to do.', [
            '@id' => $result['case_id'],
            '@frist' => $frist,
          ]));
        }
        else {
          $this->io()->warning(dt('Birthe\'s merudgifter case #@id is no longer appealable (already appealed or closed). Re-run with --reset to seed a fresh one.', [
            '@id' => $result['case_id'],
          ]));
        }
        break;

      case KlageDemoSeeder::STATUS_RESET:
        $this->io()->success(dt('Case #@previous was spent; seeded fresh case #@id for Birthe Jensen: afslag decided @decided, klagefrist @frist.', [
          '@previous' => $result['previous_case_id'],
          '@id' => $result['case_id'],
          '@decided' => date('Y-m-d', (int) $result['afgoerelse_dato']),
          '@frist' => $frist,
        ]));
        break;

      default:
        $this->io()->success(dt('Seeded case #@id for Birthe Jensen: merudgifter, afslag decided @decided, klagefrist @frist.', [
          '@id' => $result['case_id'],
          '@decided' => date('Y-m-d', (int) $result['afgoerelse_dato']),
          '@frist' => $frist,
        ]));
    }
    $this->io()->writeln(dt('Seed a MitID session for Birthe with: drush aabenforms:seed-mitid-session birthe'));
    $this->io()->writeln(dt('Then: curl -H "Authorization: Bearer <workflow id>" "$BASE/api/prefill/klage"'));
    return self::EXIT_SUCCESS;
  }

}
