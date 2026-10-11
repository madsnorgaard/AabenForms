<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Retention;

use Drupal\aabenforms_core\Privacy\PiiRedactor;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Legal holds (retsligt tilbageholdelse) that block retention and erasure.
 *
 * Why a keyvalue list and not a legal_hold base field on aabenforms_case: a
 * closed case is immutable (AabenformsCase::preSave() throws on any change to
 * a lukket case), yet "closed but disputed" is exactly when a hold is placed.
 * A base field would force either a bypass of that guard or a direct SQL
 * write past the entity API. A hold is also not casework: it must not mint a
 * case revision, and it must be able to cover a submission that never became
 * a case. The non-expirable keyvalue store (the core API for small,
 * module-owned state that is not configuration) keeps the hold beside the
 * record without touching it, survives cache clears and config import, and
 * is read by id in one call.
 *
 * Keys are "case:<id>" or "submission:<sid>". Values carry who placed the
 * hold, when, and a PII-redacted reason.
 */
class LegalHoldStore {

  /**
   * The keyvalue collection.
   */
  public const COLLECTION = 'aabenforms_core.legal_hold';

  /**
   * Target type for a case.
   */
  public const CASE = 'case';

  /**
   * Target type for a webform submission.
   */
  public const SUBMISSION = 'submission';

  /**
   * The keyvalue store.
   */
  protected KeyValueStoreInterface $store;

  public function __construct(
    KeyValueFactoryInterface $keyValueFactory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly TimeInterface $time,
    protected readonly PiiRedactor $redactor,
  ) {
    $this->store = $keyValueFactory->get(self::COLLECTION);
  }

  /**
   * Places a hold.
   *
   * @param string $type
   *   self::CASE or self::SUBMISSION.
   * @param int $id
   *   The entity id.
   * @param string $reason
   *   Why the hold is placed. CPR numbers are masked before storage.
   */
  public function place(string $type, int $id, string $reason): void {
    $this->store->set(self::key($type, $id), [
      'uid' => (int) $this->currentUser->id(),
      'created' => $this->time->getRequestTime(),
      'reason' => mb_substr($this->redactor->redactString(trim($reason)), 0, 255),
    ]);
  }

  /**
   * Releases a hold. Returns FALSE when there was none.
   */
  public function release(string $type, int $id): bool {
    $key = self::key($type, $id);
    if (!$this->store->has($key)) {
      return FALSE;
    }
    $this->store->delete($key);
    return TRUE;
  }

  /**
   * Whether the target is held.
   */
  public function isHeld(string $type, int $id): bool {
    return $this->store->has(self::key($type, $id));
  }

  /**
   * Returns the hold record, or NULL.
   *
   * @return array{uid: int, created: int, reason: string}|null
   *   The hold.
   */
  public function get(string $type, int $id): ?array {
    $value = $this->store->get(self::key($type, $id));
    return is_array($value) ? $value : NULL;
  }

  /**
   * Which of the given ids of one type are held.
   *
   * @param string $type
   *   self::CASE or self::SUBMISSION.
   * @param int[] $ids
   *   Entity ids.
   *
   * @return int[]
   *   The held ids.
   */
  public function heldAmong(string $type, array $ids): array {
    if ($ids === []) {
      return [];
    }
    $keys = array_map(static fn (int $id): string => self::key($type, $id), $ids);
    $found = $this->store->getMultiple($keys);
    $held = [];
    foreach ($ids as $id) {
      if (array_key_exists(self::key($type, $id), $found)) {
        $held[] = $id;
      }
    }
    return $held;
  }

  /**
   * Builds the store key, refusing unknown types.
   */
  protected static function key(string $type, int $id): string {
    if (!in_array($type, [self::CASE, self::SUBMISSION], TRUE) || $id < 1) {
      throw new \InvalidArgumentException('Invalid legal hold target.');
    }
    return $type . ':' . $id;
  }

}
