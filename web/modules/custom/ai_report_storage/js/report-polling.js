/**
 * @file
 * Auto-refresh polling for pending AI reports.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.aiReportPolling = {
    attach: function (context, settings) {
      // Only run once on page load.
      const reportContainer = context.querySelector('.report-pending');
      if (!reportContainer || reportContainer.dataset.pollingActive) {
        return;
      }

      // Mark as active to prevent multiple intervals.
      reportContainer.dataset.pollingActive = 'true';

      // Poll every 5 seconds.
      const pollInterval = setInterval(function () {
        // Simple page reload - Drupal will handle showing the completed report.
        window.location.reload();
      }, 5000);

      // Clean up on page unload.
      window.addEventListener('beforeunload', function () {
        clearInterval(pollInterval);
      });
    }
  };

})(Drupal);
