<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Traits;

use Drupal\aabenforms_core\Demo\DemoEnvironment;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Simulates production, DDEV and the opted-in demo site with putenv().
 */
trait DemoEnvironmentTrait {

  /**
   * The environment as it was before the test.
   */
  protected array $savedDemoEnv = [];

  /**
   * Switches the process environment.
   *
   * @param string $mode
   *   One of 'production', 'ddev' or 'demo_site'.
   */
  protected function setDemoEnvironment(string $mode): void {
    foreach (['DDEV_HOSTNAME', DemoEnvironment::OPT_IN_VARIABLE] as $name) {
      if (!array_key_exists($name, $this->savedDemoEnv)) {
        $this->savedDemoEnv[$name] = getenv($name);
      }
      putenv($name);
    }
    if ($mode === 'ddev') {
      putenv('DDEV_HOSTNAME=aabenforms.ddev.site');
    }
    elseif ($mode === 'demo_site') {
      putenv(DemoEnvironment::OPT_IN_VARIABLE . '=1');
    }
  }

  /**
   * Restores the environment saved by setDemoEnvironment().
   */
  protected function restoreDemoEnvironment(): void {
    foreach ($this->savedDemoEnv as $name => $value) {
      putenv($value === FALSE ? $name : "$name=$value");
    }
    $this->savedDemoEnv = [];
  }

  /**
   * Gives a Drush command a buffered output and returns the buffer.
   *
   * @param object $command
   *   A DrushCommands instance.
   *
   * @return \Symfony\Component\Console\Output\BufferedOutput
   *   The output buffer.
   */
  protected function bufferCommandOutput(object $command): BufferedOutput {
    $output = new BufferedOutput();
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    return $output;
  }

}
