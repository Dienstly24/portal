{{--
    Begruendung einer SF-Sondereinstufung je Sparte (01.10.2026).
    Erwartet: $branchKey (haftpflicht|vollkasko), $short (liability|comprehensive),
    $ref (Formularwerte), $refModel (VehicleSfReference|null), $sfRefCtx.
    Welche Bloecke sichtbar sind, entscheidet der gewaehlte Grund
    (data-reasons) - verborgene Felder werden deaktiviert und NICHT gesendet.
    Geschrieben wird ausschliesslich ueber SfReferenceService.
--}}
@php
    use App\Models\ContractVehicleDetail as VD;
    use App\Models\VehicleSfReference as SR;
    $n = fn ($field) => "vehicle[sf_ref][{$branchKey}][{$field}]";
    $refReasons = implode(',', SR::referenceReasons());
    $isVk = $branchKey === 'vollkasko';
@endphp
<div class="kfz-sfref" data-branch="{{ $branchKey }}" data-short="{{ $short }}">

    {{-- ===== Bezugsfahrzeug (Erstwagen) ===== --}}
    <div class="kfz-sfref-block" data-reasons="{{ $refReasons }}">
        <div class="kfz-ref-title">🔗 Bezugsfahrzeug (Erstwagen)</div>
        <div class="kfz-ref-sub">Der Vertrag, wegen dem die Sondereinstufung gewährt wird – meist der Erstwagen des Kunden oder eines Familienmitglieds. <b>Nicht</b> unter Vorversicherung eintragen.</div>

        @if($isVk)
        <label class="kfz-chip" style="margin-bottom:8px;"><input type="checkbox" class="kfz-ref-copy" name="{{ $n('copy_from_liability') }}" value="1" {{ $ref['copy_from_liability'] ? 'checked' : '' }}><span>↺ Gleichen Bezug wie Haftpflicht übernehmen</span></label>
        @endif

        <div class="kfz-ref-own">
            <input type="hidden" class="kfz-ref-type" name="{{ $n('reference_type') }}" value="{{ $ref['reference_type'] }}">
            <input type="hidden" class="kfz-ref-id" name="{{ $n('reference_contract_id') }}" value="{{ $ref['reference_contract_id'] }}">

            {{-- Gewaehlter Vertrag (schreibgeschuetzt) --}}
            <div class="kfz-ref-card" data-other-customer="{{ ($ref['card']['customer_id'] ?? null) && ($ref['card']['customer_id'] ?? null) !== (string) $sfRefCtx['customerId'] ? '1' : '' }}" @if(! $ref['card']) hidden @endif>
                <div style="flex:1;min-width:0;">
                    <div class="kfz-ref-card-title">{{ $ref['card']['label'] ?? '' }}</div>
                    <div class="kfz-ref-card-meta">{{ $ref['card']['meta'] ?? '' }}</div>
                </div>
                <a class="kfz-ref-card-link" href="{{ $ref['card']['url'] ?? '#' }}" target="_blank" rel="noopener" @if(empty($ref['card']['url'])) hidden @endif>Vertrag öffnen ↗</a>
                <button type="button" class="kfz-ref-clear" title="Auswahl aufheben">✕</button>
            </div>

            {{-- Suche im Bestand (Kunde + Familie) --}}
            <div class="kfz-ref-search" @if($ref['card'] || $ref['reference_type'] === SR::TYPE_EXTERNAL) hidden @endif>
                <input type="search" class="kfz-ref-q" placeholder="KFZ-Verträge von Kunde und Familie durchsuchen (Versicherer, Nr., Kennzeichen)…" autocomplete="off" style="{{ $kfzInputStyle }}" aria-label="Bezugsfahrzeug suchen">
                <div class="kfz-ref-results" role="listbox"></div>
                <div class="kfz-ref-empty" hidden>Kein passender KFZ-Vertrag bei Kunde oder Familie.</div>
                <button type="button" class="kfz-row-btn kfz-ref-ext-btn" style="margin-top:8px;">+ Vertrag bei anderem Versicherer (nicht im System)</button>
            </div>

            {{-- Externer Erstwagen --}}
            <div class="kfz-ref-ext" @if($ref['reference_type'] !== SR::TYPE_EXTERNAL) hidden @endif>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;">
                    <div class="field"><label>Versicherer</label><input type="text" list="kfz-insurer-list" name="{{ $n('ext_insurer') }}" maxlength="120" value="{{ $ref['ext_insurer'] }}" placeholder="z. B. ADAC" style="{{ $kfzInputStyle }}" aria-label="Versicherer des Erstwagens"></div>
                    <div class="field"><label>Vertragsnummer</label><input type="text" name="{{ $n('ext_contract_number') }}" maxlength="60" value="{{ $ref['ext_contract_number'] }}" placeholder="z. B. AD-5406305005" style="{{ $kfzInputStyle }}" aria-label="Vertragsnummer des Erstwagens"></div>
                    <div class="field"><label>Kennzeichen (optional)</label><input type="text" name="{{ $n('ext_license_plate') }}" maxlength="20" value="{{ $ref['ext_license_plate'] }}" placeholder="z. B. HH-AB 123" style="{{ $kfzInputStyle }}" aria-label="Kennzeichen des Erstwagens"></div>
                    <div class="field"><label>SF {{ $isVk ? 'Vollkasko' : 'Haftpflicht' }} des Erstwagens</label>
                        <select name="{{ $n('ext_sf_class') }}" style="{{ $kfzInputStyle }}" aria-label="SF-Klasse des Erstwagens">
                            <option value="">— unbekannt —</option>
                            @foreach(VD::sfClassKeys() as $key)
                            <option value="{{ $key }}" {{ $ref['ext_sf_class'] === $key ? 'selected' : '' }}>{{ VD::sfLabel($key) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label class="kfz-check"><input type="checkbox" name="{{ $n('create_external') }}" value="1" {{ $ref['create_external'] ? 'checked' : '' }}> Als Fremdvertrag im Kundenbestand anlegen <span class="kfz-hint">(nur Dokumentation – keine Courtage, zählt nicht zum Eigenbestand; beim nächsten Mal direkt auswählbar)</span></label>
                <button type="button" class="kfz-ref-back" style="margin-top:6px;">← Doch aus dem Bestand wählen</button>
            </div>

            <div class="kfz-subline" style="margin-top:12px;">Halter des Erstwagens</div>
            <div class="kfz-chip-row">
                @foreach(SR::HOLDER_RELATIONS as $key => $label)
                <label class="kfz-chip"><input type="radio" class="kfz-ref-holder" name="{{ $n('holder_relation') }}" value="{{ $key }}" {{ $ref['holder_relation'] === $key ? 'checked' : '' }}><span>{{ $label }}</span></label>
                @endforeach
            </div>
            <div class="field kfz-ref-holder-name" style="margin-top:8px;max-width:360px;" @if(! in_array($ref['holder_relation'], ['partner', 'familie', 'sonstige'], true)) hidden @endif>
                <label>Name des Halters</label><input type="text" name="{{ $n('holder_name') }}" maxlength="120" value="{{ $ref['holder_name'] }}" style="{{ $kfzInputStyle }}" aria-label="Name des Halters">
            </div>
            <div class="kfz-warn kfz-ref-holder-warn" hidden>Der gewählte Erstwagen gehört einem anderen Kunden – bitte angeben, in welcher Beziehung der Halter steht.</div>

            @if($sfRefCtx['documents']->isNotEmpty() || $ref['proof_document_id'])
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:12px;">
                <div class="field"><label>Nachweis (z. B. Beitragsrechnung des Erstwagens)</label>
                    <select name="{{ $n('proof_document_id') }}" style="{{ $kfzInputStyle }}" aria-label="Nachweis">
                        <option value="">— kein Nachweis —</option>
                        @foreach($sfRefCtx['documents'] as $doc)
                        <option value="{{ $doc->id }}" {{ $ref['proof_document_id'] === $doc->id ? 'selected' : '' }}>{{ $doc->file_name }} ({{ $doc->created_at?->lokal()->format('d.m.Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="align-self:end;">
                    @if($sfRefCtx['mayVerify'])
                    <label class="kfz-check"><input type="checkbox" name="{{ $n('verified') }}" value="1" {{ $ref['verified'] ? 'checked' : '' }}> ✔ Bezug geprüft</label>
                    @elseif($ref['verified'])
                    <span class="kfz-transfer ok">✔ Geprüft</span>
                    @else
                    <span class="kfz-hint">Prüfen dürfen Admin und Manager.</span>
                    @endif
                    @if($refModel?->verified && $refModel->verified_at)
                    <div class="kfz-hint">Geprüft von {{ $refModel->verifier?->name ?? '—' }} am {{ $refModel->verified_at->lokal()->format('d.m.Y') }}</div>
                    @endif
                </div>
            </div>
            @endif

            @if($refModel?->snapshot_sf_class)
            <div class="kfz-hint" style="margin-top:8px;">Bei Gewährung ({{ $refModel->snapshot_date?->format('d.m.Y') ?? '—' }}) hatte der Erstwagen {{ VD::sfLabel($refModel->snapshot_sf_class) }}.</div>
            @endif
        </div>
    </div>

    {{-- ===== Fuehrerscheindatum (Bezugs- und Fuehrerschein-Gruende) ===== --}}
    <div class="kfz-sfref-block" data-reasons="{{ $refReasons }},fuehrerschein_3,fuehrerschein_5" style="margin-top:10px;max-width:280px;">
        <div class="field"><label>Führerschein des Fahrers seit</label>
            <input type="date" class="kfz-ref-license" name="{{ $n('license_date') }}" value="{{ $ref['license_date'] }}" max="{{ now()->toDateString() }}" style="{{ $kfzInputStyle }}" aria-label="Führerschein seit">
        </div>
    </div>

    {{-- ===== Sonderaktion ===== --}}
    <div class="kfz-sfref-block" data-reasons="sonderaktion" style="margin-top:10px;max-width:360px;">
        <div class="field"><label>Name der Aktion</label><input type="text" name="{{ $n('campaign_name') }}" maxlength="120" value="{{ $ref['campaign_name'] }}" placeholder="z. B. Wechselaktion Herbst 2026" style="{{ $kfzInputStyle }}" aria-label="Name der Aktion"></div>
    </div>

    {{-- ===== Freitext ===== --}}
    <div class="kfz-sfref-block" data-reasons="{{ $refReasons }},sonstige,firmenfahrzeug" style="margin-top:10px;">
        <div class="field"><label>Bemerkung</label><textarea name="{{ $n('note') }}" rows="2" maxlength="2000" style="{{ $kfzInputStyle }}" aria-label="Bemerkung zur Sondereinstufung">{{ $ref['note'] }}</textarea></div>
    </div>
</div>
