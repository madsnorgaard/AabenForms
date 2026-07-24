<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Certificate;

/**
 * Immutable certificate material returned by a CertificateLocator.
 *
 * Carries the cert as a file path and an optional passphrase. A locator that
 * has no file on disk (the drupal:key source) instead carries the PKCS#12
 * bytes in $pkcs12 and leaves $path empty; the vendor adapter reads whichever
 * is present. Log emitters must never touch $pkcs12.
 */
final class Certificate {

  /**
   * Constructs a Certificate.
   *
   * @param string $path
   *   Absolute path to the PKCS#12/PEM file, or '' when the cert is held as
   *   bytes in $pkcs12 (the key source).
   * @param string|null $passphrase
   *   The PKCS#12 passphrase, or NULL when none.
   * @param string $sourceLabel
   *   A short label for the locator that produced this (e.g. 'file', 'key').
   * @param string|null $pkcs12
   *   The raw PKCS#12 bytes when the cert is not on disk; NULL otherwise.
   */
  public function __construct(
    public readonly string $path,
    public readonly ?string $passphrase,
    public readonly string $sourceLabel,
    public readonly ?string $pkcs12 = NULL,
  ) {
  }

}
