@extends('layouts.admin')
@section('content')
@php
// Menschlich lesbare Bezeichnungen fuer die Vorschau (welche Daten werden uebertragen).
$labels = [
    'contracts' => 'Verträge', 'documents' => 'Dokumente', 'tickets' => 'Tickets',
    'appointments' => 'Termine', 'customer_notes' => 'Notizen', 'customer_family' => 'Familienmitglieder',
    'customer_vehicles' => 'Fahrzeuge', 'customer_timeline' => 'Timeline-Einträge',
    'customer_change_requests' => 'Änderungswünsche', 'customer_addresses' => 'Adressen',
    'customer_contacts' => 'Kontaktdaten', 'internal_messages' => 'Interne Nachrichten',
    'customer_messages' => 'Portal-Nachrichten', 'customer_consents' => 'Einwilligungen (DSGVO)',
    'document_requests' => 'Dokumentanfragen', 'tasks' => 'Aufgaben', 'email_messages' => 'E-Mail-Zuordnungen',
    'approval_requests' => 'Freigaben', 'employee_customers' => 'Betreuer-Zuordnungen',
    'external_references' => 'Externe Kennungen', 'customer_relationships' => 'Verwandte-Kunden-Verknüpfungen',
    'contract_histories' => 'Vertragshistorie', 'meter_readings' => 'Zählerstände',
    'provisions' => 'Provisionen', 'customer_views' => 'Zuletzt-geöffnet-Einträge',
    'favorite_customers' => 'Favoriten-Markierungen',
];
@endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Zusammenführen</span></div>
    <div class="page-title">Kunden zusammenführen</div>
</div>
<div class="card" style="max-width:680px;">
    <div style="background:#FEF3C7;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13.5px;color:#92400E;line-height:1.6;">
        ⚠ <strong>Hauptkunde:</strong> {{ $customer->user?->name }} ({{ $customer->customer_number }})<br>
        Alle Verträge, Tickets, Dokumente, Familie, Fahrzeuge, Notizen, Nachrichten, Einwilligungen und Termine des Duplikats werden auf den Hauptkunden übertragen. Fehlende Stammdaten werden ergänzt. <strong>Es wird nichts gelöscht</strong> außer der dann leeren Duplikat-Akte. Diese Aktion kann nicht rückgängig gemacht werden.<br><br>
        🔐 <strong>Portal-Zugang bleibt erhalten:</strong> Der besser gepflegte Login-Account (echte E-Mail-Adresse statt Import-Platzhalter, gesetztes Passwort, erfolgte Logins) überlebt automatisch – unabhängig davon, welche Akte als Hauptkunde gewählt ist. Eine zweite echte E-Mail-Adresse wird als alternative E-Mail gesichert.
    </div>

    @if($suggested)
    <div style="background:#D9F4E6;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#131A17;line-height:1.6;">
        <strong>🔎 Möglicher Treffer erkannt:</strong> „{{ $suggested->user?->name }}" ({{ $suggested->customer_number }}) ähnelt diesem Kunden und ist unten vorausgewählt. Bitte prüfen.
        @if(!empty($preview))
        <div style="margin-top:10px;">Diese Daten würden übernommen:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
            @foreach($preview as $table => $count)
            <span style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:3px 9px;font-size:12px;">{{ $count }}× {{ $labels[$table] ?? $table }}</span>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    <form method="POST" action="{{ route('admin.customer.merge.do', $customer->id) }}" onsubmit="return confirm('Wirklich zusammenführen? Alle Daten des Duplikats werden übertragen, die leere Duplikat-Akte danach gelöscht.');">
        @csrf
        {{-- Sofort-Suche statt einer Auswahlliste ueber den ganzen Bestand:
             das <select> wuchs mit jedem Neukunden mit. --}}
        <div class="field">
            <label>Duplikat auswählen (wird nach Übertragung entfernt)</label>
            <div style="position:relative;">
                <input type="text" id="dup-search" autocomplete="off"
                    placeholder="Name, Kundennummer, Telefon oder Anschrift"
                    value="{{ $suggested ? trim(($suggested->user?->name ?? '') . ' · ' . $suggested->customer_number) : '' }}"
                    style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#fff;">
                <div id="dup-dropdown" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:8px;margin-top:4px;max-height:220px;overflow-y:auto;z-index:50;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
            </div>
            <input type="hidden" name="duplicate_id" id="dup-id" value="{{ $suggested->id ?? '' }}" required>
            <div id="dup-hint" style="font-size:12px;color:var(--ink-soft);margin-top:6px;">
                @if($suggested)✓ Vorgeschlagener Treffer ist ausgewählt – bitte prüfen.@else Mindestens zwei Zeichen eingeben.@endif
            </div>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Zusammenführen</button>
            <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
<script>
// Duplikat-Auswahl per Sofort-Suche (admin.customers.search). Der eigene
// Kunde ist ueber "exclude" ausgeschlossen - niemand fuehrt einen Kunden
// mit sich selbst zusammen.
(function () {
    const feld = document.getElementById('dup-search');
    const liste = document.getElementById('dup-dropdown');
    const wert = document.getElementById('dup-id');
    const hinweis = document.getElementById('dup-hint');
    let timer = null;
    let lauf = 0;

    function auswaehlen(id, text) {
        wert.value = id;
        feld.value = text;
        liste.style.display = 'none';
        hinweis.textContent = '✓ Ausgewählt: ' + text;
    }

    feld.addEventListener('input', function () {
        // Jede Aenderung verwirft die bisherige Auswahl - sonst wuerde ein
        // getippter Name mit einer alten ID abgeschickt.
        wert.value = '';
        hinweis.textContent = 'Mindestens zwei Zeichen eingeben.';

        const q = feld.value.trim();
        if (q.length < 2) { liste.style.display = 'none'; return; }

        clearTimeout(timer);
        timer = setTimeout(() => {
            const dieser = ++lauf;
            fetch('{{ route('admin.customers.search') }}?exclude={{ (string) $customer->id }}&q=' + encodeURIComponent(q),
                {headers: {'Accept': 'application/json'}})
                .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
                .then(data => {
                    if (dieser !== lauf) return;
                    const treffer = data.customers || [];
                    liste.innerHTML = '';
                    if (!treffer.length) {
                        const leer = document.createElement('div');
                        leer.style.cssText = 'padding:12px 16px;color:var(--ink-soft);font-size:13px;';
                        leer.textContent = 'Keine Kunden gefunden';
                        liste.appendChild(leer);
                    } else {
                        treffer.forEach(c => {
                            const text = [c.name, c.number].filter(Boolean).join(' · ');
                            const zeile = document.createElement('div');
                            zeile.style.cssText = 'padding:11px 16px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line);';
                            zeile.onmouseover = () => zeile.style.background = '#F8F9FA';
                            zeile.onmouseout = () => zeile.style.background = '#fff';
                            // textContent: Kundennamen sind Fremddaten.
                            const oben = document.createElement('div');
                            oben.style.fontWeight = '600';
                            oben.textContent = text;
                            const unten = document.createElement('div');
                            unten.style.cssText = 'font-size:12px;color:var(--ink-soft);';
                            unten.textContent = c.email || '';
                            zeile.appendChild(oben);
                            zeile.appendChild(unten);
                            zeile.addEventListener('click', () => auswaehlen(c.id, text));
                            liste.appendChild(zeile);
                        });
                    }
                    liste.style.display = 'block';
                })
                .catch(() => {
                    if (dieser !== lauf) return;
                    liste.innerHTML = '<div style="padding:12px 16px;color:#A32D2D;font-size:13px;">Suche nicht erreichbar – bitte erneut versuchen.</div>';
                    liste.style.display = 'block';
                });
        }, 200);
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('#dup-search') && !e.target.closest('#dup-dropdown')) {
            liste.style.display = 'none';
        }
    });
})();
</script>
@endsection
