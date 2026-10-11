<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Privacy;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds redacting shared tempstores for the ECA token browser.
 *
 * Swapped into eca.token_browser only (see AabenformsCoreServiceProvider), so
 * every other tempstore user on the site is unaffected.
 */
class RedactingSharedTempStoreFactory extends SharedTempStoreFactory {

  /**
   * Constructs the factory.
   */
  public function __construct(
    KeyValueExpirableFactoryInterface $storage_factory,
    LockBackendInterface $lock_backend,
    RequestStack $request_stack,
    AccountProxyInterface $current_user,
    protected PiiRedactor $redactor,
    $expire = 604800,
  ) {
    parent::__construct($storage_factory, $lock_backend, $request_stack, $current_user, $expire);
  }

  /**
   * {@inheritdoc}
   */
  public function get($collection, $owner = NULL) {
    // Owner resolution mirrors the core factory.
    if (!isset($owner)) {
      $owner = $this->currentUser->id();
      if ($this->currentUser->isAnonymous()) {
        $owner = $this->requestStack->getSession()->get('core.tempstore.shared.owner', Crypt::randomBytesBase64());
      }
    }
    $storage = $this->storageFactory->get("tempstore.shared.$collection");
    $store = new RedactingSharedTempStore($storage, $this->lockBackend, $owner, $this->requestStack, $this->currentUser, $this->expire);
    $store->setRedactor($this->redactor);
    return $store;
  }

}
