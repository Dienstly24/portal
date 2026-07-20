{{--
    Gemeinsame Vertragsfelder für Anlegen (contract_new / contract_create) und
    Bearbeiten (contract_edit). Erwartet optional $contract (Bestandsvertrag).
    Der umschließende <form>, die Kundenauswahl und der Submit-Button liegen in
    der jeweiligen Seite. Sparten-Liste zentral aus Contract::TYPES.
--}}
@php
    $c   = $contract ?? null;
    $veh = $c?->vehicleDetail;
    $en  = $c?->energyDetail;
    $net = $c?->internetDetail;
    $val = fn($field, $default = '') => old($field, $default);
    $curType   = old('type', $c->type ?? request('type', ''));
    $curStatus = old('status', $c->status ?? 'active');
    $curSub    = old('subtype', $c->subtype ?? '');
@endphp

<div class="field">
    <label style="font-weight:700;font-size:15px;">Sparte *</label>
    <select name="type" id="sparte" required onchange="contractToggleSections()"
        style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        <option value="">Bitte auswählen</option>
        @foreach(\App\Models\Contract::TYPES as $key => $cfg)
        <option value="{{ $key }}" {{ $curType === $key ? 'selected' : '' }}>{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
        @endforeach
    </select>
</div>

{{-- Sonstige: Freitext, was für ein Vertrag das ist (z.B. ADAC Schutzbrief) --}}
<div id="type-other-wrap" class="field" style="display:none;">
    <label>Was für ein Vertrag? *</label>
    <input type="text" name="type_other" maxlength="120" value="{{ $val('type_other', $c->type_other ?? '') }}"
        placeholder="z. B. ADAC Schutzbrief, Mobil-Club (ACV), Reise-Schutz ...">
</div>

{{-- Krankenversicherung: GKV/PKV steuert die Wechsel-Erinnerungen --}}
<div id="subtype-wrap-krankenversicherung" class="field subtype-wrap" style="display:none;">
    <label>Art der Krankenversicherung</label>
    <select name="subtype" data-subtype-for="krankenversicherung" disabled style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        <option value="">— bitte wählen —</option>
        <option value="gkv" {{ $curSub === 'gkv' ? 'selected' : '' }}>Gesetzlich (GKV) – erhält Wechsel-Erinnerung nach 12 Monaten</option>
        <option value="pkv" {{ $curSub === 'pkv' ? 'selected' : '' }}>Privat (PKV) – keine Wechsel-Erinnerung</option>
    </select>
</div>

{{-- Krankenzusatz: Art der Zusatzversicherung (rein beschreibend, keine Erinnerung).
     Gleiche subtype-Spalte wie oben; nur das aktive Feld wird abgeschickt (disabled). --}}
<div id="subtype-wrap-krankenzusatz" class="field subtype-wrap" style="display:none;">
    <label>Art der Krankenzusatz</label>
    <select name="subtype" data-subtype-for="krankenzusatz" disabled style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        <option value="">— bitte wählen —</option>
        @foreach(\App\Models\Contract::SUBTYPES['krankenzusatz'] as $ok => $ol)
        <option value="{{ $ok }}" {{ $curSub === $ok ? 'selected' : '' }}>{{ $ol }}</option>
        @endforeach
    </select>
</div>

<div style="border-top:1px solid var(--line);margin:24px 0;"></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="field"><label>Versicherer / Anbieter *</label><input type="text" name="insurer" required value="{{ $val('insurer', $c->insurer ?? '') }}" placeholder="z.B. Allianz, HUK-Coburg..."></div>
    <div class="field">
        {{-- Bei Energievertraegen (Strom/Gas) heisst dieses Feld "Vertragsnummer"
             statt "Versicherungsnummer (VSNR)" - per JS umgeschaltet. --}}
        <label id="contract-number-label">Versicherungsnummer (VSNR)</label>
        <input type="text" name="contract_number" maxlength="255" value="{{ $val('contract_number', $c->contract_number ?? '') }}" placeholder="Optional – später nachtragbar">
        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">Leer lassen, falls die echte Nummer noch nicht vorliegt. Es wird keine automatische Nummer erzeugt.</div>
    </div>
</div>
@php
    // Ablauf-Automatik: Modus aus den Bestandsdaten ableiten, damit eine
    // spaetere Aenderung des Beginns den Ablauf korrekt nachzieht.
    // Neuanlage startet mit "12 Monate" (haeufigster Fall, spart Klicks).
    $startVal = $val('start_date', $c && $c->start_date ? \Carbon\Carbon::parse($c->start_date)->format('Y-m-d') : '');
    $endVal   = $val('end_date', $c && $c->end_date ? \Carbon\Carbon::parse($c->end_date)->format('Y-m-d') : '');
    $endMode  = old('end_mode');
    if ($endMode === null) {
        if ($c && $startVal && $endVal) {
            $s = \Carbon\Carbon::parse($startVal);
            $endMode = match ($endVal) {
                $s->copy()->addYear()->format('Y-m-d') => 'plus12',
                $s->format('Y') . '-12-31'             => 'year_end',
                default                                 => 'manual',
            };
        } else {
            $endMode = $c ? 'manual' : 'plus12';
        }
    }
@endphp
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
    <div class="field"><label>Status *</label>
        <select name="status" required style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            @foreach(['active'=>'Aktiv','pending'=>'In Bearbeitung','cancelled'=>'Gekündigt','expired'=>'Abgelaufen'] as $sk => $sl)
            <option value="{{ $sk }}" {{ $curStatus === $sk ? 'selected' : '' }}>{{ $sl }}</option>
            @endforeach
        </select>
    </div>
    <div class="field"><label>Beginn</label>
        <div style="display:flex;gap:8px;">
            <input type="date" id="contract-start" name="start_date" value="{{ $startVal }}" style="flex:1;min-width:0;">
            {{-- Ein Klick statt Kalender: setzt das heutige Datum --}}
            <button type="button" class="btn btn-ghost" style="padding:8px 12px;font-size:12.5px;flex:none;" onclick="contractSetToday()">📅 Heute</button>
        </div>
    </div>
    <div class="field"><label>Ablauf</label>
        <input type="date" id="contract-end" name="end_date" value="{{ $endVal }}">
    </div>
</div>
{{-- Ablauf-Automatik: 12 Monate Laufzeit oder Ende des Kalenderjahres -
     der Ablauf wird aus dem Beginn errechnet und folgt jeder Aenderung.
     Manuelles Tippen im Ablauf-Feld schaltet automatisch auf "Manuell". --}}
<div class="field" style="margin-top:-6px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:12px;color:var(--ink-soft);">Ablauf berechnen:</span>
        @foreach(['plus12' => 'Laufzeit 12 Monate', 'year_end' => 'Ende des Kalenderjahres (31.12.)', 'manual' => 'Manuell'] as $mk => $ml)
        <label class="end-mode-chip" style="position:relative;display:inline-flex;">
            <input type="radio" name="end_mode" value="{{ $mk }}" {{ $endMode === $mk ? 'checked' : '' }} onchange="contractEndSync()" style="position:absolute;inset:0;opacity:0;cursor:pointer;margin:0;">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border:1.5px solid var(--line);border-radius:999px;font-size:12px;font-weight:600;background:var(--surface);cursor:pointer;user-select:none;">{{ $ml }}</span>
        </label>
        @endforeach
        <span id="end-mode-hint" style="font-size:11.5px;color:var(--ink-soft);"></span>
    </div>
</div>
<style>
.end-mode-chip input:checked + span{border-color:#17A65B;background:#E7F6EE;color:#0E7A41;box-shadow:inset 0 0 0 1px #17A65B;}
.end-mode-chip input:focus-visible + span{outline:2px solid #17A65B;outline-offset:2px;}
</style>
<div style="display:grid;grid-template-columns:1fr 2fr;gap:16px;">
    <div class="field"><label>Kündigungsdatum</label><input type="date" name="cancellation_date" value="{{ $val('cancellation_date', $c && $c->cancellation_date ? \Carbon\Carbon::parse($c->cancellation_date)->format('Y-m-d') : '') }}"></div>
    <div class="field"><label>Notizen</label><input type="text" name="notes" value="{{ $val('notes', $c->notes ?? '') }}" placeholder="Interne Notizen..."></div>
</div>

{{-- Beitrag + Zahlweise: was der Kunde zahlt und in welchem Rhythmus. Gilt fuer
     alle Sparten und ist Grundlage der Kosten-Statistik in der Kundenakte/Portal. --}}
@php $curInterval = old('premium_interval', $c->premium_interval ?? 'monthly'); @endphp
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="field">
        <label>Beitrag (€)</label>
        <input type="number" step="0.01" min="0" name="premium_amount"
            value="{{ $val('premium_amount', $c && $c->premium_amount !== null ? rtrim(rtrim(number_format((float) $c->premium_amount, 2, '.', ''), '0'), '.') : '') }}"
            placeholder="z. B. 49,90">
        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">Leer lassen, wenn kein Beitrag hinterlegt werden soll.</div>
    </div>
    <div class="field">
        <label>Zahlweise</label>
        <select name="premium_interval" style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            @foreach(\App\Models\Contract::PREMIUM_INTERVALS as $ik => $cfg)
            <option value="{{ $ik }}" {{ $curInterval === $ik ? 'selected' : '' }}>{{ $cfg['label'] }}</option>
            @endforeach
        </select>
    </div>
</div>

{{-- ===== KFZ (Redesign 17.07.2026: Button-Oberflaeche, eigenes Partial) ===== --}}
<div id="section-kfz" class="branch-section" style="display:none;">
    @include('admin.partials.contract_kfz_fields')
</div>

{{-- ===== Energie (Strom & Gas) ===== --}}
<div id="section-energy" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
    <div class="card-title" style="font-size:14px;">⚡ Energievertrag (Strom / Gas)</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Tarif</label><input type="text" name="energy[tariff]" value="{{ $val('energy.tariff', $en->tariff ?? '') }}"></div>
        {{-- Energievertraege haben zusaetzlich eine Kundennummer beim Anbieter
             (getrennt von der Vertragsnummer oben). --}}
        <div class="field"><label>Kundennummer (beim Anbieter)</label><input type="text" name="energy[customer_number]" maxlength="60" value="{{ $val('energy.customer_number', $en->customer_number ?? '') }}" placeholder="Kundennummer des Energieanbieters"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Verbrauch (kWh/Jahr)</label><input type="number" name="energy[consumption_kwh]" min="0" value="{{ $val('energy.consumption_kwh', $en->consumption_kwh ?? '') }}"></div>
        <div class="field"><label>Zählernummer</label><input type="text" name="energy[meter_number]" maxlength="60" value="{{ $val('energy.meter_number', $en->meter_number ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Marktlokations-ID (MaLo-ID, 11 Ziffern)</label><input type="text" name="energy[malo_id]" maxlength="11" pattern="[0-9]{11}" value="{{ $val('energy.malo_id', $en->malo_id ?? '') }}" placeholder="Nicht die Zählernummer!"></div>
        <div class="field"><label>Zählerstand (optional)</label><input type="text" name="energy[meter_reading]" maxlength="30" value="{{ $val('energy.meter_reading', $en->meter_reading ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Netzbetreiber (optional)</label><input type="text" name="energy[grid_operator]" value="{{ $val('energy.grid_operator', $en->grid_operator ?? '') }}"></div>
        <div class="field"><label>Messstellenbetreiber (optional)</label><input type="text" name="energy[metering_operator]" value="{{ $val('energy.metering_operator', $en->metering_operator ?? '') }}"></div>
    </div>
    {{-- Vorversorger (bisheriger Lieferant beim Wechsel) - aus dem Auftrag. --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Bisheriger Lieferant (Vorversorger)</label><input type="text" name="energy[previous_provider]" maxlength="150" value="{{ $val('energy.previous_provider', $en->previous_provider ?? '') }}" placeholder="z. B. Stadtwerke Neuss"></div>
        <div class="field"><label>Kundennummer beim bisherigen Lieferanten</label><input type="text" name="energy[previous_customer_number]" maxlength="60" value="{{ $val('energy.previous_customer_number', $en->previous_customer_number ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Abschlag (€)</label><input type="number" step="0.01" name="energy[payment_amount]" min="0" value="{{ $val('energy.payment_amount', $en->payment_amount ?? '') }}"></div>
        <div class="field"><label>Zahlungsintervall</label>
            <select name="energy[payment_interval]" style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @php $curInt = old('energy.payment_interval', $en->payment_interval ?? ''); @endphp
                <option value="">—</option>
                @foreach(['monatlich'=>'Monatlich','vierteljaehrlich'=>'Vierteljährlich','halbjaehrlich'=>'Halbjährlich','jaehrlich'=>'Jährlich'] as $ik => $il)
                <option value="{{ $ik }}" {{ $curInt === $ik ? 'selected' : '' }}>{{ $il }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

{{-- ===== Internet ===== --}}
<div id="section-internet" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
    <div class="card-title" style="font-size:14px;">📶 Internet & Mobilfunk</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Tarif</label><input type="text" name="internet[tariff]" value="{{ $val('internet.tariff', $net->tariff ?? '') }}"></div>
        <div class="field"><label>Geschwindigkeit</label><input type="text" name="internet[speed]" maxlength="30" value="{{ $val('internet.speed', $net->speed ?? '') }}" placeholder="250 Mbit/s"></div>
    </div>
</div>

{{-- ===== E-Scooter (eigener Namensraum, nutzt aber die Fahrzeugtabelle) ===== --}}
<div id="section-escooter" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
    <div class="card-title" style="font-size:14px;">🛴 E-Scooter</div>
    <p style="font-size:12px;color:var(--ink-soft);margin:0 0 14px;">
        E-Scooter laufen ueber ein festes Versicherungsjahr: Der Vertrag endet automatisch am Ende des Februars
        (bedarf keiner Kuendigung), der Beitrag wird einmalig faellig. Deckung: Haftpflicht oder Teilkasko (kein Vollkasko).
        Der Ablauf wird aus dem Beginn berechnet und muss nicht von Hand gesetzt werden.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Versicherungskennzeichen</label><input type="text" name="escooter[license_plate]" maxlength="20" value="{{ $val('escooter.license_plate', $veh->license_plate ?? '') }}" placeholder="z. B. 611 MDS"></div>
        <div class="field"><label>Fahrgestellnummer (FIN)</label><input type="text" name="escooter[vin]" maxlength="30" value="{{ $val('escooter.vin', $veh->vin ?? '') }}" placeholder="z. B. ZSF10Z23075358"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Hersteller/Modellbezeichnung</label><input type="text" name="escooter[manufacturer]" maxlength="255" value="{{ $val('escooter.manufacturer', $veh->manufacturer ?? '') }}" placeholder="z. B. ZHEJIANG KUANTU (RC)"></div>
        <div class="field"><label>Modell (optional)</label><input type="text" name="escooter[model]" maxlength="255" value="{{ $val('escooter.model', $veh->model ?? '') }}"></div>
    </div>
    <div class="field">
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="checkbox" name="escooter[has_teilkasko]" value="1" {{ old('escooter.has_teilkasko', $veh->has_teilkasko ?? false) ? 'checked' : '' }}>
            Teilkasko eingeschlossen (sonst nur Haftpflicht)
        </label>
    </div>
</div>

<script>
function contractToggleSections() {
    const type = document.getElementById('sparte').value;
    // Strom und Gas sind getrennte Sparten, teilen sich aber das Energie-Formular.
    const energyTypes = ['strom', 'gas'];
    document.querySelectorAll('.branch-section').forEach(el => el.style.display = 'none');
    const active = energyTypes.includes(type)
        ? document.getElementById('section-energy')
        : document.getElementById('section-' + type);
    if (active) active.style.display = 'block';
    document.getElementById('type-other-wrap').style.display = (type === 'andere') ? 'block' : 'none';
    // Untergruppe (subtype) je Sparte: nur das passende Feld anzeigen UND aktivieren.
    // disabled steuert, welcher Wert abgeschickt wird - sonst kaemen zwei subtype-Werte an.
    document.querySelectorAll('.subtype-wrap').forEach(w => w.style.display = 'none');
    document.querySelectorAll('select[data-subtype-for]').forEach(s => s.disabled = true);
    const subWrap = document.getElementById('subtype-wrap-' + type);
    if (subWrap) {
        subWrap.style.display = 'block';
        const sel = subWrap.querySelector('select[data-subtype-for]');
        if (sel) sel.disabled = false;
    }
    // Energievertraege haben eine Vertragsnummer statt einer Versicherungsnummer.
    const lbl = document.getElementById('contract-number-label');
    if (lbl) lbl.textContent = energyTypes.includes(type) ? 'Vertragsnummer' : 'Versicherungsnummer (VSNR)';
}

// ---- Ablauf-Automatik (Beginn + Modus -> Ablauf) ----
function contractEndMode() {
    return document.querySelector('input[name="end_mode"]:checked')?.value || 'manual';
}

// Ablauf aus Beginn errechnen: +12 Monate (17.07.2026 -> 17.07.2027)
// oder Ende des Kalenderjahres (17.07.2026 -> 31.12.2026).
function contractEndSync() {
    const start = document.getElementById('contract-start').value;
    const end = document.getElementById('contract-end');
    const mode = contractEndMode();
    const hint = document.getElementById('end-mode-hint');
    hint.textContent = '';
    if (mode === 'manual') return;
    if (!start) { hint.textContent = 'Beginn eintragen – der Ablauf wird automatisch berechnet.'; return; }
    const [y, m, d] = start.split('-').map(Number);
    let target;
    if (mode === 'plus12') {
        const plus = new Date(Date.UTC(y + 1, m - 1, d));
        // 29.02. + 1 Jahr rutscht auf den 28.02. statt in den Maerz.
        if (plus.getUTCMonth() !== m - 1) plus.setUTCDate(0);
        target = plus.toISOString().slice(0, 10);
    } else {
        target = y + '-12-31';
    }
    if (end.value !== target) {
        end.value = target;
        hint.textContent = 'Ablauf automatisch gesetzt: ' + target.split('-').reverse().join('.');
    }
}

// Heute-Button: setzt den Beginn auf das heutige Datum (lokale Zeit).
function contractSetToday() {
    const el = document.getElementById('contract-start');
    const now = new Date();
    el.value = [now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0')].join('-');
    contractEndSync();
}

document.addEventListener('DOMContentLoaded', function () {
    contractToggleSections();
    const start = document.getElementById('contract-start');
    const end = document.getElementById('contract-end');
    // Beginn geaendert -> Ablauf sofort neu berechnen (gewaehlter Modus).
    start.addEventListener('change', contractEndSync);
    start.addEventListener('input', contractEndSync);
    // Manuelles Tippen im Ablauf-Feld schaltet die Automatik ab.
    end.addEventListener('input', function () {
        const manual = document.querySelector('input[name="end_mode"][value="manual"]');
        if (manual && !manual.checked) { manual.checked = true; document.getElementById('end-mode-hint').textContent = 'Automatik aus – Ablauf manuell gesetzt.'; }
    });
    contractEndSync();
});
</script>
