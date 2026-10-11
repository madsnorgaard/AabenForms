<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Demo;

/**
 * Decides where demo scaffolding (seeders, demo accounts) may run.
 *
 * Demo code is production code, so demo commands are present everywhere and
 * must refuse outside a demo environment. Two tiers:
 * - isDemoAllowed(): local DDEV, or a site that explicitly opts in with
 *   AABENFORMS_DEMO_SITE=1 (the public demo at aabenforms.dk). For demo
 *   records and demo staff accounts.
 * - isLocalDevelopment(): local DDEV only. For anything that mints a verified
 *   identity or a capability (seeded MitID sessions, approval tokens): a
 *   seeded identity must never exist on a public site, demo or not.
 */
final class DemoEnvironment {

  /**
   * The environment variable that opts a non-DDEV site into demo commands.
   */
  public const OPT_IN_VARIABLE = 'AABENFORMS_DEMO_SITE';

  /**
   * Whether this process runs inside a local DDEV container.
   */
  public function isLocalDevelopment(): bool {
    return (string) getenv('DDEV_HOSTNAME') !== '';
  }

  /**
   * Whether demo data commands may run here.
   */
  public function isDemoAllowed(): bool {
    return $this->isLocalDevelopment() || getenv(self::OPT_IN_VARIABLE) === '1';
  }

  /**
   * The refusal message for a demo command run outside a demo environment.
   *
   * @param string $command
   *   The Drush command name.
   *
   * @return string
   *   A message naming the opt-in variable.
   */
  public function refusalMessage(string $command): string {
    return sprintf('%s is a demo command and is disabled on this site. It runs only under DDEV, or on a demo site that sets %s=1.', $command, self::OPT_IN_VARIABLE);
  }

  /**
   * The refusal message for a DDEV-only command.
   *
   * @param string $command
   *   The Drush command name.
   *
   * @return string
   *   A message explaining that the opt-in variable does not apply.
   */
  public function localOnlyRefusalMessage(string $command): string {
    return sprintf('%s mints a test identity or capability and runs only under local DDEV. %s=1 does not enable it: seeded identities must never exist on a public site.', $command, self::OPT_IN_VARIABLE);
  }

}
