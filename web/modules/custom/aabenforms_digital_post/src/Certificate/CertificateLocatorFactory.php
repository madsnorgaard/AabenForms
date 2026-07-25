<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Certificate;

use Drupal\aabenforms_digital_post\Exception\CertificateException;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Selects the concrete CertificateLocator based on config.cert_source.
 *
 * Ships "file" (path + env-var passphrase) and "key" (PKCS#12 held in a
 * drupal:key entry). A future "os2web_key" bridge submodule would register its
 * own locator and be picked here by string match.
 */
final class CertificateLocatorFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileCertificateLocator $fileLocator,
    private readonly KeyCertificateLocator $keyLocator,
  ) {
  }

  /**
   * Returns the locator implementation matching aabenforms_digital_post.settings.cert_source.
   *
   * @throws \Drupal\aabenforms_digital_post\Exception\CertificateException
   *   When cert_source is set to an unsupported backend.
   */
  public function create(): CertificateLocatorInterface {
    $source = (string) $this->configFactory
      ->get('aabenforms_digital_post.settings')
      ->get('cert_source');
    return match ($source) {
      'file', '' => $this->fileLocator,
      'key' => $this->keyLocator,
      default => throw new CertificateException(sprintf(
        'cert_source "%s" is not supported in this build. Available: file, key.',
        $source
      )),
    };
  }

}
