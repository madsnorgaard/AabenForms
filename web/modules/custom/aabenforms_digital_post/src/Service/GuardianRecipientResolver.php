<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Service;

use Drupal\aabenforms_core\Family\FamilyRelationsLookupInterface;
use Drupal\aabenforms_digital_post\DigitalPost\RecipientResolution;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves who receives Digital Post concerning a child.
 *
 * Implements the sender-side recipient rule the post infrastructure does NOT
 * implement for you:
 * - Child under 15: one message per registered custody holder (the 21 March
 *   2022 rule; separated parents at different addresses each get their own
 *   letter).
 * - Young person 15+: the pupil receives the post directly (15 is the age of
 *   mandatory Digital Post enrolment; parents only see it if the teen grants
 *   access in Digital Post itself).
 * - Unknown birth date or no registered custody holders: fail closed with
 *   RULE_NONE so the flow escalates to manual handling instead of guessing.
 *
 * Age is computed from the registry birth date (never parsed out of the CPR
 * number, whose century encoding is ambiguous) against Danish local time.
 */
class GuardianRecipientResolver {

  /**
   * The age from which Digital Post goes to the young person directly.
   */
  public const DIGITAL_POST_AGE = 15;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected FamilyRelationsLookupInterface $familyLookup,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('aabenforms_digital_post');
  }

  /**
   * Resolves the Digital Post recipients for post concerning a child.
   *
   * @param string $childCpr
   *   The child's CPR number (10 digits, hyphens tolerated).
   *
   * @return \Drupal\aabenforms_digital_post\DigitalPost\RecipientResolution
   *   The resolution. Never throws: registry failures resolve to RULE_NONE
   *   (fail closed) with the reason recorded.
   */
  public function resolveForChild(string $childCpr): RecipientResolution {
    $childCpr = preg_replace('/[^0-9]/', '', $childCpr) ?? '';
    if (strlen($childCpr) !== 10) {
      return new RecipientResolution(
        RecipientResolution::RULE_NONE,
        'Child CPR is missing or malformed.',
        [],
      );
    }

    try {
      $birthDate = $this->familyLookup->birthDateOf($childCpr);

      if ($birthDate === NULL) {
        return new RecipientResolution(
          RecipientResolution::RULE_NONE,
          'Birth date unknown in the CPR registry; recipient rule cannot be applied.',
          [],
        );
      }

      if ($this->ageAt($birthDate) >= self::DIGITAL_POST_AGE) {
        return new RecipientResolution(
          RecipientResolution::RULE_PUPIL,
          'The young person is 15 or older and receives Digital Post directly.',
          [
            ['cpr' => $childCpr, 'full_name' => '', 'role' => 'pupil'],
          ],
        );
      }

      $recipients = [];
      $seen = [];
      foreach ($this->familyLookup->guardiansOf($childCpr) as $guardian) {
        $cpr = (string) ($guardian['cpr'] ?? '');
        if ($cpr === '' || isset($seen[$cpr])) {
          continue;
        }
        $seen[$cpr] = TRUE;
        $recipients[] = [
          'cpr' => $cpr,
          'full_name' => (string) ($guardian['full_name'] ?? ''),
          'role' => 'guardian',
        ];
      }

      if ($recipients === []) {
        return new RecipientResolution(
          RecipientResolution::RULE_NONE,
          'No registered custody holders found for the child.',
          [],
        );
      }

      return new RecipientResolution(
        RecipientResolution::RULE_GUARDIANS,
        sprintf('Child is under 15; post goes to each of the %d registered custody holder(s).', count($recipients)),
        $recipients,
      );
    }
    catch (\Exception $e) {
      // Fail closed: an unavailable registry must never cause post about a
      // child to be sent to a guessed recipient.
      $this->logger->error('Guardian recipient resolution failed closed: {message}', [
        'message' => $e->getMessage(),
      ]);
      return new RecipientResolution(
        RecipientResolution::RULE_NONE,
        'The CPR registry could not be reached; recipient resolution failed closed.',
        [],
      );
    }
  }

  /**
   * Computes the age in whole years at the current request time.
   *
   * @param \DateTimeImmutable $birthDate
   *   The birth date.
   *
   * @return int
   *   The age in whole years, in Danish local time.
   */
  protected function ageAt(\DateTimeImmutable $birthDate): int {
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'));
    return $birthDate->diff($today)->y;
  }

}
