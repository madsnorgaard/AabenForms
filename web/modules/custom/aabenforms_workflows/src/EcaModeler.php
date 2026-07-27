<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;

/**
 * Ownership of ECA flows by the React Workflow Modeler.
 *
 * An ECA flow renders in the Workflow Modeler only while its config carries
 * the `modeler_api.modeler_id` third-party setting. ModelOwnerBase defaults a
 * missing setting to "fallback", which silently drops the flow to the BPMN.iO
 * editor. Two places need the same rule, so it lives here rather than being
 * written twice: the wizard stamps it at creation, and the af:modeler-adopt
 * Drush command re-asserts it on flows that regressed (editing a flow in
 * BPMN.iO strips the setting).
 */
final class EcaModeler {

  /**
   * The modeler id every ÅbenForms flow should be owned by.
   */
  public const ID = 'workflow_modeler';

  /**
   * Marks a flow as owned by the Workflow Modeler.
   *
   * The label is a modeler-only concern: `label` is not part of the Eca config
   * entity's exportable properties, so the human-readable name for the modeler
   * list belongs in the same third-party namespace as the owner id.
   *
   * @param \Drupal\Core\Config\Entity\ThirdPartySettingsInterface $flow
   *   The ECA flow config entity.
   * @param string $label
   *   The label to show in the modeler list. Falls back to the flow id when
   *   empty, so a row is never blank.
   */
  public static function stamp(ThirdPartySettingsInterface $flow, string $label = ''): void {
    $flow->setThirdPartySetting('modeler_api', 'modeler_id', self::ID);

    if ($label === '') {
      $label = $flow->getThirdPartySetting('modeler_api', 'label', '');
    }
    if ($label === '' && method_exists($flow, 'id')) {
      $label = (string) $flow->id();
    }
    if ($label !== '') {
      $flow->setThirdPartySetting('modeler_api', 'label', $label);
    }
  }

  /**
   * Whether the flow is already owned by the Workflow Modeler.
   *
   * @param \Drupal\Core\Config\Entity\ThirdPartySettingsInterface $flow
   *   The ECA flow config entity.
   *
   * @return bool
   *   TRUE when no stamping is needed.
   */
  public static function isOwned(ThirdPartySettingsInterface $flow): bool {
    return $flow->getThirdPartySetting('modeler_api', 'modeler_id', 'fallback') === self::ID;
  }

}
