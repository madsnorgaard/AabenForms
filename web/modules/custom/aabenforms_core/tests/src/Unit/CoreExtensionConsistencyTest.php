<?php

namespace Drupal\Tests\aabenforms_core\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Keeps config/sync/core.extension.yml honest about the custom modules.
 *
 * `drush cim` installs exactly what core.extension.yml lists, so a module
 * enabled by hand in a local database and never exported is a module that
 * exists in every deployed environment as dead code. That is not hypothetical:
 * advancedqueue, aabenforms_digital_post_queue and
 * aabenforms_digital_post_beskedfordeler shipped to production on every deploy
 * for weeks without being installed, which meant live Digital Post sends had
 * no outbox, no retry, and nothing to resolve their `pending` state.
 *
 * Nothing surfaced it, because a missing entry is silent in both directions:
 * the deploy succeeds and the dashboard simply omits a card.
 *
 * @group aabenforms_core
 */
class CoreExtensionConsistencyTest extends UnitTestCase {

  /**
   * Custom modules that are deliberately NOT enabled on deploy.
   *
   * Add an entry here with a reason rather than leaving a module silently
   * absent from core.extension.yml.
   *
   * @var array<string, string>
   */
  private const INTENTIONALLY_DISABLED = [];

  /**
   * Absolute path to web/modules/custom.
   */
  private function customModulesDir(): string {
    $dir = dirname(__DIR__, 4);
    $this->assertSame('custom', basename($dir), 'Expected to resolve the custom-modules root; this test file has moved.');
    return $dir;
  }

  /**
   * The module list from config/sync/core.extension.yml.
   *
   * @return array<string, int>
   *   Module name => weight.
   */
  private function enabledModules(): array {
    $file = dirname($this->customModulesDir(), 3) . '/config/sync/core.extension.yml';
    $this->assertFileExists($file);
    $data = Yaml::decode((string) file_get_contents($file));
    $this->assertIsArray($data['module'] ?? NULL, 'core.extension.yml has no module list.');
    return $data['module'];
  }

  /**
   * Every custom module on disk, mapped to its info.yml path.
   *
   * Covers submodules under a parent module's modules/ directory, which is
   * where the Digital Post queue and receipt handlers live.
   *
   * @return array<string, string>
   *   Module machine name => path to its .info.yml.
   */
  private function modulesOnDisk(): array {
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->customModulesDir(), \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      /** @var \SplFileInfo $file */
      if (!str_ends_with($file->getFilename(), '.info.yml')) {
        continue;
      }
      $name = basename($file->getFilename(), '.info.yml');
      // The info file must be named after its directory to be a module root;
      // this skips test fixtures and stray info files.
      if (basename($file->getPath()) !== $name) {
        continue;
      }
      $found[$name] = $file->getPathname();
    }
    return $found;
  }

  /**
   * Every custom module on disk is enabled on deploy, or explicitly excused.
   */
  public function testEveryCustomModuleIsEnabledOnDeploy(): void {
    $enabled = $this->enabledModules();
    $onDisk = $this->modulesOnDisk();

    $this->assertNotEmpty($onDisk, 'Expected to find custom modules on disk.');

    foreach (array_keys($onDisk) as $module) {
      if (isset(self::INTENTIONALLY_DISABLED[$module])) {
        continue;
      }
      $this->assertArrayHasKey($module, $enabled, sprintf(
        'The module "%s" exists in web/modules/custom but config/sync/core.extension.yml does not list it, so drush cim will never install it. Its code will ship to every environment and do nothing. Add it, or add it to INTENTIONALLY_DISABLED with a reason.',
        $module
      ));
    }
  }

  /**
   * Every dependency of an enabled custom module is enabled too.
   *
   * Enabling a module whose dependency is missing makes drush cim fail during
   * the deploy, which leaves code live and config unapplied.
   */
  public function testEnabledModulesHaveTheirDependenciesEnabled(): void {
    $enabled = $this->enabledModules();
    $onDisk = $this->modulesOnDisk();
    $checked = 0;

    foreach ($onDisk as $module => $infoPath) {
      if (!isset($enabled[$module])) {
        continue;
      }
      $info = Yaml::decode((string) file_get_contents($infoPath));
      foreach ($info['dependencies'] ?? [] as $dependency) {
        // Dependencies are declared as "project:module"; the module name is
        // what core.extension lists.
        $name = str_contains((string) $dependency, ':')
          ? substr((string) $dependency, strpos((string) $dependency, ':') + 1)
          : (string) $dependency;
        // Core modules are not listed in core.extension by project name and
        // are always available; only assert on things we could get wrong.
        if (in_array($name, ['drupal'], TRUE)) {
          continue;
        }
        $checked++;
        $this->assertArrayHasKey($name, $enabled, sprintf(
          'Module "%s" is enabled on deploy and depends on "%s", which core.extension.yml does not list. drush cim would fail mid-deploy, leaving code live and config unapplied.',
          $module,
          $name
        ));
      }
    }

    $this->assertGreaterThan(0, $checked, 'Expected to check at least one dependency.');
  }

  /**
   * Every custom module core.extension enables actually exists.
   */
  public function testEnabledCustomModulesExistOnDisk(): void {
    $onDisk = $this->modulesOnDisk();

    foreach (array_keys($this->enabledModules()) as $module) {
      if (!str_starts_with($module, 'aabenforms')) {
        // Contrib and core are not ours to verify from this directory.
        continue;
      }
      // The install profile shares the aabenforms prefix but is not a module.
      if ($module === 'aabenforms') {
        continue;
      }
      $this->assertArrayHasKey($module, $onDisk, sprintf(
        'core.extension.yml enables "%s", but no such module exists under web/modules/custom. drush cim would fail on deploy.',
        $module
      ));
    }
  }

}
