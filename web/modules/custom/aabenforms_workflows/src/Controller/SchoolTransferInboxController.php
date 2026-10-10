<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Controller;

use Drupal\aabenforms_workflows\Service\SchoolTransferCaseView;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The school leader's list of co-signed school transfers to decide.
 */
final class SchoolTransferInboxController extends ControllerBase {

  public function __construct(private readonly SchoolTransferCaseView $caseView) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('aabenforms_workflows.school_transfer_case_view'));
  }

  /**
   * Lists the applications awaiting a decision (masked CPR only).
   */
  public function inbox(): array {
    $rows = [];
    foreach ($this->caseView->awaitingDecision() as $submission) {
      $summary = $this->caseView->staffSummary($submission);
      $signatures = [];
      foreach ($summary['signatures'] as $signature) {
        $signatures[] = $signature['name'] !== '' ? $signature['name'] : $signature['cpr_masked'];
      }
      $rows[] = [
        $summary['case_id'] ? '#' . $summary['case_id'] : '-',
        $summary['child_name'] . ' (' . $summary['child_cpr_masked'] . ')',
        $summary['current_school'] . ' → ' . $summary['target_school'],
        implode(', ', $signatures),
        Link::fromTextAndUrl($this->t('Open case'), Url::fromRoute('aabenforms_workflows.school_transfer_decide', [
          'webform_submission' => $summary['submission_id'],
        ])),
      ];
    }

    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Applications that both custody holders have signed. Open a case to approve or reject the school transfer; the decision letter goes to both parents through Digital Post.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => ['data-testid' => 'skoleskift-inbox'],
        '#header' => [
          $this->t('Case'),
          $this->t('Child'),
          $this->t('Schools'),
          $this->t('Signed by'),
          $this->t('Next step'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No school transfers await a decision.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
