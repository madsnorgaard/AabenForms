<?php

namespace Drupal\aabenforms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Readiness board: an honest, live scorecard of what is real vs mock.
 *
 * AabenForms is the form + logic engine that ties Denmark's digitalisation
 * service structure together. This board states plainly which parts are
 * production-grade, which run on mock rails, and which are roadmap - so a
 * client proof-of-concept is built on honesty, not on a hidden demo seam.
 * Live counts (submissions, cases, traces) are read from the database so the
 * board doubles as proof the engine is actually running.
 */
class ReadinessController extends ControllerBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * Platform capabilities that are genuinely demoable end to end.
   *
   * [capability, status, evidence].
   */
  protected const CAPABILITIES = [
    [
      'Field-level CPR encryption (AES-256)', 'ready',
      'Fail-closed, ciphertext at rest, env-keyed - refuses to store plaintext; regression-tested across every CPR element type (#172)',
    ],
    [
      'GDPR audit logging', 'ready',
      'Every sensitive access hashed (SHA-256), logged with purpose, tenant-scoped (#174)',
    ],
    ['Case (sag) lifecycle', 'ready', 'Lawful transitions, frist clock, immutable once closed'],
    ['Evidence trace dashboard', 'ready', 'Per-submission trace across every service contract (this tool)'],
    [
      'MitID OIDC - protocol layer', 'ready',
      'Real PKCE + RS256/JWKS verification + NSIS LoA enforced at login and per flow at the workflow gate (#157)',
    ],
    [
      'Multi-tenant data isolation', 'ready',
      'Per-tenant access control + query scoping + per-tenant CPR keys; kernel-tested cross-tenant deny (#141)',
    ],
    [
      'Procurement compliance pack', 'ready',
      'Databehandleraftale, Art. 30 record, DPIA and NIS2 incident runbook drafted in docs/compliance (#92); tilgængelighedserklæring awaits the frontend WCAG audit',
    ],
  ];

  /**
   * Danish government integration status.
   *
   * [integration, contract, status, what-is-missing]. Status is one of
   * live-capable | mock | stub. Source: integration readiness audit.
   */
  protected const INTEGRATIONS = [
    [
      'MitID / NemLog-in', 'OIDC', 'live-capable',
      'OIDC crypto is production-grade, but a production KOMMUNE login is OIOSAML 3 (SAML) via '
      . 'NemLog-in, not OIDC; the OIDC rail is demo/private-sector (#79)',
    ],
    [
      'CPR person lookup', 'SF1520', 'mock',
      'Real KOMBIT protocol (InvocationContext, OCES3 mTLS) + service agreement (#76)',
    ],
    ['CVR company lookup', 'SF1530', 'mock', 'Same certificate + protocol work as SF1520 (#76)'],
    [
      'Digital Post (MeMo)', 'SF1601', 'live-capable',
      'Real MeMo XML builder + SOAP transport built (#77); needs OCES3 cert + serviceaftale; idempotency key outstanding (#73)',
    ],
    ['Fordelingskomponent', 'SF2900', 'stub', 'Full chain: STS SF1512/1514, OCES3-signed SOAP, SFTP, async receipts'],
    [
      'Case journaling', 'SF1470', 'stub',
      'Adapter interface + transactional outbox in place (#84); needs real SOAP registration + KOMBIT compliancetest (#85, #86)',
    ],
    ['ESDH connectors', '-', 'stub', 'Live HTTP transports for SBSYS/GetOrganized/WorkZone/Acadre (framework is real)'],
    ['eIndkomst income', '-', 'stub', 'No real integration - income is demo-synthesised'],
    ['Adressevælger picker', '-', 'mock', 'Protocol-correct REST proxy; needs only a real API URL + token'],
    ['Datafordeler validation', '-', 'stub', 'Authoritative BBR/Matriklen/DAR address validation (#80)'],
    [
      'Beskedfordeler receipts', 'SF1461/62', 'mock',
      'Submodule built: SF1461 delivery status + SF1462 case-completed events (#78); needs live subscription + certs',
    ],
  ];

  /**
   * Pressure-test findings to resolve before a client proof-of-concept.
   *
   * [finding, severity, action]. Severity is critical | high | medium.
   * Source: adversarial security pressure-test. Being the glass box means
   * showing these plainly, not hiding them behind a green demo.
   */
  protected const FINDINGS = [
    [
      'Production kommune login needs OIOSAML 3', 'high',
      'The OIDC/MitID rail is demo/private-sector. A real kommune citizen login is OIOSAML 3 (SAML) '
      . 'via NemLog-in at NSIS Substantial; needs the aabenforms_nemlogin SP + broker registration (#79).',
    ],
    [
      'JSON:API and MitID routes are unthrottled', 'high',
      'The webform submit endpoint is flood-controlled, but JSON:API and the MitID session '
      . 'endpoints have no rate limit, leaving enumeration and cost-amplification open (#142).',
    ],
    [
      'MitID session capability travels in the URL', 'medium',
      'The session id rides the redirect as a query parameter (browser history, Referer, proxy '
      . 'logs) and the session endpoint trusts the bearer alone within its 15-minute TTL; needs a '
      . 'one-time exchange or HTTPOnly cookie, coordinated with the frontend (#156).',
    ],
    [
      'Government transports run on mock rails', 'medium',
      'CPR/CVR (SF1520/1530), Digital Post cert + idempotency, SF2900, SF1470, ESDH and '
      . 'eIndkomst need OCES3 certs + a serviceaftale to go live (#76, #73, #85, #86).',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * Renders the readiness board.
   */
  public function board(): array {
    $build = [];
    $build['#attached']['library'][] = 'aabenforms_core/trace';

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('We are the glass box: the form and logic engine that ties the Danish digitalisation service structure together. This board states plainly what is production-grade, what runs on mock rails, and what is roadmap.'),
      '#attributes' => ['class' => ['af-trace-intro']],
    ];

    $build['metrics'] = $this->buildMetrics();
    $build['capabilities'] = $this->buildCapabilities();
    $build['integrations'] = $this->buildIntegrations();
    $build['findings'] = $this->buildFindings();
    return $build;
  }

  /**
   * Builds the pressure-test findings panel (fix before a client POC).
   */
  protected function buildFindings(): array {
    $tones = ['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning'];
    $rows = [];
    foreach (self::FINDINGS as [$finding, $severity, $action]) {
      $rows[] = [
        $finding,
        [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => strtoupper($severity),
            '#attributes' => ['class' => ['af-trace-pill', 'af-trace-pill--' . ($tones[$severity] ?? 'warning')]],
          ],
        ],
        $action,
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Pressure-test findings - resolve before a client POC'),
      ],
      'note' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The POC security holes an adversarial test found have been closed (demo-mode fail-open, client-supplied workflow_id, open-redirect, unthrottled webform submit, and cross-tenant data access are all fixed and tested), and two earlier findings have since landed as well: tenant-scoped audit + trace (#174) and the per-flow NSIS assurance gate (#157). These are the open items on the path to a real kommune pilot.'),
        '#attributes' => ['class' => ['af-trace-intro']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Finding'), $this->t('Severity'), $this->t('Action')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['af-trace-table']],
      ],
    ];
  }

  /**
   * Builds the live-proof metric row (real counts from the database).
   */
  protected function buildMetrics(): array {
    $metrics = [
      [$this->t('Submissions traced'), $this->count('aabenforms_trace')],
      [$this->t('Cases opened'), $this->count('aabenforms_case')],
      [$this->t('Audit events'), $this->count('aabenforms_audit_log')],
      [$this->t('Deployed flows'), $this->countEntities('eca')],
    ];
    $items = [];
    foreach ($metrics as [$label, $value]) {
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['af-metric']],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $value,
          '#attributes' => ['class' => ['af-metric__value']],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => ['class' => ['af-metric__label']],
        ],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-metric-row']],
      'items' => $items,
    ];
  }

  /**
   * Computes the workflow-engine evidence line from live config.
   *
   * The previous text was a frozen snapshot ("23 flows, 0 broken") that
   * drifted from reality within weeks. Counting flows, enabled state and
   * webform-binding resolution live keeps the claim true by construction.
   * Whether a binding targets the RIGHT webform is a human judgement; the
   * flow overview groups flows by journey so a wrong binding stands out.
   *
   * @return string
   *   The evidence sentence.
   */
  protected function flowAuditEvidence(): string {
    try {
      $eca_storage = $this->entityTypeManager()->getStorage('eca');
      $webform_storage = $this->entityTypeManager()->getStorage('webform');
    }
    catch (\Exception) {
      return 'Flow storage unavailable';
    }
    $total = 0;
    $enabled = 0;
    $bindings = 0;
    $missing = [];
    $prefix = 'webform_submission ';
    foreach ($eca_storage->loadMultiple() as $eca) {
      $total++;
      if ($eca->status()) {
        $enabled++;
      }
      foreach ($eca->get('events') ?? [] as $event) {
        $type = (string) ($event['configuration']['type'] ?? '');
        if (str_starts_with($type, $prefix)) {
          $bindings++;
          $webform_id = substr($type, strlen($prefix));
          if (!$webform_storage->load($webform_id)) {
            $missing[] = $webform_id;
          }
        }
      }
    }
    $resolution = $missing === []
      ? 'all resolving to existing webforms'
      : count($missing) . ' targeting missing webforms (' . implode(', ', array_unique($missing)) . ')';
    return sprintf(
      'Live audit: %d flows (%d enabled), %d webform bindings, %s. Binding-to-the-right-form is reviewed per journey in the flow overview.',
      $total, $enabled, $bindings, $resolution,
    );
  }

  /**
   * Builds the "production-grade capabilities" panel.
   */
  protected function buildCapabilities(): array {
    $rows = [];
    $capabilities = array_merge(
      [['Webform → ECA workflow engine', 'ready', $this->flowAuditEvidence()]],
      self::CAPABILITIES,
    );
    foreach ($capabilities as [$capability, $status, $evidence]) {
      $rows[] = [
        $capability,
        [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Ready'),
            '#attributes' => ['class' => ['af-trace-pill', 'af-trace-pill--success']],
          ],
        ],
        $evidence,
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Production-grade platform capabilities'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Capability'), $this->t('Status'), $this->t('Evidence')],
        '#rows' => $rows,
        '#attributes' => ['class' => ['af-trace-table']],
      ],
    ];
  }

  /**
   * Builds the Danish integration status matrix.
   */
  protected function buildIntegrations(): array {
    $tones = ['live-capable' => 'success', 'mock' => 'warning', 'stub' => 'danger'];
    $labels = [
      'live-capable' => $this->t('Live-capable'),
      'mock' => $this->t('Mock'),
      'stub' => $this->t('Roadmap'),
    ];
    $rows = [];
    foreach (self::INTEGRATIONS as [$name, $contract, $status, $missing]) {
      $rows[] = [
        $name,
        [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $contract,
            '#attributes' => ['class' => ['af-trace-node__contract']],
          ],
        ],
        [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $labels[$status],
            '#attributes' => ['class' => ['af-trace-pill', 'af-trace-pill--' . $tones[$status]]],
          ],
        ],
        $missing,
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Danish service integrations - honest status'),
      ],
      'note' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Every mock is labelled in-product (demo flags, SF####-DEMO references) and every live path fails closed rather than silently pretending. Real transports drop in behind stable action interfaces.'),
        '#attributes' => ['class' => ['af-trace-intro']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Integration'),
          $this->t('Contract'),
          $this->t('Status'),
          $this->t('What is needed for production'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['af-trace-table']],
      ],
    ];
  }

  /**
   * Counts rows in a table, tolerating its absence.
   */
  protected function count(string $table): int {
    try {
      if (!$this->database->schema()->tableExists($table)) {
        return 0;
      }
      return (int) $this->database->select($table)->countQuery()->execute()->fetchField();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Counts config entities of a type (e.g. deployed ECA flows).
   */
  protected function countEntities(string $entity_type): int {
    try {
      return (int) $this->entityTypeManager()->getStorage($entity_type)->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

}
