<?php

namespace Drupal\aabenforms_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Persists and reads workflow execution traces.
 *
 * The WorkflowExecutionCollector produces a per-request step list that would
 * otherwise be lost after the response is sent. This store writes one row per
 * submission into aabenforms_trace so a case can be traced, debugged and
 * demonstrated after the fact - the persisted spine of the evidence dashboard.
 */
class TraceStore {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * Constructs the trace store.
   */
  public function __construct(Connection $database, TimeInterface $time) {
    $this->database = $database;
    $this->time = $time;
  }

  /**
   * Records the execution trace for one submission.
   *
   * Idempotent per submission: a re-save replaces the prior trace so the table
   * never accumulates duplicates for the same sid.
   *
   * @param int $sid
   *   The webform submission id.
   * @param string $webform_id
   *   The webform machine name.
   * @param int|null $case_id
   *   The case opened from the submission, if any.
   * @param array $execution
   *   The WorkflowExecutionCollector::toArray() result.
   */
  public function save(int $sid, string $webform_id, ?int $case_id, array $execution): void {
    $steps = $execution['steps'] ?? [];
    $this->database->delete('aabenforms_trace')
      ->condition('sid', $sid)
      ->execute();
    $this->database->insert('aabenforms_trace')
      ->fields([
        'sid' => $sid,
        'webform_id' => $webform_id,
        'case_id' => $case_id,
        'status' => $execution['status'] ?? 'completed',
        'step_count' => $execution['step_count'] ?? count($steps),
        'mitid_verified' => $this->mitidVerified($steps) ? 1 : 0,
        'steps' => json_encode($steps),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Loads a single trace by submission id.
   *
   * @param int $sid
   *   The submission id.
   *
   * @return array|null
   *   The trace row with 'steps' decoded to an array, or NULL if none.
   */
  public function load(int $sid): ?array {
    $row = $this->database->select('aabenforms_trace', 't')
      ->fields('t')
      ->condition('sid', $sid)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['steps'] = $row['steps'] ? json_decode($row['steps'], TRUE) : [];
    return $row;
  }

  /**
   * Returns the most recent traces for the trace list view.
   *
   * @param int $limit
   *   Maximum number of rows.
   *
   * @return array[]
   *   Trace rows (steps left as JSON; the list view does not need them).
   */
  public function recent(int $limit = 50): array {
    return $this->database->select('aabenforms_trace', 't')
      ->fields('t', ['id', 'sid', 'webform_id', 'case_id', 'status', 'step_count', 'mitid_verified', 'created'])
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('sid');
  }

  /**
   * Whether the step list shows a genuinely verified MitID identity.
   *
   * A step is the MitID gate when its id is aabenforms_mitid_validate. It only
   * counts as verified when it completed AND its description does not admit
   * demo mode ("identity was NOT verified").
   *
   * @param array $steps
   *   The decoded step list.
   *
   * @return bool
   *   TRUE when identity was really verified.
   */
  protected function mitidVerified(array $steps): bool {
    foreach ($steps as $step) {
      if (($step['id'] ?? '') === 'aabenforms_mitid_validate') {
        $completed = ($step['status'] ?? '') === 'completed';
        $demo = stripos($step['description'] ?? '', 'NOT verified') !== FALSE
          || stripos($step['description'] ?? '', 'demo') !== FALSE;
        return $completed && !$demo;
      }
    }
    return FALSE;
  }

}
