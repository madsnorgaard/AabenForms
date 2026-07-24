<?php

namespace Drupal\aabenforms_core\Controller;

use Drupal\aabenforms_core\Service\TraceStore;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Evidence dashboard: trace a submission through every service touchpoint.
 *
 * AabenForms is the glass box that ties Denmark's digitalisation service
 * structure together. This controller makes that visible: for any submission
 * it reconstructs the workflow trace, the case it opened, every government
 * service contract that was invoked (MitID, SF1520, SF1470, SF1601, SF2900),
 * and the audited state transitions - one screen, nothing hidden.
 */
class EvidenceController extends ControllerBase {

  /**
   * The trace store.
   */
  protected TraceStore $traceStore;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Maps ECA action ids to their Danish service contract and domain.
   *
   * [label, contract, domain-slug]. The domain slug drives the node colour so
   * government handoffs read differently from internal case steps.
   */
  protected const CONTRACT_MAP = [
    'aabenforms_mitid_validate' => ['MitID / NemLog-in', 'OIDC', 'identity'],
    'aabenforms_cpr_lookup' => ['CPR-opslag', 'SF1520', 'serviceplatform'],
    'aabenforms_cvr_lookup' => ['CVR-opslag', 'SF1530', 'serviceplatform'],
    'aabenforms_case_open' => ['Sag oprettet', 'Intern', 'case'],
    'aabenforms_case_journal' => ['Journalisering', 'SF1470', 'esdh'],
    'aabenforms_case_income_lookup' => ['Indkomstopslag', 'eIndkomst', 'serviceplatform'],
    'aabenforms_case_transition' => ['Sagsovergang', 'Intern', 'case'],
    'aabenforms_case_decide' => ['Afgørelse', 'Intern', 'case'],
    'aabenforms_digital_post_send' => ['Digital Post', 'SF1601', 'digitalpost'],
    'aabenforms_case_sf2900_distribute' => ['Fordelingskomponent', 'SF2900', 'distribution'],
    'aabenforms_case_partshoering' => ['Partshøring (FVL §19)', 'Intern', 'case'],
    'aabenforms_case_set_klagefrist' => ['Klagefrist', 'Intern', 'case'],
    'aabenforms_workflow_deny' => ['Afvist ved gate', 'Gate', 'deny'],
  ];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->traceStore = $container->get('aabenforms_core.trace_store');
    $instance->database = $container->get('database');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * Lists recent submission traces.
   */
  public function list(): array {
    $rows = [];
    foreach ($this->traceStore->recent(100) as $trace) {
      $sid = (int) $trace->sid;
      $status_tone = $trace->status === 'failed' ? 'danger' : 'success';
      $rows[] = [
        'data' => [
          [
            'data' => [
              '#type' => 'link',
              '#title' => '#' . $sid,
              '#url' => Url::fromRoute('aabenforms_core.trace', ['sid' => $sid]),
            ],
          ],
          $trace->webform_id,
          $trace->case_id ? 'Sag #' . $trace->case_id : '-',
          [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $trace->status,
              '#attributes' => ['class' => ['af-trace-pill', 'af-trace-pill--' . $status_tone]],
            ],
          ],
          [
            'data' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $trace->mitid_verified ? 'Verificeret' : 'Demo / ingen',
              '#attributes' => [
                'class' => ['af-trace-pill', 'af-trace-pill--' . ($trace->mitid_verified ? 'success' : 'warning')],
              ],
            ],
          ],
          $trace->step_count,
          $this->dateFormatter->format((int) $trace->created, 'short'),
        ],
      ];
    }

    $build = [];
    $build['#attached']['library'][] = 'aabenforms_core/trace';
    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Every submission that ran a workflow leaves a persisted trace here. Open one to follow it through every Danish service contract it touched.'),
      '#attributes' => ['class' => ['af-trace-intro']],
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Submission'),
        $this->t('Form'),
        $this->t('Case'),
        $this->t('Result'),
        $this->t('MitID'),
        $this->t('Steps'),
        $this->t('Time'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No traces yet. Submit a form that runs a workflow (e.g. merudgifter) to generate one.'),
      '#attributes' => ['class' => ['af-trace-table']],
    ];
    return $build;
  }

  /**
   * Renders the full evidence trace for a single submission.
   *
   * @param int $sid
   *   The webform submission id.
   */
  public function trace(int $sid): array {
    $build = [];
    $build['#attached']['library'][] = 'aabenforms_core/trace';

    $trace = $this->traceStore->load($sid);
    if (!$trace) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No trace found for submission #@sid.', ['@sid' => $sid]),
      ];
      return $build;
    }

    $case = $this->loadCase($trace['case_id'] ? (int) $trace['case_id'] : NULL);
    $audit = $this->loadAuditRows($trace['case_id'] ? (int) $trace['case_id'] : NULL, $sid);
    $sf2900 = $this->extractSf2900($audit);

    $build['header'] = $this->buildHeader($sid, $trace, $sf2900);
    $build['summary'] = $this->buildCaseSummary($case, $trace);
    $build['timeline'] = $this->buildTimeline($trace['steps'] ?? [], $sf2900);
    $build['digitalpost'] = $this->buildDigitalPost($trace);
    $build['audit'] = $this->buildAuditTable($audit);
    return $build;
  }

  /**
   * Builds the trace header with the headline evidence badges.
   */
  protected function buildHeader(int $sid, array $trace, ?string $sf2900): array {
    $badges = [];
    $badges[] = $this->badge($this->t('Submission #@sid', ['@sid' => $sid]), 'neutral');
    $badges[] = $this->badge($trace['webform_id'], 'neutral');
    if (!empty($trace['case_id'])) {
      $badges[] = $this->badge($this->t('Sag #@id', ['@id' => $trace['case_id']]), 'brand');
    }
    $badges[] = $trace['status'] === 'failed'
      ? $this->badge($this->t('Flow failed'), 'danger')
      : $this->badge($this->t('Flow completed'), 'success');
    $badges[] = !empty($trace['mitid_verified'])
      ? $this->badge($this->t('MitID verified'), 'success')
      : $this->badge($this->t('MitID demo / not verified'), 'warning');
    if ($sf2900) {
      $badges[] = $this->badge($this->t('SF2900: @txn', ['@txn' => $sf2900]), 'brand');
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-header']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Sagsspor - evidence trace'),
      ],
      'badges' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['af-trace-badges']],
        'items' => $badges,
      ],
    ];
  }

  /**
   * Builds the case summary panel.
   */
  protected function buildCaseSummary($case, array $trace): array {
    if (!$case) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['af-trace-panel']],
        'note' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('This submission did not open a case (no case-opening flow, or it was denied at an identity gate).'),
        ],
      ];
    }

    $rows = [
      [$this->t('Case type'), $this->fieldValue($case, 'case_type')],
      [$this->t('Status'), $this->fieldValue($case, 'status')],
      [$this->t('Decision'), $this->fieldValue($case, 'afgoerelse_type') ?: '-'],
      [$this->t('Journal ref (SF1470)'), $this->fieldValue($case, 'journal_ref') ?: '-'],
      [$this->t('Partshøring'), $this->fieldValue($case, 'partshoering_state') ?: '-'],
      [$this->t('Deadline (frist)'), $this->timestampValue($case, 'frist_due')],
      [$this->t('Appeal deadline (klagefrist)'), $this->timestampValue($case, 'klagefrist')],
    ];
    $table_rows = [];
    foreach ($rows as [$label, $value]) {
      $table_rows[] = [
        ['data' => $label, 'header' => TRUE],
        (string) $value,
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Case (sag)'),
      ],
      'table' => [
        '#type' => 'table',
        '#rows' => $table_rows,
        '#attributes' => ['class' => ['af-trace-kv']],
      ],
    ];
  }

  /**
   * Builds the vertical service-touchpoint timeline.
   */
  protected function buildTimeline(array $steps, ?string $sf2900): array {
    $nodes = [];
    foreach ($steps as $step) {
      $id = $step['id'] ?? '';
      [$label, $contract, $domain] = self::CONTRACT_MAP[$id] ?? [$step['name'] ?? $id, '-', 'case'];
      $description = $step['description'] ?? '';
      $failed = ($step['status'] ?? '') === 'failed';
      $demo = stripos($description, 'demo') !== FALSE
        || stripos($description, 'simuleret') !== FALSE
        || stripos($description, 'NOT verified') !== FALSE;
      $mode = $failed ? 'FEJL' : ($demo ? 'DEMO' : 'LIVE');
      $mode_tone = $failed ? 'danger' : ($demo ? 'warning' : 'success');

      // Surface the SF2900 transaction id inline on the distribution node.
      if ($id === 'aabenforms_case_sf2900_distribute' && $sf2900) {
        $description = trim($description . ' · ' . $this->t('Transaktion: @txn', ['@txn' => $sf2900]));
      }

      $nodes[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['af-trace-node', 'af-trace-node--' . $domain]],
        'marker' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '',
          '#attributes' => ['class' => ['af-trace-node__dot']],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['af-trace-node__body']],
          'top' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => ['class' => ['af-trace-node__top']],
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $label,
              '#attributes' => ['class' => ['af-trace-node__label']],
            ],
            'contract' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $contract,
              '#attributes' => ['class' => ['af-trace-node__contract']],
            ],
            'mode' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $mode,
              '#attributes' => ['class' => ['af-trace-pill', 'af-trace-pill--' . $mode_tone]],
            ],
          ],
          'desc' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $description,
            '#attributes' => ['class' => ['af-trace-node__desc']],
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Service touchpoints'),
      ],
      'timeline' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['af-trace-timeline']],
        'nodes' => $nodes,
      ],
    ];
  }

  /**
   * Builds the Digital Post (SF1601 MeMo) panel showing the real message XML.
   *
   * Aabenforms_digital_post_log has no sid/case_id column, so rows are
   * correlated to this trace by time (the send runs synchronously in the same
   * request). Heuristic but reliable for a single-submission trace; labelled as
   * such in the UI.
   */
  protected function buildDigitalPost(array $trace): array {
    if (!$this->database->schema()->tableExists('aabenforms_digital_post_log')) {
      return [];
    }
    $created = (int) ($trace['created'] ?? 0);
    $rows = $this->database->select('aabenforms_digital_post_log', 'l')
      ->fields('l', ['transaction_id', 'subject', 'status', 'payload', 'created'])
      ->condition('created', [$created - 2, $created + 300], 'BETWEEN')
      ->orderBy('created', 'ASC')
      ->range(0, 5)
      ->execute()
      ->fetchAll();
    if (!$rows) {
      return [];
    }

    $items = [];
    foreach ($rows as $row) {
      $payload = json_decode($row->payload ?? '', TRUE) ?: [];
      $xml = $payload['memo_xml'] ?? NULL;
      $pretty = is_string($xml) && $xml !== ''
        ? $this->prettyXml($xml)
        : (string) $this->t('(no MeMo XML - JSON summary only)');
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['af-trace-memo']],
        'meta' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['af-trace-memo__meta']],
          '#value' => $this->t('@subject &middot; tx @tx &middot; @status', [
            '@subject' => $row->subject,
            '@tx' => $row->transaction_id,
            '@status' => $row->status,
          ]),
        ],
        'xml' => [
          '#type' => 'inline_template',
          '#template' => '<pre class="af-trace-xml">{{ xml }}</pre>',
          '#context' => ['xml' => $pretty],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Digital Post (SF1601 MeMo)'),
      ],
      'note' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['af-trace-intro']],
        '#value' => $this->t('The real MeMo message built for this case (mock transport; correlated by time).'),
      ],
      'items' => $items,
    ];
  }

  /**
   * Pretty-prints an XML string, tolerating parse failures.
   */
  protected function prettyXml(string $xml): string {
    $previous = libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = FALSE;
    $dom->formatOutput = TRUE;
    $ok = $dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $ok ? (string) $dom->saveXML() : $xml;
  }

  /**
   * Builds the audit-evidence table.
   */
  protected function buildAuditTable(array $audit): array {
    $rows = [];
    foreach ($audit as $row) {
      $rows[] = [
        $this->dateFormatter->format((int) $row->timestamp, 'short'),
        $row->action,
        $row->purpose,
        [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $row->status,
            '#attributes' => [
              'class' => ['af-trace-pill', 'af-trace-pill--' . ($row->status === 'success' ? 'success' : 'danger')],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['af-trace-panel']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Audit trail (GDPR)'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Time'), $this->t('Action'), $this->t('Purpose'), $this->t('Status')],
        '#rows' => $rows,
        '#empty' => $this->t('No audit rows linked to this case.'),
        '#attributes' => ['class' => ['af-trace-table']],
      ],
    ];
  }

  /**
   * Loads the case entity, tolerating a missing entity type.
   */
  protected function loadCase(?int $case_id) {
    if (!$case_id) {
      return NULL;
    }
    try {
      return $this->entityTypeManager()->getStorage('aabenforms_case')->load($case_id);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Loads audit rows for a case (by hashed identifier) plus the opening row.
   *
   * Case actions log with the case id as the identifier, so its SHA-256 hash
   * matches every case_* row. The case-opening row is keyed differently but
   * carries the submission id in its context JSON.
   */
  protected function loadAuditRows(?int $case_id, int $sid): array {
    if (!$this->database->schema()->tableExists('aabenforms_audit_log')) {
      return [];
    }
    $query = $this->database->select('aabenforms_audit_log', 'a')->fields('a');
    $or = $query->orConditionGroup();
    if ($case_id) {
      $or->condition('identifier_hash', hash('sha256', (string) $case_id));
    }
    // The opening row records the sid in its context JSON.
    $or->condition('context', '%"submission_id":' . $sid . '%', 'LIKE');
    $or->condition('context', '%"submission_id":"' . $sid . '"%', 'LIKE');
    $query->condition($or);
    $query->orderBy('timestamp', 'ASC');
    $query->range(0, 200);
    return $query->execute()->fetchAll();
  }

  /**
   * Extracts the SF2900 transaction id from the audit rows, if present.
   */
  protected function extractSf2900(array $audit): ?string {
    foreach ($audit as $row) {
      if ($row->action === 'case_sf2900_distribute' && !empty($row->context)) {
        $context = json_decode($row->context, TRUE);
        if (!empty($context['transaction_id'])) {
          return $context['transaction_id'];
        }
      }
    }
    return NULL;
  }

  /**
   * Reads a scalar field value from an entity, or '' if absent.
   */
  protected function fieldValue($entity, string $field): string {
    return ($entity && $entity->hasField($field) && !$entity->get($field)->isEmpty())
      ? (string) $entity->get($field)->value
      : '';
  }

  /**
   * Formats a timestamp field, or '-' if empty.
   */
  protected function timestampValue($entity, string $field): string {
    $value = $this->fieldValue($entity, $field);
    return $value !== '' ? $this->dateFormatter->format((int) $value, 'short') : '-';
  }

  /**
   * Builds a header badge render element.
   */
  protected function badge($text, string $tone): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $text,
      '#attributes' => ['class' => ['af-trace-badge', 'af-trace-badge--' . $tone]],
    ];
  }

}
