<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Certificate;

use Drupal\aabenforms_digital_post\Exception\CertificateException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Certificate locator backed by the drupal:key module.
 *
 * The FOCES3/OCES3 PKCS#12 lives in a `key` entry (named by config `cert_key`)
 * rather than on disk, so the secret stays in the key module's encrypted
 * storage and out of config/sync. The passphrase is resolved the same way the
 * file locator does - from the environment variable named by
 * `cert_passphrase_state` - so a passphrase is never version-controlled either.
 */
final class KeyCertificateLocator implements CertificateLocatorInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function locate(): Certificate {
    $config = $this->configFactory->get('aabenforms_digital_post.settings');
    $keyName = (string) $config->get('cert_key');
    if ($keyName === '') {
      throw new CertificateException('aabenforms_digital_post.settings:cert_key is empty (cert_source=key).');
    }
    $key = $this->keyRepository->getKey($keyName);
    if ($key === NULL) {
      throw new CertificateException(sprintf('Key entry "%s" does not exist.', $keyName));
    }
    $bytes = (string) $key->getKeyValue();
    if ($bytes === '') {
      throw new CertificateException(sprintf('Key entry "%s" is empty.', $keyName));
    }

    $envVar = (string) $config->get('cert_passphrase_state');
    $passphrase = NULL;
    if ($envVar !== '') {
      $value = getenv($envVar);
      $passphrase = $value === FALSE ? NULL : $value;
    }

    return new Certificate(
      path: '',
      passphrase: $passphrase,
      sourceLabel: 'key',
      pkcs12: $bytes,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function supportsRenewal(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function expiresAt(): ?\DateTimeImmutable {
    try {
      $certificate = $this->locate();
    }
    catch (CertificateException) {
      return NULL;
    }
    $store = [];
    $passphrase = $certificate->passphrase ?? '';
    if ($certificate->pkcs12 === NULL || !openssl_pkcs12_read($certificate->pkcs12, $store, $passphrase)) {
      return NULL;
    }
    $parsed = @openssl_x509_parse((string) ($store['cert'] ?? ''));
    if ($parsed === FALSE || !isset($parsed['validTo_time_t'])) {
      return NULL;
    }
    return (new \DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']);
  }

}
