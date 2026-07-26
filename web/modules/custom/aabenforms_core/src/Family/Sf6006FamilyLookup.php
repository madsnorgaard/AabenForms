<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Family;

use Drupal\aabenforms_core\Service\ServiceplatformenClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Family/custody lookup backed by Serviceplatformen SF6006 (Familie+).
 *
 * Demo fallback: when no Serviceplatformen client certificate is provisioned
 * (the local/POC case, same signal the CPR lookup action uses), the service
 * answers from DemoFamilyRepository instead of calling out, and logs that it
 * did. Once certificates are configured, real SF6006 lookups run without any
 * config change. The WireMock mappings in .ddev/mocks/wiremock mirror the
 * demo data, so `wiremock` transport testing and demo mode agree.
 *
 * Custody filtering happens here, not in callers: childrenOf() only returns
 * children the adult actually holds custody of (GuardianType codes), and
 * hasCustody() is fail-closed.
 */
class Sf6006FamilyLookup implements FamilyRelationsLookupInterface {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected ServiceplatformenClient $client,
    protected ConfigFactoryInterface $configFactory,
    protected DemoFamilyRepository $demoFamilies,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('aabenforms_core');
  }

  /**
   * {@inheritdoc}
   */
  public function childrenOf(string $parentCpr): array {
    $parentCpr = $this->normalize($parentCpr);
    if ($parentCpr === '') {
      return [];
    }

    if ($this->demoMode()) {
      $this->logger->warning('Family lookup ran in demo mode (no Serviceplatformen certificate).');
      return $this->demoFamilies->childrenOf($parentCpr);
    }

    $result = $this->client->request('SF6006', 'FamilyLookup', ['cpr' => $parentCpr], ['no_cache' => TRUE]);
    $children = [];
    foreach ($result['children'] ?? [] as $child) {
      // The interface promises guardians = custody holders ONLY; the raw
      // feed may carry non-custodial relation codes, which must never reach
      // co-sign flows that pick "the other guardian" from this list.
      $custodial = array_values(array_filter(
        $child['guardians'] ?? [],
        static fn (array $g): bool => GuardianType::isCustodial((int) ($g['type'] ?? 0)),
      ));
      foreach ($custodial as $guardian) {
        if ($guardian['cpr'] === $parentCpr) {
          $child['guardians'] = $custodial;
          $children[] = $child;
          break;
        }
      }
    }
    return $children;
  }

  /**
   * {@inheritdoc}
   */
  public function guardiansOf(string $childCpr): array {
    $childCpr = $this->normalize($childCpr);
    if ($childCpr === '') {
      return [];
    }

    if ($this->demoMode()) {
      $this->logger->warning('Family lookup ran in demo mode (no Serviceplatformen certificate).');
      return $this->demoFamilies->guardiansOf($childCpr);
    }

    $result = $this->client->request('SF6006', 'FamilyLookup', ['cpr' => $childCpr], ['no_cache' => TRUE]);
    $guardians = [];
    foreach ($result['guardians'] ?? [] as $guardian) {
      if (GuardianType::isCustodial((int) ($guardian['type'] ?? 0))) {
        $guardians[] = $guardian;
      }
    }
    return $guardians;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCustody(string $adultCpr, string $childCpr): bool {
    $adultCpr = $this->normalize($adultCpr);
    $childCpr = $this->normalize($childCpr);
    if ($adultCpr === '' || $childCpr === '') {
      return FALSE;
    }
    try {
      foreach ($this->guardiansOf($childCpr) as $guardian) {
        if (hash_equals($guardian['cpr'], $adultCpr)) {
          return TRUE;
        }
      }
    }
    catch (\Exception $e) {
      // Fail closed: an unavailable registry never grants custody.
      $this->logger->error('Custody check failed closed: {message}', ['message' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function birthDateOf(string $childCpr): ?\DateTimeImmutable {
    $childCpr = $this->normalize($childCpr);
    if ($childCpr === '') {
      return NULL;
    }

    if ($this->demoMode()) {
      return $this->demoFamilies->birthDateOf($childCpr);
    }

    $result = $this->client->request('SF6006', 'FamilyLookup', ['cpr' => $childCpr], ['no_cache' => TRUE]);
    $date = $result['person']['birth_date'] ?? NULL;
    if (!is_string($date) || $date === '') {
      return NULL;
    }
    try {
      return new \DateTimeImmutable($date);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isDemoMode(): bool {
    return $this->demoMode();
  }

  /**
   * Whether to answer from demo data instead of Serviceplatformen.
   *
   * Same signal as the CPR lookup action: no client certificate provisioned
   * means demo mode. There is deliberately no separate flag to keep the two
   * lookups from diverging.
   */
  protected function demoMode(): bool {
    $certs = $this->configFactory->get('aabenforms_core.settings')->get('serviceplatformen.certificates') ?? [];
    return empty($certs['cert_path']) && empty($certs['key_path']);
  }

  /**
   * Normalizes a CPR to a bare 10-digit string, or '' when invalid.
   */
  protected function normalize(string $cpr): string {
    $digits = preg_replace('/[^0-9]/', '', $cpr) ?? '';
    return strlen($digits) === 10 ? $digits : '';
  }

}
