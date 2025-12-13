/**
 * @file
 * Dashboard JavaScript functionality.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.clientDashboard = {
    attach: function (context, settings) {
      const dashboard = context.querySelector('.webform-client-dashboard');

      if (!dashboard) {
        return;
      }

      // Check if there are any queued or processing reports
      const pendingReports = dashboard.querySelectorAll('.report-status-queued, .report-status-processing');

      if (pendingReports.length > 0) {
        // Poll for status updates every 10 seconds
        const pollInterval = setInterval(() => {
          checkReportStatus();
        }, 10000);

        // Store interval ID so it can be cleared if needed
        dashboard.dataset.pollInterval = pollInterval;
      }

      function checkReportStatus() {
        // Reload the page to get updated report statuses
        // In a more sophisticated implementation, we could use AJAX to fetch just the status
        const currentPending = dashboard.querySelectorAll('.report-status-queued, .report-status-processing');

        if (currentPending.length > 0) {
          // Fetch updated status via AJAX
          fetch(window.location.href, {
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          .then(response => response.text())
          .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newDashboard = doc.querySelector('.dashboard-results');
            const currentDashboardResults = dashboard.querySelector('.dashboard-results');

            if (newDashboard && currentDashboardResults) {
              // Check if any queued/processing reports are now ready
              const newPending = newDashboard.querySelectorAll('.report-status-queued, .report-status-processing');

              if (newPending.length < currentPending.length) {
                // Some reports have finished - reload the page to show updated state
                window.location.reload();
              }
            } else if (newDashboard && !currentDashboardResults) {
              // Dashboard results section appeared - reload
              window.location.reload();
            }
          })
          .catch(error => {
            console.error('Error checking report status:', error);
          });
        } else {
          // No more queued/processing reports, stop polling
          const intervalId = dashboard.dataset.pollInterval;
          if (intervalId) {
            clearInterval(parseInt(intervalId));
            delete dashboard.dataset.pollInterval;
          }
        }
      }

      console.log('Dashboard loaded');
    }
  };

})(Drupal);
