{{--
    Vertragsherkunft (Betreiber-Auftrag 28.09.2026) - steht GANZ OBEN im
    Vertragsformular. Erwartet optional $contract (Bestand) und
    $originCustomer (Kunde, sofern bekannt - fuer die Auswahl von
    Vorgaenger/Nachfolger). Ohne Kunden (Formular "Vertrag anlegen" aus der
    Vertragsliste) steht nur die Schnellanlage des Vorvertrags zur Wahl.

    KEINE STILLE VOREINSTELLUNG bei der Neuanlage: die Frage "ist das unser
    Vertrag?" wird aktiv beantwortet, sonst waere sie wieder die Annahme,
    an der der Fehler entstanden ist. Pruefung serverseitig
    (ContractController::validateOrigin).
--}}
@php
    $oc = $contract ?? null;
    $ocOrigin = old('origin', $oc->origin ?? '');
    $ocOriginal = $oc->origin ?? null;
    $ocDarfAendern = ! $oc || in_array(auth()->user()->role, ['admin', 'manager'], true);
    $ocKunde = $originCustomer ?? $oc?->customer;
    $ocKandidaten = $ocKunde
        ? \App\Models\Contract::where('customer_id', $ocKunde->id)
            ->when($oc, fn ($q) => $q->where('id', '!=', $oc->id))
            ->orderByDesc('created_at')->get()
        : collect();
    $ocNachfolgerId = old('replaced_by_contract_id', $oc?->successor?->id ?? '');
    $ocVorgaengerId = old('replaces_contract_id', $oc->replaces_contract_id ?? '');
    $ocReplacesMode = old('replaces_mode', $ocVorgaengerId ? 'existing' : 'none');
    $ocIcons = ['brokered' => '🤝', 'external' => '📁', 'transferred' => '🔄'];
    $ocKandidatLabel = fn ($k) => $k->typeIcon().' '.$k->typeLabel().' · '.$k->insurer
        .($k->contract_number ? ' ('.$k->contract_number.')' : ($k->reference_number ? ' (Ref. '.$k->reference_number.')' : ''))
        .($k->isExternal() ? ' – Fremdvertrag' : '');
@endphp

<div class="field" id="origin-block">
    <label style="font-weight:700;font-size:15px;">Vertragsherkunft *</label>
    <div style="font-size:12px;color:var(--ink-soft);margin:-2px 0 8px;">Ist das ein Vertrag, den <strong>wir</strong> vermittelt haben? Bitte bewusst auswählen.</div>

    @if(! $ocDarfAendern)
        {{-- Mitarbeiter/Support: Herkunft nur lesen. Der Wert geht als
             verstecktes Feld mit - geaendert werden kann er nur von
             admin/manager, und das prueft der Server erneut. --}}
        <input type="hidden" name="origin" value="{{ $ocOriginal }}">
        <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:var(--canvas);font-size:13px;">
            <span style="font-size:18px;">{{ $ocIcons[$ocOriginal] ?? '🤝' }}</span>
            <div><strong>{{ $oc->originLabel() }}</strong>
                <div class="muted-2xs">Die Herkunft kann nur ein Admin oder Manager ändern (wird protokolliert).</div></div>
        </div>
    @else
    <div class="origin-cards" role="radiogroup" aria-label="Vertragsherkunft">
        @foreach(\App\Models\Contract::ORIGIN_LABELS as $ok => $ol)
        <label class="origin-card">
            <input type="radio" name="origin" value="{{ $ok }}" {{ $ocOrigin === $ok ? 'checked' : '' }} required data-origin-radio>
            <span class="origin-card-body">
                <span class="origin-card-title">{{ $ocIcons[$ok] }} {{ $ok === 'external' ? 'Fremdvertrag' : $ol }}</span>
                <span class="origin-card-hint">{{ \App\Models\Contract::ORIGIN_HINTS[$ok] }}</span>
            </span>
        </label>
        @endforeach
    </div>
    @if($oc)
    {{-- Aenderung der Herkunft = bewusste, protokollierte Entscheidung. --}}
    <label id="origin-change-confirm" class="wahl wahl-warnung" data-original="{{ $ocOriginal }}" style="display:none;margin-top:10px;font-size:12.5px;">
        <input type="checkbox" name="origin_change_confirmed" value="1" @checked(old('origin_change_confirmed'))>
        <span>⚠️ Ich ändere die Herkunft dieses Vertrags bewusst (bisher: <strong>{{ $oc->originLabel() }}</strong>). Die Änderung wird mit Name und Zeitpunkt protokolliert.</span>
    </label>
    @endif
    @endif
</div>

{{-- Fremdvertrag: wer ist zustaendig, warum ist er erfasst, was haben wir getan --}}
<div class="origin-section" data-origin-for="external transferred" style="display:none;border:1px dashed var(--line);border-radius:10px;padding:14px 16px;margin-bottom:16px;background:var(--canvas);">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="field" style="margin-bottom:0;">
            <label>Bisher betreut durch</label>
            <input type="text" name="previous_broker" maxlength="150" value="{{ old('previous_broker', $oc->previous_broker ?? '') }}" placeholder="z. B. anderer Makler, Versicherer direkt, unbekannt" aria-label="Bisher betreut durch">
        </div>
        <div class="field origin-only-transferred" style="margin-bottom:0;display:none;">
            <label>Übernommen am *</label>
            <input type="date" name="transfer_date" value="{{ old('transfer_date', $oc?->transfer_date?->format('Y-m-d') ?? '') }}" aria-label="Übernommen am">
            <div class="muted-2xs" style="margin-top:4px;">Datum der Maklervollmacht bzw. Courtagezusage.</div>
        </div>
        <div class="field origin-only-external" style="margin-bottom:0;display:none;">
            <input type="hidden" name="cancellation_submitted_by_us" value="0">
            <label class="wahl" style="font-weight:500;margin-top:22px;">
                <input type="checkbox" name="cancellation_submitted_by_us" value="1" {{ old('cancellation_submitted_by_us', $oc->cancellation_submitted_by_us ?? false) ? 'checked' : '' }}>
                <span>Kündigung haben <strong>wir</strong> im Auftrag des Kunden eingereicht</span>
            </label>
        </div>
    </div>
    <div class="field" style="margin:14px 0 0;">
        <label>Hinweis zur Herkunft</label>
        <textarea name="origin_note" maxlength="2000" rows="2" placeholder="z. B. Nur als Vorvertrag erfasst, Wechsel zu DA Direkt" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;font-family:inherit;" aria-label="Hinweis zur Herkunft">{{ old('origin_note', $oc->origin_note ?? '') }}</textarea>
    </div>
    @if($ocKandidaten->isNotEmpty())
    <div class="field origin-only-external" style="margin:14px 0 0;display:none;">
        <label>Ersetzt durch Vertrag</label>
        <select name="replaced_by_contract_id" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;" aria-label="Ersetzt durch Vertrag">
            <option value="">— kein Nachfolger —</option>
            @foreach($ocKandidaten->filter(fn ($k) => $k->isOwnPortfolio()) as $k)
            <option value="{{ $k->id }}" {{ (string) $ocNachfolgerId === (string) $k->id ? 'selected' : '' }}>{{ $ocKandidatLabel($k) }}</option>
            @endforeach
        </select>
    </div>
    @endif
</div>

{{-- Haeufigster Ablauf: neuer Eigenvertrag ersetzt einen fremden Altvertrag.
     Der Vorvertrag laesst sich hier auswaehlen ODER in einem Zug als
     Fremdvertrag anlegen. --}}
<div class="origin-section" data-origin-for="brokered transferred" style="display:none;border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
    <div style="font-weight:600;font-size:13.5px;margin-bottom:8px;">↩ Ersetzt einen bestehenden Vertrag?</div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:13px;margin-bottom:6px;">
        <label class="wahl" style="padding:7px 12px;"><input type="radio" name="replaces_mode" value="none" {{ $ocReplacesMode === 'none' ? 'checked' : '' }} data-replaces-radio> Nein</label>
        @if($ocKandidaten->isNotEmpty())
        <label class="wahl" style="padding:7px 12px;"><input type="radio" name="replaces_mode" value="existing" {{ $ocReplacesMode === 'existing' ? 'checked' : '' }} data-replaces-radio> Ja – vorhandenen Vertrag wählen</label>
        @endif
        @if(! $oc)
        <label class="wahl" style="padding:7px 12px;"><input type="radio" name="replaces_mode" value="new" {{ $ocReplacesMode === 'new' ? 'checked' : '' }} data-replaces-radio> Ja – Vorvertrag jetzt als Fremdvertrag erfassen</label>
        @endif
    </div>
    @if($ocKandidaten->isNotEmpty())
    <div class="replaces-part" data-replaces-for="existing" style="display:none;margin-top:8px;">
        <select name="replaces_contract_id" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;" aria-label="Ersetzter Vertrag">
            <option value="">— bitte wählen —</option>
            @foreach($ocKandidaten as $k)
            <option value="{{ $k->id }}" {{ (string) $ocVorgaengerId === (string) $k->id ? 'selected' : '' }}>{{ $ocKandidatLabel($k) }}</option>
            @endforeach
        </select>
        <div class="muted-2xs" style="margin-top:4px;">Mit Beginn des neuen Vertrags wird am Vorvertrag die Kündigung zu diesem Tag erfasst, falls noch keine steht.</div>
    </div>
    @endif
    @if(! $oc)
    <div class="replaces-part" data-replaces-for="new" style="display:none;margin-top:8px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="field" style="margin-bottom:0;"><label>Versicherer des Vorvertrags *</label>
                <input type="text" name="predecessor[insurer]" maxlength="255" value="{{ old('predecessor.insurer') }}" placeholder="z. B. ADAC" aria-label="Versicherer des Vorvertrags"></div>
            <div class="field" style="margin-bottom:0;"><label>Vertragsnummer des Vorvertrags</label>
                <input type="text" name="predecessor[contract_number]" maxlength="255" value="{{ old('predecessor.contract_number') }}" placeholder="z. B. AD-9533316226" aria-label="Vertragsnummer des Vorvertrags"></div>
            <div class="field" style="margin-bottom:0;"><label>Bisher betreut durch</label>
                <input type="text" name="predecessor[previous_broker]" maxlength="150" value="{{ old('predecessor.previous_broker') }}" placeholder="Versicherer direkt" aria-label="Vorvertrag bisher betreut durch"></div>
            <div class="field" style="margin-bottom:0;"><label>Monatsbeitrag des Vorvertrags (€)</label>
                <input type="number" step="0.01" min="0" name="predecessor[premium_amount]" value="{{ old('predecessor.premium_amount') }}" aria-label="Monatsbeitrag des Vorvertrags"></div>
        </div>
        <input type="hidden" name="predecessor[cancellation_submitted_by_us]" value="0">
        <label class="wahl" style="margin-top:10px;">
            <input type="checkbox" name="predecessor[cancellation_submitted_by_us]" value="1" {{ old('predecessor.cancellation_submitted_by_us') ? 'checked' : '' }}>
            <span>Kündigung haben <strong>wir</strong> im Auftrag des Kunden eingereicht</span>
        </label>
        <div class="muted-2xs" style="margin-top:6px;">Der Vorvertrag entsteht als <strong>Fremdvertrag – nur Dokumentation</strong> in derselben Sparte, gekündigt zum Beginn des neuen Vertrags.</div>
    </div>
    @endif
</div>

<style>
.origin-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;}
.origin-card{position:relative;display:block;cursor:pointer;}
.origin-card input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer;}
.origin-card-body{display:flex;flex-direction:column;gap:4px;height:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:10px;background:var(--surface);}
.origin-card-title{font-weight:700;font-size:13.5px;}
.origin-card-hint{font-size:11.5px;color:var(--ink-soft);line-height:1.45;}
.origin-card input:checked + .origin-card-body{border-color:var(--emerald);background:#E7F6EE;box-shadow:inset 0 0 0 1px var(--emerald);}
.origin-card input:focus-visible + .origin-card-body{outline:2px solid var(--emerald);outline-offset:2px;}
</style>

<script @cspNonce>
(function () {
    // Zeigt je Herkunft nur die passenden Felder. Selbsttragend (eigener
    // Nonce, addEventListener) - braucht keinen Eintrag in window.__h.
    function aktuelleHerkunft() {
        const r = document.querySelector('input[name="origin"]:checked') || document.querySelector('input[type="hidden"][name="origin"]');
        return r ? r.value : '';
    }
    function sync() {
        const o = aktuelleHerkunft();
        document.querySelectorAll('.origin-section').forEach(function (el) {
            el.style.display = (el.dataset.originFor || '').split(' ').indexOf(o) >= 0 ? '' : 'none';
        });
        document.querySelectorAll('.origin-only-external').forEach(function (el) { el.style.display = o === 'external' ? '' : 'none'; });
        document.querySelectorAll('.origin-only-transferred').forEach(function (el) { el.style.display = o === 'transferred' ? '' : 'none'; });
        const conf = document.getElementById('origin-change-confirm');
        if (conf) conf.style.display = (o && o !== conf.dataset.original) ? 'flex' : 'none';
        sperreSpeichern(conf);
        const mode = document.querySelector('input[name="replaces_mode"]:checked');
        document.querySelectorAll('.replaces-part').forEach(function (el) {
            el.style.display = mode && el.dataset.replacesFor === mode.value ? '' : 'none';
        });
    }
    // Speichern erst, wenn die Herkunftsaenderung bestaetigt ist
    // (09.10.2026). Vorher lief das Formular zum Server, kam mit einer
    // Fehlermeldung GANZ OBEN zurueck, und der Bearbeiter unten am Knopf
    // sah nur, dass "nichts passiert". Der Server prueft weiterhin selbst.
    function sperreSpeichern(conf) {
        const form = document.getElementById('origin-block')?.closest('form');
        if (!form) return;
        const box = conf && conf.querySelector('input[type=checkbox]');
        const fehlt = !!(conf && conf.style.display !== 'none' && box && !box.checked);
        form.querySelectorAll('button[type=submit]').forEach(function (b) {
            b.disabled = fehlt;
            b.title = fehlt ? 'Bitte zuerst die Änderung der Vertragsherkunft bestätigen.' : '';
        });
        let hinweis = form.querySelector('[data-origin-hinweis]');
        const knopf = form.querySelector('button[type=submit]');
        if (!hinweis && knopf) {
            hinweis = document.createElement('span');
            hinweis.className = 'aktionsleiste-hinweis';
            hinweis.setAttribute('data-origin-hinweis', '');
            hinweis.setAttribute('role', 'status');
            knopf.parentNode.appendChild(hinweis);
        }
        if (hinweis) hinweis.textContent = fehlt ? '⚠ Speichern erst nach Bestätigung der Herkunftsänderung (oben).' : '';
    }
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-origin-radio],[data-replaces-radio],[name="origin_change_confirmed"]')) sync();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sync); else sync();
})();
</script>

<div style="border-top:1px solid var(--line);margin:8px 0 24px;"></div>
