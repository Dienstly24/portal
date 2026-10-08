{{-- Sofort-Suche fuer jedes [data-verknuepfen-form] der Seite ("Verknuepfte
     Kunden", "Haushalt", "Weitere Personen" am Vertrag). Trefferliste per textContent (Kundennamen sind
     Fremddaten). --}}
@pushOnce('cspScripts')
<script @cspNonce>
(function () {
  document.querySelectorAll('[data-verknuepfen-suche]').forEach(function (feld) {
    var form = feld.closest('[data-verknuepfen-form]');
    var liste = form.querySelector('[data-verknuepfen-treffer]');
    var auswahl = form.querySelector('[data-verknuepfen-auswahl]');
    var idFeld = form.querySelector('[data-verknuepfen-id]');
    var timer = null;
    feld.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var q = feld.value.trim();
            liste.textContent = '';
            if (q.length < 2) return;
            var url = feld.dataset.url + '?q=' + encodeURIComponent(q) + '&exclude=' + encodeURIComponent(feld.dataset.exclude);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    liste.textContent = '';
                    (data.customers || []).forEach(function (c) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'btn btn-ghost';
                        b.style.cssText = 'display:block;width:100%;text-align:left;padding:6px 10px;font-size:12.5px;margin-bottom:4px;';
                        b.textContent = c.name + ' · ' + (c.number || '—');
                        b.addEventListener('click', function () {
                            idFeld.value = c.id;
                            auswahl.hidden = false;
                            auswahl.textContent = 'Ausgewählt: ' + c.name + ' · ' + (c.number || '—');
                            liste.textContent = '';
                            feld.value = '';
                        });
                        liste.appendChild(b);
                    });
                    if (!data.customers || data.customers.length === 0) {
                        liste.textContent = 'Kein Kunde gefunden.';
                    }
                });
        }, 250);
    });
    form.addEventListener('submit', function (e) {
        // data-verknuepfen-optional: die Akte ist freiwillig (z. B. versicherte
        // Person ohne eigene Kundenakte) - der Server prueft, ob ein Name da ist.
        if (!idFeld.value && !form.hasAttribute('data-verknuepfen-optional')) { e.preventDefault(); alert('Bitte zuerst einen Kunden aus der Suche auswählen.'); }
    });
  });
})();
</script>
@endPushOnce
