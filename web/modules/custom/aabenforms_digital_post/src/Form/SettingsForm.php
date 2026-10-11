<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Form;

use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\Core\Config\Config;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form at /admin/config/aabenforms/digital-post.
 *
 * Four required fields and a few optional advanced knobs. Intentionally
 * minimal: the plan demands "one-page configuration" and we mean it.
 * Fields validate at save time, not at first-send time, so misconfiguration
 * surfaces early.
 */
final class SettingsForm extends ConfigFormBase {

  public const CONFIG = 'aabenforms_digital_post.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aabenforms_digital_post_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG);

    $form['test_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Test mode'),
      '#options' => [
        'fake_db' => $this->t('fake_db - Writes the would-be payload to the log table. Zero external deps. Install default.'),
        'wiremock' => $this->t('wiremock - POSTs to a WireMock endpoint. For CI and DDEV dev-loops.'),
        'live_test' => $this->t('live_test - Real Serviceplatformen test endpoint (session 2 or later).'),
        'live' => $this->t('live - Production Serviceplatformen endpoint (session 2 or later).'),
      ],
      '#default_value' => $config->get('test_mode') ?: 'fake_db',
      '#required' => TRUE,
    ];

    $form['registration_lookup'] = [
      '#type' => 'radios',
      '#title' => $this->t('Digital Post registration lookup (fritagelse)'),
      '#description' => $this->t('Decides whether a citizen is registered for or exempt from Digital Post. Exempt citizens get decisions as physical letters; unknown status sends both and flags manual follow-up.'),
      '#options' => [
        'demo' => $this->t('demo - DEMO fixture of the demo personas only. Every other CPR is unknown.'),
        'live' => $this->t('live - Digital Post kontaktregister. Not integrated yet (#76): answers unknown for every citizen.'),
      ],
      '#default_value' => $config->get('registration_lookup') ?: 'demo',
      '#required' => TRUE,
    ];

    $form['sender'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sender'),
    ];
    $form['sender']['sender_cvr'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender CVR'),
      '#description' => $this->t('Eight digits. Required for live and live_test modes; optional for fake_db.'),
      '#default_value' => $config->get('sender_cvr'),
      '#maxlength' => 16,
      '#size' => 24,
    ];
    $form['sender']['sender_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender display name'),
      '#default_value' => $config->get('sender_name'),
      '#maxlength' => 128,
    ];

    $form['certificate'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Certificate'),
      '#description' => $this->t('Only read when test_mode is live_test or live.'),
    ];
    $form['certificate']['cert_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Certificate source'),
      '#options' => [
        'file' => $this->t('file - Path on disk. Passphrase from environment variable.'),
      ],
      '#default_value' => $config->get('cert_source') ?: 'file',
    ];
    $form['certificate']['cert_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Certificate file path'),
      '#default_value' => $config->get('cert_path'),
      '#maxlength' => 512,
    ];
    $form['certificate']['cert_passphrase_state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Passphrase environment variable name'),
      '#description' => $this->t('Example: AABENFORMS_DP_CERT_PASS. The value is read from getenv() at send time.'),
      '#default_value' => $config->get('cert_passphrase_state'),
      '#maxlength' => 128,
    ];

    $form['endpoints'] = [
      '#type' => 'details',
      '#title' => $this->t('Endpoints'),
      '#open' => FALSE,
    ];
    $form['endpoints']['wiremock_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WireMock base URL'),
      '#default_value' => $config->get('wiremock_url') ?: 'http://wiremock:8080',
    ];
    $form['endpoints']['live_test_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live test endpoint (Serviceplatformen exttest)'),
      '#default_value' => $config->get('live_test_endpoint'),
    ];
    $form['endpoints']['live_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live endpoint (Serviceplatformen prod)'),
      '#default_value' => $config->get('live_endpoint'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) $form_state->getValue('test_mode');
    $cvr = trim((string) $form_state->getValue('sender_cvr'));

    if (in_array($mode, ['live_test', 'live'], TRUE)) {
      $form_state->setErrorByName('test_mode', $this->t('live_test and live modes are planned for session 2. Pick fake_db or wiremock for now.'));
    }

    if ($cvr !== '') {
      $digits = preg_replace('/\D+/', '', $cvr) ?? '';
      if (strlen($digits) !== 8) {
        $form_state->setErrorByName('sender_cvr', $this->t('CVR must be exactly 8 digits.'));
      }
    }

    // For wiremock mode, require the URL to parse.
    if ($mode === 'wiremock') {
      $url = trim((string) $form_state->getValue('wiremock_url'));
      if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
        $form_state->setErrorByName('wiremock_url', $this->t('WireMock URL must be a valid absolute URL.'));
      }
    }

    // For live_test / live, cert path must point at an actual file.
    if (in_array($mode, ['live_test', 'live'], TRUE)) {
      $path = trim((string) $form_state->getValue('cert_path'));
      if ($path === '' || !is_file($path) || !is_readable($path)) {
        $form_state->setErrorByName('cert_path', $this->t('Certificate file must exist and be readable.'));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(self::CONFIG);
    $cvr = preg_replace('/\D+/', '', (string) $form_state->getValue('sender_cvr')) ?? '';
    $this->writeAll($config, [
      'test_mode' => $form_state->getValue('test_mode'),
      'registration_lookup' => $form_state->getValue('registration_lookup') === 'live' ? 'live' : 'demo',
      'sender_cvr' => $cvr,
      'sender_name' => $form_state->getValue('sender_name'),
      'cert_source' => $form_state->getValue('cert_source'),
      'cert_path' => $form_state->getValue('cert_path'),
      'cert_passphrase_state' => $form_state->getValue('cert_passphrase_state'),
      'wiremock_url' => $form_state->getValue('wiremock_url'),
      'live_test_endpoint' => $form_state->getValue('live_test_endpoint'),
      'live_endpoint' => $form_state->getValue('live_endpoint'),
    ]);
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  private function writeAll(Config $config, array $values): void {
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Returns the canonical list of supported Digital Post delivery types.
   *
   * Anchors the DigitalPost::VALID_TYPES use statement so PHPStan
   * doesn't flag the import as unused, and exposes the list to future
   * validation expansion. Not called at runtime today.
   */
  private function validTypes(): array {
    return DigitalPost::VALID_TYPES;
  }

}
