<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Registration;

use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * DEMO registration lookup backed by a fixed fixture of demo personas.
 *
 * DEMO ONLY: this is not the Digital Post kontaktregister. It knows the
 * fictitious demo personas from aabenforms_mitid's DemoPersonas (copied here
 * so this module keeps its minimal dependency set) and answers UNKNOWN for
 * every other CPR - it never guesses "registered".
 *
 * It refuses to answer at all (UNKNOWN) when the Digital Post transport is in
 * a live mode: a demo fixture must never decide how a real citizen's letter
 * is delivered, even if a real person happens to share a demo CPR.
 */
final class DemoRegistrationLookup implements DigitalPostRegistrationLookupInterface {

  /**
   * Demo persona registration status, keyed by the fictitious demo CPR.
   *
   * Mirrors aabenforms_mitid DemoPersonas: Birthe Jensen (78) is fritaget
   * (the `digital_post_exempt` demo claim); the other personas are tilmeldt.
   */
  public const FIXTURE = [
    // Freja Nielsen.
    '0101904521' => RegistrationStatus::REGISTERED,
    // Mikkel Jensen.
    '1502856234' => RegistrationStatus::REGISTERED,
    // Sofie Hansen.
    '2506924015' => RegistrationStatus::REGISTERED,
    // Lars Andersen.
    '0803755210' => RegistrationStatus::REGISTERED,
    // Birthe Jensen - fritaget for Digital Post.
    '2403482210' => RegistrationStatus::EXEMPT,
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function lookup(Recipient $recipient): RegistrationStatus {
    if ($recipient->type === Recipient::TYPE_CVR) {
      return new RegistrationStatus(RegistrationStatus::REGISTERED, RegistrationStatus::MODE_DEMO, 'cvr_always_registered');
    }
    $testMode = (string) $this->configFactory->get('aabenforms_digital_post.settings')->get('test_mode');
    if (in_array($testMode, ['live', 'live_test'], TRUE)) {
      return RegistrationStatus::unknown(RegistrationStatus::MODE_DEMO, 'demo_lookup_disabled_in_live');
    }
    $status = self::FIXTURE[$recipient->identifier] ?? NULL;
    if ($status === NULL) {
      return RegistrationStatus::unknown(RegistrationStatus::MODE_DEMO, 'not_in_fixture');
    }
    return new RegistrationStatus($status, RegistrationStatus::MODE_DEMO, 'demo_fixture');
  }

  /**
   * {@inheritdoc}
   */
  public function mode(): string {
    return RegistrationStatus::MODE_DEMO;
  }

}
