<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Routes the ECA replay store through PII-redacting tempstores (#74).
 *
 * While ECA replay is armed every workflow run records its token data -
 * including citizen CPR and client IP - into a cross-user shared tempstore.
 * eca.token_browser is a final class, so instead of decorating it the two
 * tempstore factories it receives are swapped for redacting ones. Nothing
 * else on the site uses these factories.
 */
class AabenformsCoreServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('eca.token_browser')) {
      return;
    }
    $definition = $container->getDefinition('eca.token_browser');
    foreach ($definition->getArguments() as $index => $argument) {
      if (!$argument instanceof Reference) {
        continue;
      }
      $id = (string) $argument;
      if ($id === 'tempstore.private') {
        $definition->replaceArgument($index, new Reference('aabenforms_core.redacting_tempstore.private'));
      }
      elseif ($id === 'tempstore.shared') {
        $definition->replaceArgument($index, new Reference('aabenforms_core.redacting_tempstore.shared'));
      }
    }
  }

}
