/**
 * @file
 * Tbring behaviors.
 */
(function (Drupal) {

  'use strict';


  /**
   * Adjusts logo spans to fill their width using font variation settings.
   */
  function adjustLogoSpans() {
    const logo = document.querySelector('#block-tbring-logo');
    if (!logo) return;

    const logoSpans = logo.querySelectorAll('div > span');
    logoSpans.forEach(span => {
      const targetWidth = Math.floor(window.innerWidth * 0.333);

      let wdth = 100;
      let iterations = 0;
      const maxIterations = 50;

      // Binary search to find the optimal wdth value.
      let minWdth = 10;
      let maxWdth = 1000;

      while (iterations < maxIterations) {
        span.style.fontVariationSettings = `"wdth" ${wdth}`;
        const textWidth = span.scrollWidth;

        if (Math.abs(textWidth - targetWidth) < 1) {
          break;
        }

        if (textWidth < targetWidth) {
          minWdth = wdth;
          wdth = (wdth + maxWdth) / 2;
        } else {
          maxWdth = wdth;
          wdth = (minWdth + wdth) / 2;
        }
        iterations++;
      }

    });
  }

  Drupal.behaviors.tbring = {
    attach (context, settings) {

      // Adjust logo spans on initial load.
      adjustLogoSpans();

      // Adjust logo spans on window resize.
      window.addEventListener('resize', adjustLogoSpans);

    }
  };

} (Drupal));
