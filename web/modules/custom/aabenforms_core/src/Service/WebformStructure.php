<?php

declare(strict_types=1);

namespace Drupal\aabenforms_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\webform\WebformInterface;

/**
 * Derives the citizen-facing structure of a webform for the SPA.
 *
 * Two things the flattened element list cannot carry:
 *
 * - pages(): the wizard pages (webform_wizard_page containers) in order, each
 *   with the keys of the visible inputs it holds, so the frontend can render
 *   one step per page with its own validation (KL principle 4).
 * - gedisa(): the guidance a kommune authors on the webform
 *   (third_party_settings.aabenforms_core): intro, what to have ready, legal
 *   basis, expected response time and next steps (KL components 2, 8, 9, 10),
 *   plus the recipient block (component 1) as far as the site knows it.
 *
 * Pure config in, arrays out: no request, so it is testable in a kernel test
 * and the controller stays a thin wrapper.
 */
class WebformStructure {

  /**
   * The third-party settings namespace on the webform entity.
   */
  public const THIRD_PARTY = 'aabenforms_core';

  /**
   * Element types that never render an input for the citizen.
   */
  private const INVISIBLE_TYPES = ['hidden', 'value'];

  /**
   * Element types that group other elements and have no value of their own.
   *
   * Any element with child elements is treated as a container too; this list
   * covers containers that happen to be empty.
   */
  private const CONTAINER_TYPES = [
    'webform_wizard_page',
    'fieldset',
    'container',
    'details',
    'webform_flexbox',
    'webform_section',
    'item',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected TenantResolver $tenantResolver,
  ) {}

  /**
   * The wizard pages of a webform, in order.
   *
   * Each page: key, title, description ('' when unset) and the ordered keys of
   * the visible inputs under it (nested containers flattened, containers and
   * hidden elements left out). Visible inputs outside every page go into a
   * synthetic first page ("start"); a form without wizard pages returns [].
   *
   * @return array<int, array{key: string, title: string, description: string, elements: string[]}>
   *   The pages.
   */
  public function pages(WebformInterface $webform): array {
    $tree = $webform->getElementsDecoded();
    if (!is_array($tree)) {
      return [];
    }

    $pages = [];
    $loose = [];
    foreach ($tree as $key => $element) {
      if (!$this->isElement($key, $element)) {
        continue;
      }
      if (($element['#type'] ?? '') === 'webform_wizard_page') {
        $pages[] = [
          'key' => (string) $key,
          'title' => (string) ($element['#title'] ?? $key),
          'description' => (string) ($element['#description'] ?? ''),
          'elements' => $this->collectInputKeys($element),
        ];
      }
      else {
        $this->collectFrom((string) $key, $element, $loose);
      }
    }

    if ($pages === []) {
      return [];
    }
    if ($loose !== []) {
      array_unshift($pages, [
        'key' => 'start',
        'title' => 'Start',
        'description' => '',
        'elements' => $loose,
      ]);
    }
    return $pages;
  }

  /**
   * The GEDISA guidance of a webform, every key present.
   *
   * @return array{intro: ?string, have_ready: string[], legal_basis: array<int, array{title: string, url?: string}>, expected_response: ?string, next_steps: ?string, recipient: array{name: ?string, department: ?string, phone: ?string, email: ?string, hours: ?string}}
   *   The guidance.
   */
  public function gedisa(WebformInterface $webform): array {
    $settings = (array) $webform->getThirdPartySettings(self::THIRD_PARTY);

    $legal = [];
    foreach ((array) ($settings['legal_basis'] ?? []) as $entry) {
      if (is_string($entry) && $entry !== '') {
        $legal[] = ['title' => $entry];
        continue;
      }
      if (!is_array($entry) || !isset($entry['title']) || (string) $entry['title'] === '') {
        continue;
      }
      $row = ['title' => (string) $entry['title']];
      if (!empty($entry['url'])) {
        $row['url'] = (string) $entry['url'];
      }
      $legal[] = $row;
    }

    $have_ready = [];
    foreach ((array) ($settings['have_ready'] ?? []) as $item) {
      if (is_scalar($item) && (string) $item !== '') {
        $have_ready[] = (string) $item;
      }
    }

    return [
      'intro' => $this->textOrNull($settings['intro'] ?? NULL),
      'have_ready' => $have_ready,
      'legal_basis' => $legal,
      'expected_response' => $this->textOrNull($settings['expected_response'] ?? NULL),
      'next_steps' => $this->textOrNull($settings['next_steps'] ?? NULL),
      'recipient' => $this->recipient(),
    ];
  }

  /**
   * The authority the citizen is writing to, as far as the site knows it.
   *
   * The site holds a Digital Post sender display name
   * (aabenforms_digital_post.settings:sender_name) and, in a multi-tenant
   * install, the tenant (domain) label. Department, phone, e-mail and opening
   * hours are not configured anywhere yet and come back as null so the
   * frontend can hide them.
   *
   * @return array{name: ?string, department: ?string, phone: ?string, email: ?string, hours: ?string}
   *   The recipient block.
   */
  protected function recipient(): array {
    $name = $this->textOrNull($this->configFactory->get('aabenforms_digital_post.settings')->get('sender_name'));
    if ($name === NULL && $this->tenantResolver->isMultiTenant()) {
      $name = $this->textOrNull($this->tenantResolver->getTenantName());
    }
    return [
      'name' => $name,
      'department' => NULL,
      'phone' => NULL,
      'email' => NULL,
      'hours' => NULL,
    ];
  }

  /**
   * Collects the visible input keys under a container, in order.
   *
   * @param array $container
   *   The container element (decoded).
   *
   * @return string[]
   *   The keys.
   */
  protected function collectInputKeys(array $container): array {
    $keys = [];
    foreach ($container as $key => $child) {
      if ($this->isElement($key, $child)) {
        $this->collectFrom((string) $key, $child, $keys);
      }
    }
    return $keys;
  }

  /**
   * Adds the element key, or its visible descendants' keys, to the list.
   */
  protected function collectFrom(string $key, array $element, array &$keys): void {
    if ($this->isContainer($element)) {
      foreach ($element as $child_key => $child) {
        if ($this->isElement($child_key, $child)) {
          $this->collectFrom((string) $child_key, $child, $keys);
        }
      }
      return;
    }
    if (in_array((string) ($element['#type'] ?? ''), self::INVISIBLE_TYPES, TRUE)) {
      return;
    }
    $keys[] = $key;
  }

  /**
   * Whether a decoded array entry is an element (not a # property).
   */
  protected function isElement(string|int $key, mixed $value): bool {
    return is_array($value) && !str_starts_with((string) $key, '#');
  }

  /**
   * Whether an element groups other elements rather than holding a value.
   */
  protected function isContainer(array $element): bool {
    if (in_array((string) ($element['#type'] ?? ''), self::CONTAINER_TYPES, TRUE)) {
      return TRUE;
    }
    foreach ($element as $key => $value) {
      if ($this->isElement($key, $value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * A non-empty string, or NULL.
   */
  protected function textOrNull(mixed $value): ?string {
    if (!is_scalar($value)) {
      return NULL;
    }
    $value = trim((string) $value);
    return $value === '' ? NULL : $value;
  }

}
