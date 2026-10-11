<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Privacy;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds redacting private tempstores for the ECA token browser.
 *
 * Swapped into eca.token_browser only (see AabenformsCoreServiceProvider).
 */
class RedactingPrivateTempStoreFactory extends PrivateTempStoreFactory {

  /**
   * Constructs the factory.
   */
  public function __construct(
    KeyValueExpirableFactoryInterface $storage_factory,
    LockBackendInterface $lock_backend,
    AccountProxyInterface $current_user,
    RequestStack $request_stack,
    protected PiiRedactor $redactor,
    $expire = 604800,
  ) {
    parent::__construct($storage_factory, $lock_backend, $current_user, $request_stack, $expire);
  }

  /**
   * {@inheritdoc}
   */
  public function get($collection) {
    $storage = $this->storageFactory->get("tempstore.private.$collection");
    $store = new RedactingPrivateTempStore($storage, $this->lockBackend, $this->currentUser, $this->requestStack, $this->expire);
    $store->setRedactor($this->redactor);
    return $store;
  }

}
