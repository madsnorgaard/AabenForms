<?php

namespace Drupal\aabenforms_nemlogin\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Assembles the onelogin/php-saml settings array for the NemLog-in SP.
 *
 * The OCES3 keypair never lives in config: sp_cert_key / sp_private_key_key
 * name `key` module entries, so the PEM material is read at runtime and stays
 * out of config/sync. NemLog-in requires signed AuthnRequests and encrypts its
 * assertions, so the security block turns those on and the SP private key is
 * what decrypts the returned assertion.
 */
class NemloginSettingsBuilder {

  /**
   * NSIS Substantial authn-context class reference (the SP's default minimum).
   */
  public const LOA_SUBSTANTIAL = 'https://data.gov.dk/concept/core/nsis/loa/Substantial';

  /**
   * Maps the configured minimum level to its NSIS authn-context class ref.
   */
  protected const LOA_CLASS_REFS = [
    'low' => 'https://data.gov.dk/concept/core/nsis/loa/Low',
    'substantial' => 'https://data.gov.dk/concept/core/nsis/loa/Substantial',
    'high' => 'https://data.gov.dk/concept/core/nsis/loa/High',
  ];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs a NemloginSettingsBuilder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository) {
    $this->configFactory = $config_factory;
    $this->keyRepository = $key_repository;
  }

  /**
   * Builds the php-saml settings array from configuration.
   *
   * @return array
   *   The settings array for OneLogin\Saml2\Auth / Settings / Response.
   */
  public function buildSettings(): array {
    $config = $this->configFactory->get('aabenforms_nemlogin.settings');
    $required = (string) ($config->get('required_assurance_level') ?: 'substantial');
    $classRef = self::LOA_CLASS_REFS[strtolower($required)] ?? self::LOA_SUBSTANTIAL;

    return [
      'strict' => (bool) ($config->get('strict') ?? TRUE),
      'debug' => FALSE,
      'sp' => [
        'entityId' => (string) $config->get('sp_entity_id'),
        'assertionConsumerService' => [
          'url' => (string) $config->get('acs_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'singleLogoutService' => [
          'url' => (string) $config->get('slo_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'x509cert' => $this->readKey((string) $config->get('sp_cert_key')),
        'privateKey' => $this->readKey((string) $config->get('sp_private_key_key')),
      ],
      'idp' => [
        'entityId' => (string) $config->get('idp_entity_id'),
        'singleSignOnService' => [
          'url' => (string) $config->get('idp_sso_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'singleLogoutService' => [
          'url' => (string) $config->get('idp_slo_url'),
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'x509cert' => (string) $config->get('idp_cert'),
      ],
      'security' => [
        'authnRequestsSigned' => TRUE,
        'logoutRequestSigned' => TRUE,
        'logoutResponseSigned' => TRUE,
        'wantMessagesSigned' => TRUE,
        'wantAssertionsSigned' => TRUE,
        'wantAssertionsEncrypted' => TRUE,
        'wantNameIdEncrypted' => FALSE,
        'signMetadata' => TRUE,
        'requestedAuthnContext' => [$classRef],
        'requestedAuthnContextComparison' => 'minimum',
        'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
      ],
    ];
  }

  /**
   * Reads PEM material from a named key entry, tolerating an unconfigured key.
   *
   * @param string $key_name
   *   The `key` module entry id, or '' when unset.
   *
   * @return string
   *   The key value, or '' when the entry is unset or missing.
   */
  protected function readKey(string $key_name): string {
    if ($key_name === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($key_name);
    return $key ? (string) $key->getKeyValue() : '';
  }

}
