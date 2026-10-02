{{-- Verhalten aller [data-beziehung-form]-Formulare (Dubletten-Pruefung,
     Verwandte Kunden, Kundenakte): Elternteil-Auswahl nur bei
     "Elternteil – Kind", Notiz Pflicht bei "Sonstiges". Ereignis-
     Delegation am Dokument - einmal registriert, gilt fuer jedes Formular. --}}
@pushOnce('cspScripts')
<script @cspNonce>
(function () {
    if (window.__beziehungForm) return;
    window.__beziehungForm = true;
    function aktualisiere(form) {
        var gewaehlt = form.querySelector('[name="type"]:checked') || form.querySelector('select[name="type"]');
        var typ = gewaehlt ? gewaehlt.value : '';
        var eltern = form.querySelector('[data-beziehung-eltern]');
        if (eltern) {
            eltern.hidden = typ !== 'elternteil_kind';
            eltern.querySelectorAll('input,select').forEach(function (i) { i.required = typ === 'elternteil_kind'; });
        }
        var notiz = form.querySelector('[data-beziehung-notiz] input');
        if (notiz) notiz.required = typ === 'sonstiges';
    }
    document.addEventListener('change', function (event) {
        var form = event.target.closest('[data-beziehung-form]');
        if (form && event.target.name === 'type') aktualisiere(form);
    });
    function alle() { document.querySelectorAll('[data-beziehung-form]').forEach(aktualisiere); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', alle); else alle();
})();
</script>
@endPushOnce
