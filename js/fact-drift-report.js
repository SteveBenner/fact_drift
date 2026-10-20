(function ($, Drupal, once) {
  Drupal.behaviors.factDriftReportResize = {
    attach(context) {
      if (typeof ResizeObserver === 'undefined') {
        return;
      }
      once('fact-drift-report-resize', '.fact-drift-report__scroll', context).forEach((region) => {
        let frame = null;
        const observer = new ResizeObserver(() => {
          if (frame !== null) {
            return;
          }
          frame = window.requestAnimationFrame(() => {
            frame = null;
            $(window).trigger('resize.tableresponsive');
          });
        });
        observer.observe(region);
      });
    },
  };
})(jQuery, Drupal, once);
