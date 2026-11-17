/* global readablerSettings, Readabler */
(function () {
  'use strict';

  var settings       = (typeof readablerSettings !== 'undefined') ? readablerSettings : {};
  var basePath       = settings.path || '';
  var buttonPosition = settings.buttonPosition || 'bottom-right';

  document.addEventListener('DOMContentLoaded', function () {
    try {
      new Readabler({
        path: basePath,
        accessibilityStatementLink: basePath + 'accessibility-statement.html',
        buttonPosition: buttonPosition,
        // Evita che MicroModal imposti overflow:hidden sul body quando il
        // pannello si apre: alcuni temi WordPress hanno già overflow:hidden
        // via CSS e il ripristino a "" lascerebbe lo scroll bloccato.
        popupScroll: true,
      });
    } catch (e) {
      console.warn('Readabler init error:', e);
    }

    // -----------------------------------------------------------------------
    // Fix – Firefox: -moz-transform su body non espande l'area di scroll.
    // Quando il widget scala il body con scale(), aggiustiamo le dimensioni
    // minime di <html> in modo che l'area scrollabile si espanda di conseguenza.
    // -----------------------------------------------------------------------
    if ( navigator.userAgent.toLowerCase().indexOf('firefox') > -1 ) {
      var firefoxBodyObserver = new MutationObserver(function () {
        var transform = document.body.style.getPropertyValue('-moz-transform');
        if ( transform && transform !== 'none' ) {
          var match = transform.match(/scale\(\s*([\d.]+)\s*\)/);
          if ( match ) {
            var scale = parseFloat(match[1]);
            document.documentElement.style.setProperty('min-height', (scale * 100) + 'vh');
            document.documentElement.style.setProperty('min-width',  (scale * 100) + 'vw');
          }
        } else {
          document.documentElement.style.removeProperty('min-height');
          document.documentElement.style.removeProperty('min-width');
        }
      });
      firefoxBodyObserver.observe(document.body, { attributes: true, attributeFilter: ['style'] });
    }

    // -----------------------------------------------------------------------
    // Fix – Tutti i browser: MicroModal imposta body.style.overflow = 'hidden'
    // all'apertura del popup, rimuovendo lo scroll orizzontale.
    // Intercettiamo la modifica e la annulliamo immediatamente.
    // -----------------------------------------------------------------------
    var overflowObserver = new MutationObserver(function () {
      if ( document.body.style.overflow === 'hidden' ) {
        overflowObserver.disconnect();
        document.body.style.overflow = '';
        overflowObserver.observe(document.body, { attributes: true, attributeFilter: ['style'] });
      }
    });
    overflowObserver.observe(document.body, { attributes: true, attributeFilter: ['style'] });

  });

})();
