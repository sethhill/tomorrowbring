/**
 * @file
 * JavaScript for the regenerate reports form.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.regenerateReportsForm = {
    attach: function (context, settings) {
      // Handle select all checkbox.
      var $selectAll = $('#edit-select-all', context);
      var $checkboxes = $('#edit-report-types input[type="checkbox"]', context);

      $selectAll.once('regenerate-form-select-all').on('change', function () {
        var isChecked = $(this).is(':checked');
        $checkboxes.prop('checked', isChecked);
      });

      // Update select all checkbox when individual checkboxes change.
      $checkboxes.once('regenerate-form-checkbox').on('change', function () {
        var allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
        $selectAll.prop('checked', allChecked);
      });
    }
  };

})(jQuery, Drupal);
