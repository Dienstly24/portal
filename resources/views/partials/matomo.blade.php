{{-- Reichweitenmessung mit dem EIGENEN Matomo (Betreiber-Entscheidung
     18.09.2026). Begruendung der Wahl gegen GA4: config/analytics.php.

     NICHT EINGERICHTET = NICHTS AUSGELIEFERT. Ohne MATOMO_URL und
     MATOMO_SITE_ID in der Server-.env steht hier kein Byte im HTML und
     kein Host in der Inhaltsrichtlinie. Das ist der Standard.

     NICHTS LAEUFT VOR DER EINWILLIGUNG (Auftrag 19.09.2026): `matomo.js`
     wird erst im Rueckruf von `d24Consent` angefordert. Vor der
     Entscheidung gibt es deshalb KEINE Anfrage an den Matomo-Host und
     keinen Eintrag in der Messung - das laesst sich im Netzwerk-Reiter
     des Browsers nachsehen, es ist keine Zusage im Text.

     FEHLT der Einwilligungs-Banner auf einer Seite, wird NICHT gemessen
     (`window.d24Consent` ist dann nicht da). Die sichere Richtung ist
     "nicht messen" - ein Einbindungsfehler darf nie dazu fuehren, dass
     ungefragt gemessen wird.

     DIESES PARTIAL GEHOERT NUR IN DIE VORLAGEN DER OEFFENTLICHEN
     WEBSITE. Nie in layouts/admin, layouts/app oder layouts/partner: in
     der Beraterwelt und im Portal stehen Kundendaten in Seitentiteln und
     Adressen ("/admin/customers/4711"), und ein Messwerkzeug schreibt
     beides mit. --}}
@php
    $matomoKonf = \App\Support\Matomo::konfiguration();
    $matomoBrauchtEinwilligung = \App\Support\Consent::statistikBrauchtEinwilligung();
@endphp
@if($matomoKonf)
<script @cspNonce>
(function () {
    var starten = function () {
        var _paq = window._paq = window._paq || [];
        /* OHNE KENNUNGEN. Das ist der Kern der Entscheidung gegen GA4:
           ohne Kennung im Browser bleibt die Messung anonym, und sie
           misst jeden Besucher gleich statt nur den, der eine Abfrage
           wegklickt. */
        _paq.push(['disableCookies']);
        /* "Nicht verfolgen" des Browsers wird beachtet. */
        _paq.push(['setDoNotTrack', true]);
        _paq.push(['trackPageView']);
        /* Zaehlt Klicks auf fremde Adressen - damit ist der UEBERGANG
           INS PORTAL messbar (SEO-Auftrag Abschnitt 31), ohne dass im
           Portal selbst irgendetwas gemessen wird. */
        _paq.push(['enableLinkTracking']);

        var u = @json($matomoKonf['url']) + '/';
        _paq.push(['setTrackerUrl', u + 'matomo.php']);
        _paq.push(['setSiteId', @json($matomoKonf['site_id'])]);
        var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
        g.async = true;
        g.src = u + 'matomo.js';
        /* Der nachgeladene Datei-Aufruf braucht KEINEN Nonce: er ist
           durch die Host-Freigabe in script-src gedeckt (ein Nonce wird
           an dynamisch erzeugte Skripte ohnehin nicht vererbt). */
        s.parentNode.insertBefore(g, s);

        /* Die Geschaeftsziele als Ereignisse (SEO-Auftrag Abschnitt 30).
           Ein EINZIGER Zuhoerer am Dokument statt eines je Knopf: die
           Knoepfe tragen bereits data-cta/data-cta-seite, neue zaehlen
           damit ohne Codeaenderung mit. Uebertragen wird ausschliesslich
           die ART des Klicks und die Seite - NIE ein eingegebener Wert,
           nie ein Name, nie eine Adresse. */
        d.addEventListener('click', function (e) {
            var ziel = e.target.closest ? e.target.closest('[data-cta]') : null;
            if (! ziel) { return; }
            _paq.push(['trackEvent', 'Kontakt', ziel.getAttribute('data-cta'),
                ziel.getAttribute('data-cta-seite') || location.pathname]);
        });

        /* Ein abgeschicktes Anfrageformular ist das eigentliche Ziel der
           Website. Gemeldet wird NUR, DASS es abgeschickt wurde, und von
           welcher Seite - kein Feldinhalt verlaesst den Browser. */
        d.addEventListener('submit', function (e) {
            var form = e.target;
            if (! form || ! form.hasAttribute || ! form.hasAttribute('data-cta-formular')) { return; }
            _paq.push(['trackEvent', 'Formular', 'abgeschickt',
                form.getAttribute('data-cta-formular')]);
        });
    };

@if($matomoBrauchtEinwilligung)
    /* Fehlt der Banner, fehlt die Einwilligung - dann wird nicht
       gemessen. Fail-closed, siehe Kommentar oben. */
    if (! window.d24Consent) { return; }
    window.d24Consent.beiFreigabe('statistik', starten);
@else
    /* Der Betreiber hat die Statistik nach eigener rechtlicher Pruefung
       auf "notwendig" gestellt (ANALYTICS_REQUIRES_CONSENT=false). */
    starten();
@endif
})();
</script>
@endif
