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

  /**
   * Randomizes the icon in page titles.
   */
  function randomizePageTitleIcon() {
    const pageTitle = document.querySelector('#block-tbring-page-title h1');
    if (!pageTitle) return;

    // Generate random number between 1 and 9
    const randomNum = Math.floor(Math.random() * 9) + 1;
    const paddedNum = String(randomNum).padStart(2, '0');

    // Set CSS custom property for the random icon
    pageTitle.style.setProperty('--random-icon', `url("../images/icon-${paddedNum}.svg")`);
  }

  Drupal.behaviors.tbring = {
    attach (context, settings) {
      // Randomize page title icon on page load
      randomizePageTitleIcon();
    }
  };

} (Drupal));
