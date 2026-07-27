<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\DigitalPost;

/**
 * The outcome of resolving Digital Post recipients for a child.
 *
 * Danish law (in force since 21 March 2022) requires post concerning a joint
 * child to reach BOTH custody holders, while young people aged 15+ are
 * enrolled in Digital Post themselves and receive authority mail directly.
 * This DTO carries which rule applied so flows can branch and audit honestly.
 */
final class RecipientResolution {

  /**
   * Post about the child goes to each custody holder (child under 15).
   */
  public const RULE_GUARDIANS = 'guardians';

  /**
   * Post goes to the young person directly (15 or older).
   */
  public const RULE_PUPIL = 'pupil';

  /**
   * No recipient could be determined.
   *
   * The flow must fall back to manual handling instead of guessing.
   */
  public const RULE_NONE = 'none';

  /**
   * Constructs a resolution.
   *
   * @param string $rule
   *   One of the RULE_* constants.
   * @param string $reason
   *   Human-readable explanation of why this rule applied.
   * @param array $recipients
   *   List of recipient records, each with keys:
   *   - cpr (string): the recipient CPR.
   *   - full_name (string): display name, '' when unknown.
   *   - role (string): 'guardian' or 'pupil'.
   */
  public function __construct(
    public readonly string $rule,
    public readonly string $reason,
    public readonly array $recipients,
  ) {
  }

  /**
   * The recipient CPR numbers, in stable order.
   *
   * @return string[]
   *   The CPR numbers.
   */
  public function cprs(): array {
    return array_values(array_map(static fn (array $r): string => $r['cpr'], $this->recipients));
  }

  /**
   * Whether any recipient was resolved.
   */
  public function hasRecipients(): bool {
    return $this->recipients !== [];
  }

}
