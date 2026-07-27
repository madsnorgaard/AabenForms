<?php

namespace Drupal\Tests\aabenforms_core\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Asserts every permission the custom modules rely on is actually declared.
 *
 * Drupal does not complain when a route requires a permission that no module
 * declares, nor when a role grants one. It simply becomes ungrantable through
 * /admin/people/permissions, and hasPermission() answers FALSE for everyone
 * except uid 1. That failure mode is silent and reads as working code, which
 * is how two of them survived in this codebase: `administer workflows` guarded
 * eleven routes and was granted by the case_worker role while being declared
 * nowhere, and the decision-state guard checked a permission that never
 * existed, so only its role branch did any work.
 *
 * This test walks the YAML on disk rather than a booted container, so it stays
 * a fast unit test and covers modules that are not installed in any given test
 * fixture.
 *
 * @group aabenforms_core
 */
class PermissionDeclarationTest extends UnitTestCase {

  /**
   * Permissions owned by Drupal core or contrib, which we cannot declare.
   */
  private const EXTERNAL_PERMISSIONS = [
    'access administration pages',
    'access content',
    'administer eca',
    'administer modelers',
    'administer site configuration',
    'administer users',
    'administer webform',
    'edit any webform submission',
    'view any webform submission',
  ];

  /**
   * Absolute path to web/modules/custom.
   *
   * This file lives at
   * custom/aabenforms_core/tests/src/Unit/PermissionDeclarationTest.php, so
   * four levels up is the custom-modules root. The assertion keeps a future
   * move from silently turning every glob below into an empty set.
   */
  private function customModulesDir(): string {
    $dir = dirname(__DIR__, 4);
    $this->assertSame('custom', basename($dir), 'Expected to resolve the custom-modules root; this test file has moved.');
    return $dir;
  }

  /**
   * Every permission declared by a custom module's permissions.yml.
   *
   * @return string[]
   *   The declared permission names.
   */
  private function declaredPermissions(): array {
    $declared = [];
    foreach (glob($this->customModulesDir() . '/*/*.permissions.yml') ?: [] as $file) {
      $data = Yaml::decode((string) file_get_contents($file));
      if (!is_array($data)) {
        continue;
      }
      foreach (array_keys($data) as $name) {
        // Skip the dynamic-callback form ("permission_callbacks:").
        if ($name === 'permission_callbacks') {
          continue;
        }
        $declared[] = (string) $name;
      }
    }
    return $declared;
  }

  /**
   * Permissions required by custom routing, keyed by the route that wants them.
   *
   * @return array<string, string>
   *   Permission name keyed by "<file>:<route id>".
   */
  private function routePermissions(): array {
    $required = [];
    foreach (glob($this->customModulesDir() . '/*/*.routing.yml') ?: [] as $file) {
      $routes = Yaml::decode((string) file_get_contents($file));
      if (!is_array($routes)) {
        continue;
      }
      foreach ($routes as $routeId => $route) {
        $permission = $route['requirements']['_permission'] ?? NULL;
        if (!is_string($permission) || $permission === '') {
          continue;
        }
        // Drupal allows "a+b" (any of) and "a,b" (all of).
        foreach (preg_split('/[+,]/', $permission) ?: [] as $single) {
          $single = trim($single);
          if ($single !== '') {
            $required[basename($file) . ':' . $routeId] = $single;
          }
        }
      }
    }
    return $required;
  }

  /**
   * Every permission string passed to hasPermission() in custom PHP.
   *
   * @return string[]
   *   The permission names.
   */
  private function codePermissions(): array {
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->customModulesDir(), \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      /** @var \SplFileInfo $file */
      if (!in_array($file->getExtension(), ['php', 'module', 'inc', 'install'], TRUE)) {
        continue;
      }
      // Tests legitimately reference permissions they create themselves.
      if (str_contains($file->getPathname(), '/tests/')) {
        continue;
      }
      $source = (string) file_get_contents($file->getPathname());
      if (preg_match_all("/hasPermission\(\s*'([^']+)'/", $source, $matches)) {
        foreach ($matches[1] as $permission) {
          $found[] = $permission;
        }
      }
    }
    return array_unique($found);
  }

  /**
   * Every route permission is declared by a custom module or by core/contrib.
   */
  public function testRoutePermissionsAreDeclared(): void {
    $known = array_merge($this->declaredPermissions(), self::EXTERNAL_PERMISSIONS);
    $routePermissions = $this->routePermissions();

    $this->assertNotEmpty($routePermissions, 'Expected to find routes guarded by a permission.');

    foreach ($routePermissions as $where => $permission) {
      $this->assertContains($permission, $known, sprintf(
        'Route %s requires the permission "%s", which no custom permissions.yml declares and which is not a known core/contrib permission. Declare it, or add it to EXTERNAL_PERMISSIONS if it belongs to core or contrib.',
        $where,
        $permission
      ));
    }
  }

  /**
   * Every hasPermission() check names a permission that exists.
   */
  public function testCodePermissionsAreDeclared(): void {
    $known = array_merge($this->declaredPermissions(), self::EXTERNAL_PERMISSIONS);
    $codePermissions = $this->codePermissions();

    $this->assertNotEmpty($codePermissions, 'Expected to find hasPermission() calls in custom code.');

    foreach ($codePermissions as $permission) {
      $this->assertContains($permission, $known, sprintf(
        'Custom code calls hasPermission("%s"), but no custom permissions.yml declares it. An undeclared permission is FALSE for every user except uid 1, so the check silently does nothing.',
        $permission
      ));
    }
  }

  /**
   * Every permission a shipped role grants exists.
   *
   * A role granting an undeclared permission cannot be managed through the UI
   * and is fragile across Drupal minors on config import.
   */
  public function testExportedRolesGrantOnlyDeclaredPermissions(): void {
    $known = array_merge($this->declaredPermissions(), self::EXTERNAL_PERMISSIONS);
    $configSync = dirname($this->customModulesDir(), 3) . '/config/sync';
    $roleFiles = glob($configSync . '/user.role.*.yml') ?: [];

    $this->assertNotEmpty($roleFiles, 'Expected exported role config in config/sync.');

    foreach ($roleFiles as $file) {
      $role = Yaml::decode((string) file_get_contents($file));
      if (!is_array($role) || ($role['is_admin'] ?? FALSE) === TRUE) {
        // The admin role holds everything implicitly.
        continue;
      }
      foreach ($role['permissions'] ?? [] as $permission) {
        // Only assert on permissions that look like ours; core and contrib
        // ship far more than EXTERNAL_PERMISSIONS can reasonably enumerate.
        if (!str_contains((string) $permission, 'aabenforms') && $permission !== 'administer workflows') {
          continue;
        }
        $this->assertContains($permission, $known, sprintf(
          'Role %s grants "%s", which no custom permissions.yml declares.',
          basename($file),
          $permission
        ));
      }
    }
  }

}
