{{-- Kundendaten auf einen Blick (Betreiber-Auftrag 27.11.2026).
     Alle Werte direkt aus der Akte (App\Support\Kundendatenblatt) - ohne
     Bearbeiten-Modus sichtbar und je Feld kopierbar. "Alle Kundendaten
     kopieren" legt alle GEFUELLTEN Werte als "Label: Wert" in die
     Zwischenablage, zum Einfuegen in ein Vertragsformular.
     Kopiert wird IMMER der Originalwert (data-kopie), nie die Anzeige.
     Bank- und Ausweisdaten sind beim Oeffnen verdeckt; jedes Kopieren
     sensibler Werte steht im Aktivitaetsprotokoll (nur Feldnamen). --}}
@php
    $kdBereiche = \App\Support\Kundendatenblatt::bereiche($customer);
    $kdText = \App\Support\Kundendatenblatt::alsText($customer);
    $kdSensibel = collect($kdBereiche)->flatMap(fn ($b) => $b['felder'])->where('sensibel', true)->where('kopie', '!=', '')->pluck('key')->values();
@endphp
<div class="card kd-karte" id="kundendaten" data-kd-protokoll="{{ route('admin.customer.data_copied', $customer->id) }}">
    <div class="kd-kopf">
        <div class="card-title" style="margin-bottom:0;">🗂️ Kundendaten</div>
        <div class="kd-aktionen">
            <a href="{{ route('admin.customer.edit', $customer->id) }}" class="btn btn-ghost" style="padding:7px 14px;font-size:13px;">✏️ Bearbeiten</a>
            <button type="button" class="btn btn-primary kd-alle" style="padding:7px 14px;font-size:13px;"
                data-kopie="{{ $kdText }}" data-kd-felder="{{ $kdSensibel->isNotEmpty() ? 'alle_mit_sensiblen' : 'alle' }}">📋 Alle Kundendaten kopieren</button>
        </div>
    </div>

    <div class="kd-raster">
        @foreach($kdBereiche as $kdKey => $kdBereich)
        <section class="kd-bereich" aria-label="{{ $kdBereich['titel'] }}">
            <div class="kd-bereich-titel">
                <span>{{ $kdBereich['titel'] }}</span>
                @if($kdKey === 'bank' || $kdKey === 'weitere')
                <span class="kd-schloss">🔐 verschlüsselt gespeichert</span>
                @endif
            </div>
            <dl class="kd-liste">
                @foreach($kdBereich['felder'] as $f)
                @php $leer = $f['kopie'] === ''; @endphp
                <div class="kd-zeile{{ $f['key'] === 'address_full' ? ' kd-zeile-haupt' : '' }}">
                    <dt>{{ $f['label'] }}</dt>
                    <dd>
                        @if($leer)
                            <span class="kd-leer">—</span>
                        @else
                            <span class="kd-wert{{ $f['sensibel'] ? ' kd-verdeckt' : '' }}" data-kd-wert>
                                @if($f['sensibel'])
                                <span class="kd-maske">{{ '•••• ' . mb_substr($f['kopie'], -4) }}</span><span class="kd-klar" hidden>{{ $f['anzeige'] }}</span>
                                @else
                                {{ $f['anzeige'] }}
                                @endif
                            </span>
                            @if($f['geerbt'])<span class="kd-geerbt">vom Elternteil übernommen</span>@endif
                            <span class="kd-knoepfe">
                                @if($f['sensibel'])
                                <button type="button" class="kd-knopf kd-zeigen" aria-label="{{ $f['label'] }} anzeigen" title="Anzeigen">👁</button>
                                @endif
                                <button type="button" class="kd-knopf kd-kopieren" data-kopie="{{ $f['kopie'] }}" data-kd-feld="{{ $f['sensibel'] ? $f['key'] : '' }}"
                                    aria-label="{{ $f['label'] }} kopieren" title="Kopieren">📋</button>
                            </span>
                        @endif
                    </dd>
                </div>
                @endforeach
            </dl>
        </section>
        @endforeach
    </div>
    <div class="kd-meldung" role="status" aria-live="polite" hidden></div>
</div>

<style>
.kd-karte .kd-kopf{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.kd-karte .kd-aktionen{display:flex;gap:8px;flex-wrap:wrap;}
.kd-karte .kd-raster{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;}
.kd-karte .kd-raster > *{min-width:0;}
.kd-karte .kd-bereich{border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--surface,#FBFAF6);}
.kd-karte .kd-bereich-titel{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:6px;}
.kd-karte .kd-schloss{font-size:10.5px;font-weight:600;text-transform:none;letter-spacing:0;}
.kd-karte .kd-liste{margin:0;}
.kd-karte .kd-zeile{display:grid;grid-template-columns:minmax(110px,38%) 1fr;gap:8px;align-items:center;padding:6px 0;border-top:1px solid var(--line);}
.kd-karte .kd-zeile:first-child{border-top:none;}
.kd-karte .kd-zeile-haupt dd{font-weight:700;}
.kd-karte dt{font-size:12.5px;color:var(--ink-soft);}
.kd-karte dd{margin:0;display:flex;align-items:center;gap:6px;min-width:0;font-size:13.5px;font-weight:600;color:var(--ink);}
.kd-karte .kd-wert{overflow-wrap:anywhere;min-width:0;}
.kd-karte .kd-verdeckt{font-family:ui-monospace,monospace;}
.kd-karte .kd-leer{color:var(--ink-soft);font-weight:400;}
.kd-karte .kd-geerbt{font-size:10.5px;background:#FEF3C7;color:#92400E;border-radius:999px;padding:1px 7px;white-space:nowrap;}
.kd-karte .kd-knoepfe{margin-left:auto;display:inline-flex;gap:2px;flex-shrink:0;}
.kd-karte .kd-knopf{border:1px solid transparent;background:none;border-radius:6px;cursor:pointer;font-size:13px;line-height:1;padding:5px 6px;opacity:.65;}
.kd-karte .kd-knopf:hover,.kd-karte .kd-knopf:focus-visible{opacity:1;border-color:var(--line);background:var(--canvas);}
.kd-karte .kd-knopf.kd-ok{opacity:1;color:var(--emerald);}
.kd-karte .kd-meldung{margin-top:10px;font-size:12.5px;color:var(--emerald);font-weight:600;}
.kd-karte [hidden]{display:none!important;}
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
        meldung.style.color = fehler ? 'var(--status-danger, #B42318)' : '';
        meldung.hidden = false;
        clearTimeout(timer);
        timer = setTimeout(function () { meldung.hidden = true; }, 2500);
    }

    // Zwischenablage: zuerst die moderne Schnittstelle, sonst ein
    // unsichtbares Textfeld (aeltere Browser / kein sicherer Kontext).
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

    karte.addEventListener('click', function (e) {
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
        if (!knopf) return;
        kopiere(knopf.dataset.kopie).then(function () {
            var alle = knopf.classList.contains('kd-alle');
            zeige(alle ? '✓ Alle Kundendaten kopiert – jetzt im Zielformular einfügen.' : '✓ Kopiert');
            if (!alle) {
                knopf.classList.add('kd-ok');
                knopf.textContent = '✓';
                setTimeout(function () { knopf.classList.remove('kd-ok'); knopf.textContent = '📋'; }, 1400);
            }
            protokolliere(alle ? knopf.dataset.kdFelder : knopf.dataset.kdFeld);
        }, function () {
            zeige('Kopieren nicht möglich – bitte Wert markieren und mit Strg+C kopieren.', true);
        });
    });
})();
</script>
@endPushOnce
