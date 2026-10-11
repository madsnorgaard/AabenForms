<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Plugin\Action;

use Drupal\aabenforms_case\Service\FristClock;
use Drupal\aabenforms_core\Employment\EmploymentLookupInterface;
use Drupal\aabenforms_core\Service\CprAccess;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eca\Attribute\EcaAction;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ECA Action: check the sygedagpenge beskæftigelseskrav for a CPR.
 *
 * Looks up employment (eIndkomst) and the employer's sickness report
 * (NemRefusion) and writes, under the result token prefix:
 * - <result>_status: 'met' or 'not_met' (beskæftigelseskravet, §32 stk. 1
 *   nr. 1: 240 hours in the last 6 completed calendar months, 40 hours in
 *   at least 5 of them; borger.dk "Sygedagpenge, hvis du er lønmodtager");
 * - <result>_employer, <result>_hours: employer name and total hours;
 * - <result>_first_sick_day, <result>_employer_period_end: from the report
 *   (falling back to the submitted first sick day), as d-m-Y;
 * - <result>_opfoelgning: first sick day + opfoelgning_weeks calendar weeks
 *   (FristClock), the latest date for the kommune's first vurdering.
 *   borger.dk states the kommune vurderer the situation within 8 weeks;
 *   using that as the first opfølgningssamtale date is a demo
 *   simplification.
 *
 * Pay is not put in a token: nothing downstream in the flow needs it. The
 * lookup is audited with a hashed CPR and the purpose 'sygedagpenge'.
 *
 * DEMO: backed by DemoEmploymentLookup until eIndkomst / NemRefusion are
 * integrated; the step and audit row say so.
 */
#[Action(
  id: 'aabenforms_employment_check',
  label: new TranslatableMarkup('Check employment requirement (sygedagpenge)'),
  type: 'aabenforms',
)]
#[EcaAction(
  description: new TranslatableMarkup('Checks the sygedagpenge employment requirement from eIndkomst and the employer sickness report (demo rail).'),
  version_introduced: '1.0.0',
)]
class EmploymentCheckAction extends CaseActionBase {

  /**
   * The CPR access helper (decrypts CPR stored at rest).
   */
  protected CprAccess $cprAccess;

  /**
   * The employment lookup (demo rail until eIndkomst is wired).
   */
  protected EmploymentLookupInterface $employmentLookup;

  /**
   * The deadline clock.
   */
  protected FristClock $fristClock;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->cprAccess = $container->get('aabenforms_core.cpr_access');
    $instance->employmentLookup = $container->get('aabenforms_core.employment_lookup');
    $instance->fristClock = $container->get('aabenforms_case.frist_clock');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'cpr_token' => '[webform_submission:values:applicant_cpr:raw]',
      'first_sick_day_token' => '[webform_submission:values:first_sick_day:raw]',
      'result_token' => 'sygedagpenge_employment',
      'opfoelgning_weeks' => 8,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['cpr_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CPR token'),
      '#default_value' => $this->configuration['cpr_token'],
      '#required' => TRUE,
    ];
    $form['first_sick_day_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First sick day token (fallback when no employer report)'),
      '#default_value' => $this->configuration['first_sick_day_token'],
    ];
    $form['result_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Result token prefix'),
      '#default_value' => $this->configuration['result_token'],
      '#required' => TRUE,
    ];
    $form['opfoelgning_weeks'] = [
      '#type' => 'number',
      '#title' => $this->t('Weeks from first sick day to first follow-up'),
      '#default_value' => $this->configuration['opfoelgning_weeks'],
      '#min' => 1,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach (['cpr_token', 'first_sick_day_token', 'result_token'] as $key) {
      $this->configuration[$key] = (string) $form_state->getValue($key);
    }
    $this->configuration['opfoelgning_weeks'] = (int) $form_state->getValue('opfoelgning_weeks');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $prefix = trim((string) ($this->configuration['result_token'] ?? '')) ?: 'sygedagpenge_employment';
    try {
      $cpr = $this->cprAccess->reveal($this->getTokenValue((string) ($this->configuration['cpr_token'] ?? ''), ''));
      $digits = preg_replace('/\D/', '', $cpr) ?? '';
      if ($digits === '') {
        $this->setTokenValue($prefix . '_status', 'not_met');
        $this->recordStep('Beskæftigelseskrav', 'Intet CPR at slå op.', 'failed');
        return;
      }
      $demo = $this->employmentLookup->isDemoMode();
      $employment = $this->employmentLookup->employment($digits);
      $report = $this->employmentLookup->sicknessReport($digits);
      $met = $employment !== NULL && !empty($employment['requirement_met']);

      $firstSickDay = $report['first_sick_day']
        ?? $this->getTokenValue((string) ($this->configuration['first_sick_day_token'] ?? ''), '');
      $first = $this->parseDate($firstSickDay);

      $this->setTokenValue($prefix . '_status', $met ? 'met' : 'not_met');
      $this->setTokenValue($prefix . '_employer', (string) ($employment['employer_name'] ?? ''));
      $this->setTokenValue($prefix . '_hours', (string) ($employment['total_hours'] ?? 0));
      if ($first !== NULL) {
        $weeks = max(1, (int) ($this->configuration['opfoelgning_weeks'] ?? 8));
        $opfoelgning = $this->fristClock->addCalendarWeeks($first->getTimestamp(), $weeks);
        $this->setTokenValue($prefix . '_first_sick_day', $first->format('d-m-Y'));
        $this->setTokenValue($prefix . '_opfoelgning', $this->formatDate($opfoelgning));
      }
      if (isset($report['employer_period_end'])) {
        $end = $this->parseDate($report['employer_period_end']);
        $this->setTokenValue($prefix . '_employer_period_end', $end?->format('d-m-Y') ?? '');
      }

      $this->recordStep('Beskæftigelseskrav', sprintf(
        '%s: %d timer de seneste %d måneder (krav %d).%s',
        $met ? 'Opfyldt' : 'Ikke opfyldt',
        (int) ($employment['total_hours'] ?? 0),
        EmploymentLookupInterface::REFERENCE_MONTHS,
        EmploymentLookupInterface::REQUIRED_HOURS,
        $demo ? ' (demo: eIndkomst og NemRefusion er ikke tilsluttet).' : '',
      ));
      $this->auditLogger->log('employment_lookup', $digits, 'sygedagpenge', 'success', [
        'action_id' => $this->getPluginId(),
        'requirement_met' => $met,
        'sickness_report' => $report !== NULL,
        'demo' => $demo,
      ]);
    }
    catch (\Throwable $e) {
      $this->setTokenValue($prefix . '_status', 'not_met');
      $this->handleError($e, 'Employment check');
    }
  }

  /**
   * Parses a Y-m-d date as Copenhagen midnight, or NULL.
   */
  protected function parseDate(string $date): ?\DateTimeImmutable {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date), new \DateTimeZone('Europe/Copenhagen'));
    return $parsed === FALSE ? NULL : $parsed;
  }

  /**
   * Formats a timestamp as a Copenhagen d-m-Y date.
   */
  protected function formatDate(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('Europe/Copenhagen'))
      ->format('d-m-Y');
  }

}
