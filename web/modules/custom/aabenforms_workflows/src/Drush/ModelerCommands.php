<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Drush;

use Drupal\aabenforms_workflows\EcaModeler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for keeping ECA flows owned by the workflow modeler.
 *
 * The ownership rule itself lives in EcaModeler. This command is the repair
 * path: some ECA save paths, and editing a flow in BPMN.iO, strip the
 * `modeler_api.modeler_id` setting, so a flow can regress to the fallback
 * editor even though config/sync declares the modeler. `af:modeler-adopt`
 * re-asserts it across every flow; it is idempotent and safe to run as a
 * post-deploy step.
 *
 * It is no longer needed after creating a workflow through the wizard.
 * WorkflowTemplateInstantiator stamps ownership at creation, so a fresh
 * wizard build already reports as adopted.
 */
final class ModelerCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Ensure every ECA flow is owned by the React workflow modeler.
   *
   * Sets the `modeler_api.modeler_id` third-party setting to workflow_modeler
   * on any flow that is missing it (i.e. showing as "Fallback" / editable only
   * via BPMN.iO). Idempotent: flows already owned by the modeler are left
   * untouched.
   *
   * @command aabenforms:modeler:adopt
   * @aliases af:modeler-adopt
   * @usage drush aabenforms:modeler:adopt
   *   Repair every flow that has fallen back to the BPMN.iO editor.
   */
  public function adopt(): int {
    $storage = $this->entityTypeManager->getStorage('eca');
    /** @var \Drupal\Core\Config\Entity\ThirdPartySettingsInterface[] $flows */
    $flows = $storage->loadMultiple();

    $fixed = [];
    foreach ($flows as $id => $flow) {
      if (EcaModeler::isOwned($flow)) {
        continue;
      }
      EcaModeler::stamp($flow);
      $flow->save();
      $fixed[] = $id;
    }

    if ($fixed === []) {
      $this->io()->success(dt('All @count flows already use the workflow modeler.', [
        '@count' => count($flows),
      ]));
      return self::EXIT_SUCCESS;
    }

    $this->io()->success(dt('Adopted the workflow modeler on @n flow(s):', ['@n' => count($fixed)]));
    $this->io()->listing($fixed);
    return self::EXIT_SUCCESS;
  }

}
