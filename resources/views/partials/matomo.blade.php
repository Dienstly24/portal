{{-- Reichweitenmessung mit dem EIGENEN Matomo (Betreiber-Entscheidung
     18.09.2026). Begruendung der Wahl gegen GA4: config/analytics.php.

     NICHT EINGERICHTET = NICHTS AUSGELIEFERT. Ohne MATOMO_URL und
     MATOMO_SITE_ID in der Server-.env steht hier kein Byte im HTML und
     kein Host in der Inhaltsrichtlinie. Das ist der Standard.

     DIESES PARTIAL GEHOERT NUR IN DIE VORLAGEN DER OEFFENTLICHEN
     WEBSITE. Nie in layouts/admin, layouts/app oder layouts/partner: in
     der Beraterwelt und im Portal stehen Kundendaten in Seitentiteln und
     Adressen ("/admin/customers/4711"), und ein Messwerkzeug schreibt
     beides mit. --}}
@php($matomoKonf = \App\Support\Matomo::konfiguration())
@if($matomoKonf)
<script @cspNonce>
var _paq = window._paq = window._paq || [];
/* OHNE KENNUNGEN. Das ist der Kern der Entscheidung gegen GA4: ohne
   Kennung im Browser braucht die Messung (nach heutiger Praxis) keine
   Einwilligung, also wird JEDER Besucher gezaehlt statt nur der, der
   eine Abfrage wegklickt. Die Zahlen sind dadurch vollstaendiger als
   bei einem Werkzeug mit Zustimmungsabfrage - und nicht weniger wert. */
_paq.push(['disableCookies']);
/* "Nicht verfolgen" des Browsers wird beachtet. Kostet ein paar
   Datensaetze und ist die Haltung, die zum Rest dieser Anwendung passt. */
_paq.push(['setDoNotTrack', true]);
_paq.push(['trackPageView']);
/* Zaehlt Klicks auf fremde Adressen - damit ist der UEBERGANG INS
   PORTAL messbar (Auftrag Abschnitt 31), ohne dass im Portal selbst
   irgendetwas gemessen wird. */
_paq.push(['enableLinkTracking']);
(function () {
    var u = @json($matomoKonf['url']) + '/';
    _paq.push(['setTrackerUrl', u + 'matomo.php']);
    _paq.push(['setSiteId', @json($matomoKonf['site_id'])]);
    var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
    g.async = true;
    g.src = u + 'matomo.js';
    /* Der nachgeladene Datei-Aufruf braucht KEINEN Nonce: er ist durch
       die Host-Freigabe in script-src gedeckt (ein Nonce wird an
       dynamisch erzeugte Skripte ohnehin nicht vererbt). */
    s.parentNode.insertBefore(g, s);
})();
/* Die Kontaktwege als Ereignisse (Auftrag Abschnitt 30). Ein
   EINZIGER Zuhoerer am Dokument statt eines je Knopf: die Knoepfe
   tragen bereits data-cta/data-cta-seite, neue Knoepfe werden damit
   ohne Codeaenderung mitgezaehlt. Kein onclick-Attribut - das waere
   seit SEC-4 durch script-src-attr 'none' ohnehin wirkungslos. */
document.addEventListener('click', function (e) {
    var ziel = e.target.closest ? e.target.closest('[data-cta]') : null;
    if (! ziel) { return; }
    _paq.push(['trackEvent', 'Kontakt', ziel.getAttribute('data-cta'),
        ziel.getAttribute('data-cta-seite') || location.pathname]);
});
</script>
@endif
