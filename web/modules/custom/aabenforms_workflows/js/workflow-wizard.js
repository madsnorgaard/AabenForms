/**
 * @file
 * Wizard interaction hardening.
 *
 * Clicking Back/Next/Create while a wizard AJAX request (e.g. the webform
 * select rebuilding the field mappings) is still in flight posts a stale
 * form_build_id. The cached form state that id resolves to no longer matches,
 * so the wizard falls back to step 1 and every choice the admin made is lost
 * (#196). Lock the navigation buttons for the duration of any AJAX request on
 * the wizard page so that race cannot be entered.
 */
(function (Drupal, once, $) {
  'use strict';

  Drupal.behaviors.aabenformsWizardAjaxLock = {
    attach(context) {
      once('af-wizard-ajax-lock', 'form.workflow-wizard-form', context).forEach((form) => {
        const setLocked = (locked) => {
          form.querySelectorAll('[data-drupal-selector="edit-actions"] input[type="submit"]').forEach((button) => {
            button.disabled = locked;
            button.classList.toggle('is-disabled', locked);
          });
        };
        $(document).on('ajaxSend.afWizardLock', () => setLocked(true));
        $(document).on('ajaxComplete.afWizardLock ajaxStop.afWizardLock', () => setLocked(false));
      });
    },
  };
})(Drupal, once, jQuery);
