<?php

/**
 * @file
 * Post update functions for aabenforms_core.
 */

use Drupal\locale\Gettext;

/**
 * Imports the bundled Danish interface translations (#198).
 *
 * The deploy pipeline runs drush updb + cim but never locale:update, so the
 * da.po files shipped with the custom modules reach production as files and
 * are never imported into the locale tables - the admin then renders in
 * English for users who selected Danish. Importing here makes a deploy
 * self-contained. Idempotent: re-importing the same file is a no-op, and the
 * import overwrites so string updates in the po files land too.
 *
 * The Danish core/contrib packs are fetched separately by locale's own cron
 * once translation update checking runs; this hook only guarantees the
 * platform's own strings.
 */
function aabenforms_core_post_update_import_danish_interface_translations(): void {
  if (!\Drupal::moduleHandler()->moduleExists('locale')) {
    return;
  }
  \Drupal::moduleHandler()->loadInclude('locale', 'bulk.inc');
  \Drupal::moduleHandler()->loadInclude('locale', 'translation.inc');

  $modules = [
    'aabenforms_core',
    'aabenforms_workflows',
    'aabenforms_mitid',
    'aabenforms_webform',
    'aabenforms_digital_post',
  ];
  $module_list = \Drupal::service('extension.list.module');
  $imported = [];
  foreach ($modules as $module) {
    $path = DRUPAL_ROOT . '/' . $module_list->getPath($module) . '/translations/' . $module . '.da.po';
    if (!is_file($path)) {
      continue;
    }
    $file = locale_translate_file_create($path);
    $file->langcode = 'da';
    $report = Gettext::fileToDatabase($file, [
      'customized' => LOCALE_CUSTOMIZED,
      'overwrite_options' => ['customized' => TRUE, 'not_customized' => TRUE],
    ]);
    $imported[] = $module . ' (' . ($report['additions'] ?? 0) . ' added, ' . ($report['updates'] ?? 0) . ' updated)';
  }

  if ($imported) {
    _locale_refresh_translations(['da']);
    \Drupal::logger('aabenforms_core')->notice('Imported bundled Danish interface translations: @modules', [
      '@modules' => implode(', ', $imported),
    ]);
  }
}
