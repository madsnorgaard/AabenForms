<?php

declare(strict_types=1);

namespace Drupal\aabenforms_mitid\Drush;

use Drupal\aabenforms_mitid\DemoPersonas;
use Drupal\aabenforms_mitid\Service\MitIdSessionManager;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ÅbenForms MitID demo harness.
 *
 * Provides af:seed, which seeds a demo-citizen MitID session so a gated flow
 * can be tested through its happy path without a live authentication.
 */
final class AabenformsMitidCommands extends DrushCommands {

  public function __construct(
    private readonly MitIdSessionManager $sessionManager,
  ) {
    parent::__construct();
  }

  /**
   * Seed a MitID session for a demo citizen so a gated flow can be tested.
   *
   * MitID-gated flows deny by default with no verified session (fail-closed).
   * This mints a session for one of the demo personas, letting you exercise a
   * flow's happy path without a live MitID/NemLog-in authentication. Pass the
   * printed workflow id to the flow's workflow_id token, or use the modeler
   * "Test as demo citizen" action which binds it to your browser session.
   *
   * @param string $persona
   *   Persona slug: freja, mikkel, sofie, lars or birthe (elderly, Digital
   *   Post exempt). Use 'list' to see them all.
   * @param array $options
   *   Command options.
   *
   * @command aabenforms:seed-mitid-session
   * @aliases af:seed
   * @option workflow-id Store the session under this exact workflow id instead
   *   of a random one.
   * @usage drush aabenforms:seed-mitid-session freja
   *   Seed Freja Nielsen and print the workflow id.
   * @usage drush aabenforms:seed-mitid-session birthe
   *   Seed Birthe Jensen for the klage / digital-inclusion scenario.
   * @usage drush aabenforms:seed-mitid-session list
   *   List the available demo personas.
   */
  public function seedMitidSession(string $persona, array $options = ['workflow-id' => NULL]): int {
    if ($persona === 'list') {
      $rows = [];
      foreach (DemoPersonas::all() as $slug => $p) {
        $rows[] = [
          $slug,
          $p['name'],
          $p['cpr'],
          $p['assurance_level'],
          !empty($p['digital_post_exempt']) ? 'yes' : 'no',
        ];
      }
      $this->io()->table(['Slug', 'Name', 'CPR', 'Assurance', 'Digital Post exempt'], $rows);
      return self::EXIT_SUCCESS;
    }

    if (!DemoPersonas::exists($persona)) {
      $this->logger()->error(dt('Unknown persona "@p". Known: @list.', [
        '@p' => $persona,
        '@list' => implode(', ', DemoPersonas::keys()),
      ]));
      return self::EXIT_FAILURE;
    }

    $workflowId = $this->sessionManager->seedDemoSession($persona, $options['workflow-id'] ?: NULL);
    if ($workflowId === NULL) {
      $this->logger()->error(dt('Failed to seed session for "@p".', ['@p' => $persona]));
      return self::EXIT_FAILURE;
    }

    $data = DemoPersonas::get($persona);
    $this->io()->success(dt('Seeded MitID session for @name (CPR @cpr).', [
      '@name' => $data['name'],
      '@cpr' => $data['cpr'],
    ]));
    $this->io()->writeln(dt('Workflow id: @id', ['@id' => $workflowId]));
    $this->io()->writeln(dt('Valid for 15 minutes. Set the flow\'s workflow_id token to this value to pass the gate.'));
    return self::EXIT_SUCCESS;
  }

}
