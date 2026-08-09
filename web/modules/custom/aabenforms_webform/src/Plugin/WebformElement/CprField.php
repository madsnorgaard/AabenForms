<?php

namespace Drupal\aabenforms_webform\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides the 'cpr_field' element for Danish CPR numbers.
 *
 * The plugin id matches the '#type' used by every shipped form and by the
 * frontend's WebformRenderer. It was 'aabenforms_cpr_field' until #172: no
 * form used that id, so the forms silently fell back to Webform's generic
 * element and lost modulus-11 validation and masked display.
 *
 * @WebformElement(
 *   id = "cpr_field",
 *   label = @Translation("CPR Number"),
 *   description = @Translation("Danish CPR number (personnummer) with validation."),
 *   category = @Translation("Danish Government"),
 * )
 */
class CprField extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      'title' => '',
      'description' => '',
      'required' => FALSE,
      'placeholder' => 'DDMMYYXXXX',
      'validate_modulus11' => TRUE,
      'mask_display' => FALSE,
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['cpr'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CPR Settings'),
    ];

    $form['cpr']['validate_modulus11'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Validate using modulus-11 algorithm'),
      '#description' => $this->t('Enable strict validation (note: CPR numbers issued after 2007 may not use modulus-11).'),
      '#default_value' => TRUE,
    ];

    $form['cpr']['mask_display'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mask CPR number in display'),
      '#description' => $this->t('Show CPR as XXXXXX-XXXX instead of full number (for privacy).'),
      '#default_value' => FALSE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, ?WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepare($element, $webform_submission);

    // Add custom validation.
    $element['#element_validate'][] = [get_class($this), 'validateCpr'];

    // Set input attributes.
    $element['#maxlength'] = 10;
    $element['#pattern'] = '\d{10}';
    $element['#attributes']['inputmode'] = 'numeric';

    // Set placeholder if not already set.
    if (empty($element['#placeholder'])) {
      $element['#placeholder'] = 'DDMMYYXXXX';
    }
  }

  /**
   * Form element validation handler for CPR numbers.
   */
  public static function validateCpr(&$element, FormStateInterface $form_state, &$complete_form) {
    $value = $element['#value'];

    if ($value === '') {
      return;
    }

    // Strip whitespace and hyphens.
    $cpr = preg_replace('/[\s-]/', '', $value);

    // Update form state with cleaned value.
    $form_state->setValueForElement($element, $cpr);

    // Validate format.
    if (!preg_match('/^\d{10}$/', $cpr)) {
      $form_state->setError($element, t('CPR number must be exactly 10 digits (format: DDMMYYXXXX).'));
      return;
    }

    // Validate using CPR validator service.
    /** @var \Drupal\aabenforms_webform\Service\CprValidator $validator */
    $validator = \Drupal::service('aabenforms_webform.cpr_validator');

    if (!$validator->isValid($cpr)) {
      $form_state->setError($element, t('Invalid CPR number. Please check the date and check digit.'));
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function formatHtmlItem(array $element, WebformSubmissionInterface $webform_submission, array $options = []) {
    $value = $this->getValue($element, $webform_submission, $options);

    if (empty($value)) {
      return '';
    }

    // Mask CPR if configured.
    if (!empty($element['#mask_display'])) {
      return substr($value, 0, 6) . '-XXXX';
    }

    // Format as DDMMYY-XXXX for readability.
    return substr($value, 0, 6) . '-' . substr($value, 6, 4);
  }

}
