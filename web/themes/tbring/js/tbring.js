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


      // Reset any previous transform before calculating.
      span.style.transform = '';

      let wdth = 10;
      let iterations = 0;
      const maxIterations = 20;

      // Binary search to find the optimal wdth value.
      let minWdth = 10;
      let maxWdth = 1000;
      let textWidth = span.scrollWidth;

      while (iterations < maxIterations) {
        span.style.fontVariationSettings = `"wdth" ${wdth}`;
        textWidth = span.scrollWidth;


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

      textWidth = span.scrollWidth;

      // If we're already at the maximum width, use scaleX to adjust further.
      // Iterate 3 times to refine the scale value.
      if (Math.round(wdth) === 1000) {
        for (let i = 0; i < 3; i++) {
          textWidth = span.getBoundingClientRect().width;
          const scale = targetWidth / textWidth;
          span.style.transform = `scaleX(${scale})`;
        }
      }

    });
  }

  Drupal.behaviors.tbring = {
    attach (context, settings) {

      // Adjust logo spans on initial load.
      // adjustLogoSpans();

      // Adjust logo spans on window resize.
      // window.addEventListener('resize', adjustLogoSpans);

      // Adjust logo spans when returning to the tab.
      // document.addEventListener('visibilitychange', () => {
      //   if (document.visibilityState === 'visible') {
      //     adjustLogoSpans();
      //   }
      });

    }
  };

} (Drupal));
