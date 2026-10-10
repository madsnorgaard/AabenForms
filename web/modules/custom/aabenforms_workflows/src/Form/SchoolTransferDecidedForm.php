<?php

declare(strict_types=1);

namespace Drupal\aabenforms_workflows\Form;

use Drupal\aabenforms_core\Service\FrontendUrl;
use Drupal\aabenforms_workflows\Service\SchoolTransferCaseView;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows the school leader the outcome of a decision, with a sign-out.
 *
 * The sign-out button is a form submit (Form API CSRF token), ends the staff
 * session and returns to the frontend demo, built from the allowlisted
 * frontend origin only.
 */
class SchoolTransferDecidedForm extends FormBase {

  public function __construct(
    protected SchoolTransferCaseView $caseView,
    protected FrontendUrl $frontendUrl,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aabenforms_workflows.school_transfer_case_view'),
      $container->get('aabenforms_core.frontend_url'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenforms_school_transfer_decided';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?WebformSubmissionInterface $webform_submission = NULL): array {
    if ($webform_submission === NULL || $webform_submission->getWebform()?->id() !== SchoolTransferCaseView::WEBFORM_ID) {
      throw new NotFoundHttpException();
    }
    $receipt = $this->caseView->receipt($webform_submission);
    $decided = $receipt['stage'] === 'decided';

    $form['#attributes']['data-testid'] = 'skoleskift-decided';
    $form['status'] = [
      '#theme' => 'status_messages',
      '#message_list' => [
        ($decided ? 'status' : 'warning') => [
          $decided
            ? $this->t('The decision is registered on the case and the decision letter has been sent.')
            : $this->t('The decision has not been registered on the case. Check the flow log.'),
        ],
      ],
      '#status_headings' => [
        'status' => $this->t('Decision sent'),
        'warning' => $this->t('No decision registered'),
      ],
    ];

    $decision = match ($receipt['afgoerelse_type']) {
      'medhold' => (string) $this->t('School transfer approved'),
      'afslag' => (string) $this->t('School transfer rejected'),
      default => '-',
    };
    $sentTo = $receipt['decision_letters_sent_to'] ? implode(', ', $receipt['decision_letters_sent_to']) : '-';
    $items = [
      [$this->t('Case'), $receipt['case_id'] ? '#' . $receipt['case_id'] : '-'],
      [$this->t('Child'), $receipt['child_name']],
      [$this->t('Decision'), $decision],
      [$this->t('Appeal deadline'), $receipt['klagefrist'] ?? '-'],
      [$this->t('Decision letter sent to'), $sentTo],
    ];
    $rows = '';
    foreach ($items as [$label, $value]) {
      $rows .= '<dt>' . Html::escape((string) $label) . '</dt><dd>' . Html::escape((string) $value) . '</dd>';
    }
    $form['summary'] = ['#markup' => '<dl class="skoleskift-summary">' . $rows . '</dl>'];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['logout'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log out and return to the demo'),
      '#button_type' => 'primary',
    ];
    $form['actions']['inbox'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to the list'),
      '#url' => Url::fromRoute('aabenforms_workflows.school_transfer_inbox'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    user_logout();
    $url = $this->frontendUrl->build('/demo/skoleskift', ['som' => 'lars', 'afgjort' => '1']);
    if ($url !== NULL) {
      $form_state->setResponse(new TrustedRedirectResponse($url));
      return;
    }
    $form_state->setRedirect('<front>');
  }

}
