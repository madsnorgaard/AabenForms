<?php

namespace Drupal\aabenforms_webform\Element;

use Drupal\Core\Render\Element\Textfield;

/**
 * Provides the 'cpr_field' render element for Danish CPR numbers.
 *
 * Backs the cpr_field webform element plugin (CprField in
 * Plugin/WebformElement) so server-rendered forms get a real input. The
 * citizen-facing SPA renders its own CPR input from the element type; this
 * covers the Drupal-rendered paths (admin test, preview, employee forms).
 *
 * @FormElement("cpr_field")
 */
class CprField extends Textfield {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The element info.
   */
  public function getInfo() {
    $info = parent::getInfo();
    $info['#maxlength'] = 10;
    $info['#pattern'] = '\d{10}';
    $info['#attributes']['class'][] = 'cpr-field';
    $info['#attributes']['inputmode'] = 'numeric';
    $info['#attributes']['autocomplete'] = 'off';
    return $info;
  }

}
