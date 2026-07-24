<?php

namespace Drupal\aabenforms_nemlogin\Service;

use Drupal\aabenforms_core\Identity\IdentityProviderInterface;
use Drupal\aabenforms_core\Identity\SessionManagerInterface;
use Drupal\aabenforms_core\Identity\VerifiedIdentity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use OneLogin\Saml2\Response as SamlResponse;
use OneLogin\Saml2\Settings as SamlSettings;
use Psr\Log\LoggerInterface;

/**
 * Validates a NemLog-in OIOSAML 3 assertion and mints a verified session.
 *
 * This is the production kommune identity rail. It is the SAML sibling of
 * MitIdOidcClient: a different protocol in, the same VerifiedIdentity out,
 * stored through the same SessionManagerInterface. Every downstream reader (the
 * ECA gate, CPR extractor, webform intake) is therefore unaware which rail
 * authenticated the citizen.
 *
 * The assertion-to-identity mapping (buildIdentity / resolveAssuranceLevel) is
 * kept pure so it can be unit-tested against attribute fixtures without a live
 * IdP; authenticateResponse() wraps it with php-saml signature/condition
 * validation.
 */
class NemloginSamlAuthenticator implements IdentityProviderInterface {

  /**
   * The stable provider id for the NemLog-in SAML rail.
   */
  public const PROVIDER_ID = 'nemlogin_saml';

  /**
   * OIOSAML 3 attribute Names (Faelleskommunal / core eID attributprofil).
   */
  public const ATTR_CPR = 'https://data.gov.dk/model/core/eid/cprNumber';
  public const ATTR_FULL_NAME = 'https://data.gov.dk/model/core/eid/fullName';
  public const ATTR_FIRST_NAME = 'https://data.gov.dk/model/core/eid/firstName';
  public const ATTR_LAST_NAME = 'https://data.gov.dk/model/core/eid/lastName';
  public const ATTR_EMAIL = 'https://data.gov.dk/model/core/eid/email';
  public const ATTR_DOB = 'https://data.gov.dk/model/core/eid/dateOfBirth';
  public const ATTR_ALIAS = 'https://data.gov.dk/model/core/eid/alias';

  /**
   * NSIS Level-of-Assurance attribute and its value URIs (OIOSAML 3).
   */
  public const ATTR_NSIS_LOA = 'https://data.gov.dk/concept/core/nsis/loa';

  /**
   * Legacy numeric assurance attribute (OIOSAML 2 identification means).
   */
  public const ATTR_LEGACY_ASSURANCE = 'dk:gov:saml:attribute:AssuranceLevel';

  /**
   * The verified-identity session store (provider-neutral).
   *
   * @var \Drupal\aabenforms_core\Identity\SessionManagerInterface
   */
  protected SessionManagerInterface $sessionManager;

  /**
   * The php-saml settings builder.
   *
   * @var \Drupal\aabenforms_nemlogin\Service\NemloginSettingsBuilder
   */
  protected NemloginSettingsBuilder $settingsBuilder;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a NemloginSamlAuthenticator.
   *
   * @param \Drupal\aabenforms_core\Identity\SessionManagerInterface $session_manager
   *   The verified-identity session store.
   * @param \Drupal\aabenforms_nemlogin\Service\NemloginSettingsBuilder $settings_builder
   *   The php-saml settings builder.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    SessionManagerInterface $session_manager,
    NemloginSettingsBuilder $settings_builder,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->sessionManager = $session_manager;
    $this->settingsBuilder = $settings_builder;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('aabenforms_nemlogin');
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return self::PROVIDER_ID;
  }

  /**
   * Validates a posted SAMLResponse and stores the resulting session.
   *
   * @param string $saml_response_b64
   *   The base64 SAMLResponse as posted to the ACS endpoint.
   * @param string $workflow_id
   *   The workflow / bearer handle to store the session under.
   *
   * @return \Drupal\aabenforms_core\Identity\VerifiedIdentity
   *   The verified identity.
   *
   * @throws \RuntimeException
   *   When the assertion is invalid or its assurance is below the requirement.
   */
  public function authenticateResponse(string $saml_response_b64, string $workflow_id): VerifiedIdentity {
    $settings = new SamlSettings($this->settingsBuilder->buildSettings());
    $response = new SamlResponse($settings, $saml_response_b64);

    if (!$response->isValid()) {
      $reason = $response->getErrorException()?->getMessage() ?? 'unknown';
      $this->logger->warning('NemLog-in assertion rejected: {reason}', ['reason' => $reason]);
      throw new \RuntimeException('Invalid NemLog-in SAML assertion.');
    }

    $identity = $this->buildIdentity($response->getAttributes(), $response->getNameId());
    return $this->completeLogin($identity, $workflow_id);
  }

  /**
   * Enforces assurance, stores the identity and returns it.
   *
   * Shared by the live ACS path and any test harness that has already produced
   * a VerifiedIdentity. Fails closed: an identity below the configured minimum
   * NSIS level never reaches the session store.
   *
   * @param \Drupal\aabenforms_core\Identity\VerifiedIdentity $identity
   *   The verified identity to persist.
   * @param string $workflow_id
   *   The workflow / bearer handle.
   *
   * @return \Drupal\aabenforms_core\Identity\VerifiedIdentity
   *   The stored identity.
   *
   * @throws \RuntimeException
   *   When the assurance is below the configured requirement.
   */
  public function completeLogin(VerifiedIdentity $identity, string $workflow_id): VerifiedIdentity {
    $required = (string) ($this->configFactory->get('aabenforms_nemlogin.settings')
      ->get('required_assurance_level') ?: 'substantial');
    if (!$identity->meetsAssurance($required)) {
      $this->logger->warning('NemLog-in assurance {have} below required {need}.', [
        'have' => $identity->assuranceLevel,
        'need' => $required,
      ]);
      throw new \RuntimeException(sprintf(
        'NemLog-in assurance level "%s" is below the required "%s".',
        $identity->assuranceLevel,
        $required
      ));
    }

    if ($identity->cpr === '') {
      throw new \RuntimeException('NemLog-in assertion carried no CPR number.');
    }

    $this->sessionManager->storeSession($workflow_id, $identity->toSessionArray());
    $this->logger->info('NemLog-in session stored for workflow {wf} (cpr {masked}, loa {loa}).', [
      'wf' => $workflow_id,
      'masked' => substr($identity->cpr, 0, 6) . 'XXXX',
      'loa' => $identity->assuranceLevel,
    ]);
    return $identity;
  }

  /**
   * Maps OIOSAML 3 assertion attributes to a VerifiedIdentity.
   *
   * Pure: no I/O, no php-saml. This is the heart of the rail and is exercised
   * directly by unit tests against attribute fixtures.
   *
   * @param array $attributes
   *   The php-saml getAttributes() shape: attribute Name => list of values.
   * @param string|null $name_id
   *   The assertion Subject NameID (the persistent person UUID URI).
   *
   * @return \Drupal\aabenforms_core\Identity\VerifiedIdentity
   *   The mapped identity (assurance not yet enforced).
   */
  public function buildIdentity(array $attributes, ?string $name_id): VerifiedIdentity {
    return new VerifiedIdentity(
      cpr: $this->first($attributes, self::ATTR_CPR),
      assuranceLevel: $this->resolveAssuranceLevel($attributes),
      provider: self::PROVIDER_ID,
      subject: $name_id ?: NULL,
      name: $this->first($attributes, self::ATTR_FULL_NAME) ?: $this->composeName($attributes),
      givenName: $this->first($attributes, self::ATTR_FIRST_NAME) ?: NULL,
      familyName: $this->first($attributes, self::ATTR_LAST_NAME) ?: NULL,
      birthdate: $this->first($attributes, self::ATTR_DOB) ?: NULL,
      email: $this->first($attributes, self::ATTR_EMAIL) ?: NULL,
      issuer: (string) ($this->configFactory->get('aabenforms_nemlogin.settings')->get('idp_entity_id') ?: '') ?: NULL,
      authTime: NULL,
    );
  }

  /**
   * Resolves the NSIS assurance level from either assurance scheme.
   *
   * NemLog-in may assert an NSIS LoA (OIOSAML 3) or, for a non-NSIS means, a
   * legacy numeric AssuranceLevel. Fails closed: anything unrecognised is
   * 'unknown' and never satisfies a substantial/high requirement.
   *
   * @param array $attributes
   *   The php-saml getAttributes() shape.
   *
   * @return string
   *   'low', 'substantial', 'high' or 'unknown'.
   */
  public function resolveAssuranceLevel(array $attributes): string {
    $loa = $this->first($attributes, self::ATTR_NSIS_LOA);
    if ($loa !== '') {
      // Value is a URI ending in Low|Substantial|High, or a bare word.
      $tail = strtolower(substr($loa, (int) strrpos($loa, '/') + 1) ?: $loa);
      if (isset(VerifiedIdentity::ASSURANCE_RANKS[$tail])) {
        return $tail;
      }
    }
    $legacy = $this->first($attributes, self::ATTR_LEGACY_ASSURANCE);
    return match ($legacy) {
      '4' => 'high',
      '3' => 'substantial',
      '2', '1' => 'low',
      default => 'unknown',
    };
  }

  /**
   * Returns the first value of a multi-valued SAML attribute, or ''.
   *
   * @param array $attributes
   *   The attribute map.
   * @param string $name
   *   The attribute Name.
   *
   * @return string
   *   The first value, or the empty string when absent. 'N/A' (returned by
   *   NemLog-in for name-protected citizens) is treated as absent.
   */
  protected function first(array $attributes, string $name): string {
    $value = '';
    if (isset($attributes[$name]) && is_array($attributes[$name]) && $attributes[$name] !== []) {
      $value = trim((string) reset($attributes[$name]));
    }
    return $value === 'N/A' ? '' : $value;
  }

  /**
   * Builds a full name from first/last parts when no fullName is asserted.
   *
   * @param array $attributes
   *   The attribute map.
   *
   * @return string|null
   *   The composed name, or NULL when neither part is present.
   */
  protected function composeName(array $attributes): ?string {
    $parts = array_filter([
      $this->first($attributes, self::ATTR_FIRST_NAME),
      $this->first($attributes, self::ATTR_LAST_NAME),
    ], 'strlen');
    return $parts === [] ? NULL : implode(' ', $parts);
  }

}
