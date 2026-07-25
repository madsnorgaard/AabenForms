<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Certificate;

use ItkDev\Serviceplatformen\Certificate\AbstractCertificateLocator;
use ItkDev\Serviceplatformen\Certificate\Exception\CertificateLocatorException;

/**
 * Bridges the module's Certificate DTO to itk-dev's CertificateLocatorInterface.
 *
 * The live SF1601 client obtains a module Certificate (path + passphrase, or
 * raw PKCS#12 bytes) from the module's own locator, then wraps it in this
 * adapter so the vendor SF1601 service can call getCertificates(). At runtime
 * the REST path only ever reads getCertificates()['cert'|'pkey'] (the
 * openssl_pkcs12_read output), so that is the method that matters; the others
 * exist to satisfy the interface.
 */
final class VendorCertificateLocatorAdapter extends AbstractCertificateLocator {

  /**
   * Constructs the adapter from a module Certificate DTO.
   *
   * @param \Drupal\aabenforms_digital_post\Certificate\Certificate $certificate
   *   The located module certificate (file path or in-memory PKCS#12 bytes).
   */
  public function __construct(private readonly Certificate $certificate) {
    parent::__construct($certificate->passphrase ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getCertificates(): array {
    $bytes = $this->readPkcs12();
    $store = [];
    $passphrase = $this->hasPassphrase() ? $this->getPassphrase() : NULL;
    if (!openssl_pkcs12_read($bytes, $store, (string) $passphrase)) {
      throw new CertificateLocatorException(sprintf(
        'Could not read PKCS#12 certificate from %s (wrong passphrase or corrupt file).',
        $this->certificate->sourceLabel
      ));
    }
    return $store;
  }

  /**
   * {@inheritdoc}
   */
  public function getCertificate(): string {
    $store = $this->getCertificates();
    return (string) ($store['cert'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getAbsolutePathToCertificate(): string {
    // Deprecated in the interface and unused by the REST send path. A
    // key-sourced cert has no path, so refuse rather than return a bad value.
    if ($this->certificate->path !== '') {
      return $this->certificate->path;
    }
    throw new CertificateLocatorException('This certificate is held in memory and has no filesystem path.');
  }

  /**
   * Returns the PKCS#12 bytes, from memory or by reading the file.
   *
   * @return string
   *   The raw PKCS#12 bytes.
   *
   * @throws \ItkDev\Serviceplatformen\Certificate\CertificateLocatorException
   *   When neither bytes nor a readable file are available.
   */
  private function readPkcs12(): string {
    if ($this->certificate->pkcs12 !== NULL && $this->certificate->pkcs12 !== '') {
      return $this->certificate->pkcs12;
    }
    if ($this->certificate->path === '' || !is_readable($this->certificate->path)) {
      throw new CertificateLocatorException(sprintf(
        'Certificate source "%s" has no bytes and no readable file.',
        $this->certificate->sourceLabel
      ));
    }
    $bytes = file_get_contents($this->certificate->path);
    if ($bytes === FALSE) {
      throw new CertificateLocatorException(sprintf('Could not read certificate file "%s".', $this->certificate->path));
    }
    return $bytes;
  }

}
