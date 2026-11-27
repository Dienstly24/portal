{{-- Kundendaten auf einen Blick (Betreiber-Auftrag 27.11.2026, Layout nach
     der Vorlage "modernes CRM"): vier Karten (Persoenlich, Adresse, Bank,
     Versicherung/Weitere) und rechts eine Spalte mit Status und
     Schnellaktionen. Alle Werte direkt aus der Akte
     (App\Support\Kundendatenblatt) - nichts geschaetzt, nichts erfunden.
     Kopiert wird IMMER der Originalwert (data-kopie), nie die Anzeige.
     Sensible Werte sind verdeckt; Kopieren/Aufdecken steht im
     Aktivitaetsprotokoll (nur Feldnamen). --}}
@php
    $kdBereiche = \App\Support\Kundendatenblatt::bereiche($customer);
    $kdIcons = ['persoenlich' => '👤', 'adresse' => '🏠', 'bank' => '🏦', 'weitere' => '🛡️'];
    $kdTitel = ['weitere' => 'Versicherung & weitere Daten'];
    // In der Karte "Versicherung & weitere Daten" stehen diese Felder immer,
    // alle uebrigen nur, wenn sie gefuellt sind (sonst ist die Karte eine
    // Liste von Strichen); der Rest steht unter "Alle Daten anzeigen".
    $kdKern = ['health_insurance_type', 'health_insurance_company', 'health_insurance_number', 'tax_id'];
    $kdAdresse = collect($kdBereiche['adresse']['felder'])->firstWhere('key', 'address_full')['kopie'] ?? '';
    $kdMonat = $aktiveVertraege->sum(fn ($c) => $c->monthlyPremium());
@endphp
<div class="kd-layout" id="kundendaten" data-kd-protokoll="{{ route('admin.customer.data_copied', $customer->id) }}">
    <div class="kd-haupt">
        @foreach($kdBereiche as $kdKey => $kdBereich)
        @php
            $kdFelder = collect($kdBereich['felder']);
            if ($kdKey === 'adresse') {
                $kdFelder = $kdFelder->reject(fn ($f) => $f['key'] === 'address_full');
            }
            if ($kdKey === 'bank') {
                $kdFelder = $kdFelder->reject(fn ($f) => $f['key'] === 'iban2' && $f['kopie'] === '');
            }
            $kdSichtbar = $kdKey === 'weitere'
                ? $kdFelder->filter(fn ($f) => in_array($f['key'], $kdKern, true))
                : $kdFelder;
            $kdMehr = $kdKey === 'weitere'
                ? $kdFelder->reject(fn ($f) => in_array($f['key'], $kdKern, true))
                : collect();
            $kdMehrGefuellt = $kdMehr->where('kopie', '!=', '')->count();
            $kdSensibelBereich = $kdFelder->where('sensibel', true)->where('kopie', '!=', '')->isNotEmpty();
            $kdBereichText = \App\Support\Kundendatenblatt::bereichAlsText($kdBereich);
        @endphp
        <section class="kd-karte" aria-label="{{ $kdTitel[$kdKey] ?? $kdBereich['titel'] }}">
            <div class="kd-karte-kopf">
                <h2 class="kd-karte-titel"><span class="kd-karte-icon">{{ $kdIcons[$kdKey] ?? '•' }}</span>{{ $kdTitel[$kdKey] ?? $kdBereich['titel'] }}</h2>
                @if($kdKey === 'bank' && $kdSensibelBereich)
                <span class="kd-sicher">🔒 Sicher</span>
                @endif
                @if($kdBereichText !== '')
                <button type="button" class="kd-kopf-knopf kd-kopieren" data-kopie="{{ $kdBereichText }}"
                    data-kd-feld="{{ $kdSensibelBereich ? 'bereich_'.$kdKey : '' }}" title="{{ $kdBereich['titel'] }} kopieren">⧉ Kopieren</button>
                @endif
            </div>

            @if($kdKey === 'adresse')
            <div class="kd-adresse">
                @if($kdAdresse !== '')
                    @foreach(array_map('trim', explode(',', $kdAdresse)) as $kdZeile)
                    <div>{{ $kdZeile }}</div>
                    @endforeach
                @else
                    <div class="kd-leer">Keine Adresse hinterlegt</div>
                @endif
            </div>
            @endif

            <dl class="kd-liste">
                @foreach($kdSichtbar as $f)
                    @include('admin.partials.kundendaten_zeile', ['f' => $f])
                @endforeach
            </dl>

            @if($kdMehr->isNotEmpty())
            <details class="kd-mehr">
                <summary>Alle Daten anzeigen @if($kdMehrGefuellt > 0)<span class="kd-zahl">{{ $kdMehrGefuellt }}</span>@endif</summary>
                <dl class="kd-liste">
                    @foreach($kdMehr as $f)
                        @include('admin.partials.kundendaten_zeile', ['f' => $f])
                    @endforeach
                </dl>
            </details>
            @endif

            @if($kdKey === 'adresse' && $kdAdresse !== '')
            {{-- Nur ein LINK: die Seite laedt keine Karte (jede eingebettete
                 Karte saehe jede Besucher-IP). Geoeffnet wird erst auf Klick. --}}
            <a class="kd-karte-link" href="https://www.openstreetmap.org/search?query={{ urlencode($kdAdresse) }}" target="_blank" rel="noopener noreferrer">📍 Auf Karte anzeigen ↗</a>
            @endif
        </section>
        @endforeach
    </div>

    <aside class="kd-seite">
        <div class="kd-status">
            <div class="kd-status-zeile">
                <div>
                    <div class="kd-status-label">Kunde seit</div>
                    <div class="kd-status-wert">{{ $customer->created_at?->lokal()->format('d.m.Y') ?? '—' }}</div>
                </div>
                <span class="kd-status-pill">{{ $aktiveVertraege->count() }} {{ $aktiveVertraege->count() === 1 ? 'Vertrag' : 'Verträge' }}</span>
            </div>
            <div class="kd-status-label" style="margin-top:14px;">Aktueller Status</div>
            <div class="kd-status-badge">{{ $aktiveVertraege->count() > 0 ? '✓ Aktiv' : 'Kein aktiver Vertrag' }}</div>
            @if($kdMonat > 0)
            <div class="kd-status-label" style="margin-top:14px;">Beitrag aktiver Verträge</div>
            <div class="kd-status-wert">{{ number_format($kdMonat, 2, ',', '.') }} € <span style="font-size:13px;font-weight:500;opacity:.75;">/ Monat</span></div>
            @endif
            <button type="button" class="kd-alle-knopf kd-alle" data-kopie="{{ \App\Support\Kundendatenblatt::alsText($customer) }}" data-kd-felder="alle">📋 Alle Kundendaten kopieren</button>
        </div>

        <div class="kd-schnell">
            <h2 class="kd-karte-titel"><span class="kd-karte-icon">⚡</span>Schnellaktionen</h2>
            @if(! $customer->eigenstaendigkeitsSperre())
            <a href="{{ route('admin.contract.create', $customer->id) }}">📄 Neuen Vertrag erstellen<span>›</span></a>
            @endif
            <button type="button" data-kd-tab="tab-dokumente">⬆️ Dokument hochladen<span>›</span></button>
            <button type="button" data-kd-tab="tab-nachrichten">✉️ Nachricht senden<span>›</span></button>
            <a href="{{ route('admin.tasks', ['customer_id' => $customer->id, 'neu' => 1]) }}">☑️ Aufgabe erstellen<span>›</span></a>
            <button type="button" data-kd-tab="tab-vertraege">📑 Verträge ansehen<span>›</span></button>
        </div>
    </aside>
    <div class="kd-meldung" role="status" aria-live="polite" hidden></div>
</div>

<style>
.kd-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;margin-bottom:20px;align-items:start;}
.kd-haupt{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;}
.kd-karte{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(19,26,23,.04);min-width:0;}
.kd-karte-kopf{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.kd-karte-titel{font-size:16px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;color:var(--ink);flex:1;min-width:0;}
.kd-karte-icon{width:30px;height:30px;border-radius:8px;background:var(--emerald-soft,#E8F7EF);display:inline-flex;align-items:center;justify-content:center;font-size:15px;flex:none;}
.kd-kopf-knopf{border:1px solid var(--line);background:#fff;border-radius:8px;padding:5px 10px;font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer;white-space:nowrap;}
.kd-kopf-knopf:hover{background:var(--canvas);}
.kd-kopf-knopf.kd-ok{background:var(--emerald-soft,#E8F7EF);border-color:var(--emerald);color:var(--emerald-dark,#128a4b);}
.kd-sicher{font-size:11.5px;font-weight:600;color:var(--emerald-dark,#128a4b);background:var(--emerald-soft,#E8F7EF);border-radius:999px;padding:2px 9px;white-space:nowrap;}
.kd-adresse{font-size:15px;font-weight:600;line-height:1.55;color:var(--ink);padding:4px 0 10px;border-bottom:1px solid var(--line);margin-bottom:4px;}
.kd-liste{margin:0;}
.kd-zeile{display:grid;grid-template-columns:minmax(100px,40%) 1fr;gap:8px;align-items:center;padding:6px 0;border-top:1px solid var(--line);}
.kd-liste .kd-zeile:first-child{border-top:none;}
.kd-zeile dt{font-size:12.5px;color:var(--ink-soft);}
.kd-zeile dd{margin:0;display:flex;align-items:center;gap:6px;min-width:0;font-size:13.5px;font-weight:600;color:var(--ink);}
.kd-wert{overflow-wrap:anywhere;min-width:0;}
.kd-verdeckt{font-family:ui-monospace,monospace;}
.kd-leer{color:var(--ink-soft);font-weight:400;}
.kd-geerbt{font-size:10.5px;background:#FEF3C7;color:#92400E;border-radius:999px;padding:1px 7px;white-space:nowrap;}
.kd-knoepfe{margin-left:auto;display:inline-flex;gap:2px;flex-shrink:0;}
.kd-knopf{border:1px solid transparent;background:none;border-radius:6px;cursor:pointer;font-size:13px;line-height:1;padding:5px 6px;opacity:.55;}
.kd-zeile:hover .kd-knopf{opacity:.9;}
.kd-knopf:hover,.kd-knopf:focus-visible{opacity:1;border-color:var(--line);background:var(--canvas);}
.kd-knopf.kd-ok{opacity:1;color:var(--emerald);}
.kd-mehr{margin-top:8px;}
.kd-mehr summary{cursor:pointer;list-style:none;text-align:center;font-size:12.5px;font-weight:600;color:var(--ink);border:1px solid var(--line);border-radius:999px;padding:6px 12px;width:max-content;margin:4px auto 0;}
.kd-mehr summary::-webkit-details-marker{display:none;}
.kd-mehr summary::after{content:' ▾';}
.kd-mehr[open] summary::after{content:' ▴';}
.kd-mehr[open] summary{margin-bottom:8px;}
.kd-zahl{display:inline-block;margin-left:4px;font-size:11px;background:var(--emerald-soft,#E8F7EF);color:var(--emerald-dark,#128a4b);border-radius:999px;padding:0 7px;}
.kd-karte-link{display:inline-block;margin-top:10px;font-size:12.5px;font-weight:600;color:var(--emerald-dark,#128a4b);text-decoration:none;}
.kd-seite{display:flex;flex-direction:column;gap:16px;min-width:0;}
.kd-status{background:linear-gradient(150deg,var(--graphite,#131A17) 0%,#16392A 100%);color:#fff;border-radius:14px;padding:18px;box-shadow:0 4px 14px rgba(19,26,23,.18);}
.kd-status-zeile{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.kd-status-label{font-size:12.5px;opacity:.75;}
.kd-status-wert{font-size:22px;font-weight:700;margin-top:2px;}
.kd-status-pill{font-size:12px;font-weight:700;background:var(--emerald,#17A65B);border-radius:999px;padding:4px 10px;white-space:nowrap;}
.kd-status-badge{display:inline-block;margin-top:6px;font-size:13px;font-weight:700;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:4px 12px;}
.kd-alle-knopf{display:block;width:100%;margin-top:16px;border:0;border-radius:10px;padding:11px 12px;font:inherit;font-size:14px;font-weight:700;cursor:pointer;color:#fff;background:linear-gradient(180deg,#19b463,#128a4b);box-shadow:0 2px 0 rgba(0,0,0,.2);}
.kd-alle-knopf:hover{filter:brightness(1.06);}
.kd-alle-knopf.kd-ok{background:#fff;color:var(--emerald-dark,#128a4b);}
.kd-schnell{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;}
.kd-schnell .kd-karte-titel{margin-bottom:6px;}
.kd-schnell a,.kd-schnell button{display:flex;justify-content:space-between;align-items:center;width:100%;padding:10px 6px;border:0;border-top:1px solid var(--line);background:none;font:inherit;font-size:13.5px;font-weight:600;color:var(--ink);text-decoration:none;cursor:pointer;text-align:left;}
.kd-schnell a:hover,.kd-schnell button:hover{background:var(--canvas);}
.kd-schnell span{color:var(--ink-soft);font-size:16px;}
.kd-meldung{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:60;background:var(--graphite,#131A17);color:#fff;font-size:13px;font-weight:600;border-radius:999px;padding:9px 18px;box-shadow:0 6px 20px rgba(0,0,0,.25);}
.kd-layout [hidden]{display:none!important;}
@media (max-width:1180px){.kd-layout{grid-template-columns:minmax(0,1fr)}.kd-seite{display:grid;grid-template-columns:1fr 1fr}}
@media (max-width:760px){.kd-haupt{grid-template-columns:minmax(0,1fr)}.kd-seite{grid-template-columns:1fr}}
</style>

@pushOnce('cspScripts')
<script @cspNonce>
(function () {
    var karte = document.getElementById('kundendaten');
    if (!karte) return;
    var meldung = karte.querySelector('.kd-meldung');
    var timer = null;

    function zeige(text, fehler) {
        meldung.textContent = text;
        meldung.style.background = fehler ? '#B42318' : '';
        meldung.hidden = false;
        clearTimeout(timer);
        timer = setTimeout(function () { meldung.hidden = true; }, 2400);
    }

    // Zwischenablage: moderne Schnittstelle, sonst ein unsichtbares Textfeld.
    function kopiere(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (ok, nein) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy') ? ok() : nein(); } catch (e) { nein(e); }
            document.body.removeChild(ta);
        });
    }

    // Protokoll: nur der FELDNAME, nie der Wert.
    function protokolliere(feld) {
        if (!feld) return;
        var token = document.querySelector('meta[name="csrf-token"]');
        try {
            fetch(karte.dataset.kdProtokoll, {
                method: 'POST', keepalive: true, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
                body: JSON.stringify({ feld: feld })
            }).catch(function () {});
        } catch (e) {}
    }

    // Auf dem ganzen Dokument: die Kontaktleiste im Kopf nutzt dieselben Knoepfe.
    document.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-kd-tab]');
        if (tab) {
            var reiter = document.querySelector('.cust-tab[data-tab="' + tab.dataset.kdTab + '"]');
            if (reiter) { reiter.click(); reiter.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            return;
        }
        var zeigen = e.target.closest('.kd-zeigen');
        if (zeigen) {
            var wert = zeigen.closest('dd').querySelector('[data-kd-wert]');
            var klar = wert.querySelector('.kd-klar');
            var offen = klar.hidden;
            klar.hidden = !offen;
            wert.querySelector('.kd-maske').hidden = offen;
            zeigen.title = offen ? 'Verbergen' : 'Anzeigen';
            if (offen) protokolliere('angezeigt:' + (zeigen.nextElementSibling.dataset.kdFeld || ''));
            return;
        }
        var knopf = e.target.closest('.kd-kopieren, .kd-alle');
        if (!knopf || knopf.disabled || !knopf.dataset.kopie) return;
        kopiere(knopf.dataset.kopie).then(function () {
            var alle = knopf.classList.contains('kd-alle');
            zeige(alle ? '✓ Alle Kundendaten kopiert – jetzt im Zielformular einfügen' : '✓ Kopiert');
            knopf.classList.add('kd-ok');
            // Nur die kleinen Symbol-Knoepfe tauschen ihr Zeichen.
            var symbol = knopf.classList.contains('kd-knopf');
            if (symbol) knopf.textContent = '✓';
            setTimeout(function () { knopf.classList.remove('kd-ok'); if (symbol) knopf.textContent = '📋'; }, 1400);
            protokolliere(alle ? (knopf.dataset.kdFelder || 'alle') : knopf.dataset.kdFeld);
        }, function () {
            zeige('Kopieren nicht möglich – bitte Wert markieren und mit Strg+C kopieren.', true);
        });
    });
})();
</script>
@endPushOnce
