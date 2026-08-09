<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Encrypts CPR values at rest and reveals them at the point of use.
 *
 * A single touchpoint so the rest of the system does not need to know the
 * ciphertext format. Protected values carry a prefix, which makes the
 * encrypt/reveal operations idempotent and lets reveal() pass through any
 * value that is not encrypted (for example a CPR taken from a MitID session
 * rather than a stored webform field).
 */
class CprAccess {

  /**
   * Legacy marker: single-key ciphertext, decrypted with the default profile.
   */
  protected const PREFIX = 'AFENC1:';

  /**
   * Per-tenant marker: format AFENC2:<tenant>:<base64>, per-tenant profile.
   */
  protected const PREFIX_TENANT = 'AFENC2:';

  /**
   * The default (single-tenant / legacy) encryption profile id.
   */
  protected const DEFAULT_PROFILE = 'aabenforms_aes256';

  /**
   * Element '#type' values that hold a CPR number.
   *
   * 'cpr_field' is the real plugin id. 'aabenforms_cpr_field' and 'cpr' are
   * the pre-#172 ids, kept so stored or imported config that still uses them
   * is encrypted rather than silently skipped.
   */
  public const CPR_ELEMENT_TYPES = ['cpr_field', 'aabenforms_cpr_field', 'cpr'];

  /**
   * The encryption service.
   *
   * @var \Drupal\aabenforms_core\Service\EncryptionService
   */
  protected EncryptionService $encryption;

  /**
   * The tenant resolver.
   *
   * @var \Drupal\aabenforms_core\Service\TenantResolver
   */
  protected TenantResolver $tenantResolver;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a CprAccess helper.
   *
   * @param \Drupal\aabenforms_core\Service\EncryptionService $encryption
   *   The encryption service.
   * @param \Drupal\aabenforms_core\Service\TenantResolver $tenant_resolver
   *   The tenant resolver (selects a per-tenant key when a tenant is active).
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EncryptionService $encryption, TenantResolver $tenant_resolver, LoggerChannelFactoryInterface $logger_factory) {
    $this->encryption = $encryption;
    $this->tenantResolver = $tenant_resolver;
    $this->logger = $logger_factory->get('aabenforms_core');
  }

  /**
   * Whether a value is already an encrypted CPR.
   *
   * @param string $value
   *   The value to test.
   *
   * @return bool
   *   TRUE if the value carries either encryption prefix.
   */
  public function isProtected(string $value): bool {
    return str_starts_with($value, self::PREFIX) || str_starts_with($value, self::PREFIX_TENANT);
  }

  /**
   * Decides whether a webform element holds a CPR number.
   *
   * The single authority for "is this a CPR field" (#172). Both the
   * webform-submission presave encryption hook and the API controller's
   * MitID prefill MUST use this, so a field cannot be prefilled with a CPR
   * by one path and then missed by the encryption in the other.
   *
   * @param string $key
   *   The element's machine key.
   * @param array<string, mixed> $element
   *   The (initialized or decoded) element definition.
   *
   * @return bool
   *   TRUE when the element's type is a CPR type, or - because several
   *   citizen forms use a plain textfield - its key is 'cpr' or ends in
   *   '_cpr' (cpr, child_cpr, applicant_cpr, parent1_cpr, ...).
   */
  public function isCprElement(string $key, array $element): bool {
    return in_array($element['#type'] ?? '', self::CPR_ELEMENT_TYPES, TRUE)
      || $key === 'cpr'
      || str_ends_with($key, '_cpr');
  }

  /**
   * The per-tenant encryption profile id for a tenant.
   */
  protected function tenantProfile(string $tenant): string {
    return self::DEFAULT_PROFILE . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($tenant));
  }

  /**
   * Returns an encrypted, storage-safe representation of a CPR.
   *
   * Idempotent: an already-encrypted or empty value is returned unchanged.
   *
   * @param string $cpr
   *   The plaintext CPR.
   *
   * @return string
   *   The prefixed ciphertext, or the original value if empty/already
   *   protected.
   *
   * @throws \RuntimeException
   *   When encryption is misconfigured (missing profile/key). Fails hard so a
   *   CPR is NEVER written in plaintext: it is safer to reject the submission
   *   than to store personal data unencrypted (databeskyttelsesloven). The
   *   sole caller (the webform-submission presave hook) lets this abort the
   *   save; the error message carries no CPR.
   */
  public function protect(string $cpr): string {
    if ($cpr === '' || $this->isProtected($cpr)) {
      return $cpr;
    }
    try {
      $tenant = $this->tenantResolver->getCurrentTenantId();
      if (is_string($tenant) && $tenant !== '') {
        // Multi-tenant: encrypt with this tenant's own key so another tenant's
        // key cannot decrypt it. The tenant id is embedded for null-context
        // reads (drush/cron) but same-context reads use the current tenant.
        return self::PREFIX_TENANT . $tenant . ':' . base64_encode($this->encryption->encrypt($cpr, $this->tenantProfile($tenant)));
      }
      // Single-tenant: unchanged legacy format + default profile.
      return self::PREFIX . base64_encode($this->encryption->encrypt($cpr));
    }
    catch (\Throwable $e) {
      $this->logger->error('CPR encryption failed; refusing to store plaintext: {error}', ['error' => $e->getMessage()]);
      throw new \RuntimeException('CPR-kryptering er ikke konfigureret; indsendelsen blev afvist for at undgå at gemme CPR i klartekst.', 0, $e);
    }
  }

  /**
   * Returns the plaintext CPR for a value, decrypting if necessary.
   *
   * A value without the encryption prefix is returned unchanged, so callers
   * can pass session-sourced or already-plaintext CPRs through safely.
   *
   * @param string $value
   *   The stored value.
   *
   * @return string
   *   The plaintext CPR, or '' if decryption fails.
   */
  public function reveal(string $value): string {
    if (str_starts_with($value, self::PREFIX_TENANT)) {
      $rest = substr($value, strlen(self::PREFIX_TENANT));
      $sep = strpos($rest, ':');
      if ($sep === FALSE) {
        return '';
      }
      $embedded = substr($rest, 0, $sep);
      $ciphertext = base64_decode(substr($rest, $sep + 1));
      // Decrypt with the CURRENT tenant's key when a tenant context exists, so
      // one kommune cannot reveal another's CPR (the wrong key fails). In a
      // null context (drush/cron) fall back to the embedded tenant's key.
      $current = $this->tenantResolver->getCurrentTenantId();
      $profileTenant = (is_string($current) && $current !== '') ? $current : $embedded;
      try {
        return $this->encryption->decrypt($ciphertext, $this->tenantProfile($profileTenant));
      }
      catch (\Throwable $e) {
        $this->logger->error('CPR decryption failed (tenant): {error}', ['error' => $e->getMessage()]);
        return '';
      }
    }
    if (str_starts_with($value, self::PREFIX)) {
      try {
        return $this->encryption->decrypt(base64_decode(substr($value, strlen(self::PREFIX))));
      }
      catch (\Throwable $e) {
        $this->logger->error('CPR decryption failed: {error}', ['error' => $e->getMessage()]);
        return '';
      }
    }
    // Not encrypted (e.g. a session-sourced or already-plaintext CPR).
    return $value;
  }

}
