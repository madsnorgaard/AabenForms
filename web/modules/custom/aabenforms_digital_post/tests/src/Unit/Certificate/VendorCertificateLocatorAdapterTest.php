<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Unit\Certificate;

use Drupal\aabenforms_digital_post\Certificate\Certificate;
use Drupal\aabenforms_digital_post\Certificate\VendorCertificateLocatorAdapter;
use Drupal\Tests\UnitTestCase;
use ItkDev\Serviceplatformen\Certificate\Exception\CertificateLocatorException;

/**
 * Tests the module-DTO -> vendor-locator certificate adapter.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\Certificate\VendorCertificateLocatorAdapter
 * @group aabenforms_digital_post
 */
class VendorCertificateLocatorAdapterTest extends UnitTestCase {

  /**
   * The self-signed PKCS#12 bytes generated for the test.
   */
  protected string $pkcs12;

  /**
   * The passphrase protecting the test PKCS#12.
   */
  protected string $passphrase = 'test-pass';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $pkey = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $this->assertNotFalse($pkey, 'Could not generate a test key.');
    $csr = openssl_csr_new(['commonName' => 'aabenforms-test'], $pkey);
    $x509 = openssl_csr_sign($csr, NULL, $pkey, 1);
    $bytes = '';
    openssl_pkcs12_export($x509, $bytes, $pkey, $this->passphrase);
    $this->pkcs12 = $bytes;
  }

  /**
   * The store from openssl_pkcs12_read (cert + pkey) is returned from bytes.
   *
   * @covers ::getCertificates
   * @covers ::getCertificate
   */
  public function testGetCertificatesFromBytes(): void {
    $certificate = new Certificate('', $this->passphrase, 'key', $this->pkcs12);
    $adapter = new VendorCertificateLocatorAdapter($certificate);

    $store = $adapter->getCertificates();
    $this->assertArrayHasKey('cert', $store);
    $this->assertArrayHasKey('pkey', $store);
    $this->assertStringContainsString('BEGIN CERTIFICATE', $store['cert']);
    $this->assertStringContainsString('BEGIN CERTIFICATE', $adapter->getCertificate());
  }

  /**
   * A file-backed Certificate is read from disk when it carries no bytes.
   *
   * @covers ::getCertificates
   */
  public function testGetCertificatesFromFile(): void {
    $path = tempnam(sys_get_temp_dir(), 'af-p12-');
    file_put_contents($path, $this->pkcs12);
    try {
      $certificate = new Certificate($path, $this->passphrase, 'file');
      $adapter = new VendorCertificateLocatorAdapter($certificate);
      $store = $adapter->getCertificates();
      $this->assertArrayHasKey('pkey', $store);
    }
    finally {
      @unlink($path);
    }
  }

  /**
   * A wrong passphrase surfaces as a CertificateLocatorException.
   *
   * @covers ::getCertificates
   */
  public function testWrongPassphraseThrows(): void {
    $certificate = new Certificate('', 'wrong-pass', 'key', $this->pkcs12);
    $adapter = new VendorCertificateLocatorAdapter($certificate);
    $this->expectException(CertificateLocatorException::class);
    $adapter->getCertificates();
  }

  /**
   * A bytes-only certificate refuses getAbsolutePathToCertificate().
   *
   * @covers ::getAbsolutePathToCertificate
   */
  public function testInMemoryCertHasNoPath(): void {
    $adapter = new VendorCertificateLocatorAdapter(new Certificate('', $this->passphrase, 'key', $this->pkcs12));
    $this->expectException(CertificateLocatorException::class);
    $adapter->getAbsolutePathToCertificate();
  }

}
