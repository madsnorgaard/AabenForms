<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Drush;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Drupal\aabenforms_workflows\Service\OrgChartServiceInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Ensures the school leader staff account for the skoleskift decision.
 */
final class SchoolLeaderCommands extends DrushCommands {

  public const ROLE = 'skoleleder';
  public const PERMISSIONS = ['decide school transfer'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OrgChartServiceInterface $orgChart,
    private readonly DemoEnvironment $demoEnvironment,
  ) {
    parent::__construct();
  }

  /**
   * Creates or updates the school leader role and user (idempotent).
   *
   * The role gets only "decide school transfer" (no admin permissions). The
   * user must also be an employee in aabenforms_workflows.org_chart (config
   * sync ships E1003 for account "skoleleder"), or the decision flow's
   * aabenforms_resolve_employee gate denies the decision. Its
   * institution_numbers decide which school's cases the leader sees (E1003:
   * Risskov Skole, 751104).
   *
   * @command aabenforms:demo:ensure-skoleleder
   * @option name Account name (default skoleleder).
   * @option mail E-mail address (default skoleleder@example.dk).
   * @option password Set this password. Without it a new account gets a
   *   random password (printed once) and an existing one keeps its own.
   * @usage drush aabenforms:demo:ensure-skoleleder --password=test1234
   *   DDEV demo: the school leader logs in as skoleleder / test1234.
   */
  public function ensureSkoleleder(
    array $options = [
      'name' => 'skoleleder',
      'mail' => 'skoleleder@example.dk',
      'password' => NULL,
    ],
  ): int {
    if (!$this->demoEnvironment->isDemoAllowed()) {
      $this->io()->error($this->demoEnvironment->refusalMessage('aabenforms:demo:ensure-skoleleder'));
      return self::EXIT_FAILURE;
    }
    $name = (string) $options['name'];
    $mail = (string) $options['mail'];

    $roleStorage = $this->entityTypeManager->getStorage('user_role');
    $role = $roleStorage->load(self::ROLE) ?? $roleStorage->create(['id' => self::ROLE, 'label' => 'Skoleleder']);
    foreach ($role->getPermissions() as $permission) {
      if (!in_array($permission, self::PERMISSIONS, TRUE)) {
        $role->revokePermission($permission);
      }
    }
    foreach (self::PERMISSIONS as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();

    $userStorage = $this->entityTypeManager->getStorage('user');
    $accounts = $userStorage->loadByProperties(['name' => $name]);
    /** @var \Drupal\user\UserInterface $user */
    $user = $accounts ? reset($accounts) : $userStorage->create(['name' => $name]);
    $isNew = $user->isNew();
    $password = $options['password'] !== NULL ? (string) $options['password'] : NULL;
    if ($password === NULL && $isNew) {
      $password = bin2hex(random_bytes(12));
      $this->io()->note(dt('Generated password for @name: @password', ['@name' => $name, '@password' => $password]));
    }
    if ($password !== NULL) {
      $user->setPassword($password);
    }
    $user->setEmail($mail);
    $user->set('preferred_langcode', 'da');
    $user->set('preferred_admin_langcode', 'da');
    $user->activate();
    $user->addRole(self::ROLE);
    $user->save();

    $this->io()->success(dt('@action school leader @name (uid @uid) with role @role.', [
      '@action' => $isNew ? 'Created' : 'Updated',
      '@name' => $name,
      '@uid' => $user->id(),
      '@role' => self::ROLE,
    ]));

    $employeeId = $this->orgChart->employeeIdForAccountName($name);
    if ($employeeId === '') {
      $this->io()->warning(dt('@name is not in the org chart (aabenforms_workflows.org_chart), so the decision flow will deny the decision. Import config or add the employee.', ['@name' => $name]));
      return self::EXIT_FAILURE;
    }
    if ($this->orgChart->institutionNumbers($employeeId) === []) {
      $this->io()->warning(dt('@name has no institution_numbers in the org chart, so the case list stays empty. Bind the employee to a school.', ['@name' => $name]));
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

}
