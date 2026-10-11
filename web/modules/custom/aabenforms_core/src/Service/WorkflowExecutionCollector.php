<?php

namespace Drupal\aabenforms_core\Service;

use Drupal\aabenforms_core\Privacy\PiiRedactor;

/**
 * Collects workflow execution steps during a single request.
 *
 * ECA actions call addStep() as they execute. The WebformApiController
 * reads the collected steps after $submission->save() and includes
 * them in the JSON response. This works because ECA workflows execute
 * synchronously during entity save.
 *
 * Step text is redacted on the way in: descriptions can carry token-replaced
 * text (aabenforms_log) or upstream error messages, and the steps end up in
 * the submit response and the persisted trace. Neither may hold a raw CPR.
 */
class WorkflowExecutionCollector {

  /**
   * Collected workflow steps for the current request.
   *
   * @var array
   */
  protected array $steps = [];

  /**
   * The PII redactor.
   */
  protected PiiRedactor $redactor;

  /**
   * Constructs the collector.
   *
   * @param \Drupal\aabenforms_core\Privacy\PiiRedactor|null $redactor
   *   The redactor. Optional so plain `new` in unit tests keeps working; the
   *   default instance applies the same rules.
   */
  public function __construct(?PiiRedactor $redactor = NULL) {
    $this->redactor = $redactor ?? new PiiRedactor();
  }

  /**
   * Records a completed workflow step.
   *
   * @param string $actionId
   *   The ECA action plugin ID.
   * @param string $label
   *   Human-readable step name.
   * @param string $description
   *   What this step did.
   * @param string $status
   *   Step status: 'completed' or 'failed'.
   * @param string|null $error
   *   Error message if failed.
   */
  public function addStep(string $actionId, string $label, string $description, string $status = 'completed', ?string $error = NULL): void {
    $this->steps[] = [
      'id' => $actionId,
      'name' => $this->redactor->redactString($label),
      'description' => $this->redactor->redactString($description),
      'status' => $status,
      'completed_at' => date('c'),
      'error' => $error === NULL ? NULL : $this->redactor->redactString($error),
    ];
  }

  /**
   * Returns all collected steps, deduplicated by name.
   *
   * ECA workflows with parallel branches (e.g. dual parent approval)
   * fire the same action multiple times. We keep only the first
   * occurrence of each step name to avoid cluttering the UI.
   */
  public function getSteps(): array {
    $seen = [];
    $unique = [];
    foreach ($this->steps as $step) {
      if (!isset($seen[$step['name']])) {
        $seen[$step['name']] = TRUE;
        $unique[] = $step;
      }
    }
    return $unique;
  }

  /**
   * Whether any steps were collected.
   */
  public function hasSteps(): bool {
    return !empty($this->steps);
  }

  /**
   * Returns the workflow result as an array for JSON serialization.
   */
  public function toArray(): array {
    $steps = $this->getSteps();
    return [
      'status' => $this->hasFailedSteps() ? 'failed' : 'completed',
      'step_count' => count($steps),
      'steps' => $steps,
    ];
  }

  /**
   * Whether any step has a 'failed' status.
   */
  protected function hasFailedSteps(): bool {
    foreach ($this->steps as $step) {
      if ($step['status'] === 'failed') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
