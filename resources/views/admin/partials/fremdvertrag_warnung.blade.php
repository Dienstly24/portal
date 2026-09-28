{{--
    Warnleiste an jedem Arbeitsplatz, der mit einem FREMDVERTRAG zu tun hat
    (Betreiber-Auftrag 28.09.2026): Vertragsakte, Aenderungsantrag,
    Dokumentanforderung, Dokumenten-Eingang. Erwartet $contract; zeigt nichts,
    wenn der Vertrag kein Fremdvertrag ist - Aufrufer muessen nicht selbst
    pruefen (eine vergessene Bedingung waere eine fehlende Warnung).

    "Uebernahme anbieten" fuehrt in den Signatur-Ablauf (Maklervollmacht) -
    der Weg, aus einem Fremdvertrag einen Eigenvertrag zu machen, statt an
    einem Vertrag ohne Mandat zu arbeiten.
--}}
@if(isset($contract) && $contract && $contract->isExternal())
<div class="fremdvertrag-warnung" role="alert" style="display:flex;gap:12px;align-items:flex-start;background:#FDF1E4;border:1.5px solid #D98B3A;border-radius:10px;padding:12px 16px;margin:{{ $margin ?? '0 0 16px' }};font-size:13px;line-height:1.55;{{ isset($maxWidth) ? 'max-width:'.$maxWidth.';' : '' }}">
    <span style="font-size:20px;line-height:1;">⚠️</span>
    <div style="flex:1;min-width:0;">
        <div style="font-weight:700;color:#8A4B0F;">Achtung: Dieser Vertrag wurde nicht über uns vermittelt.</div>
        <div>
            @if(! empty($showContract))<strong>{{ $contract->typeIcon() }} {{ $contract->insurer }}@if($contract->contract_number) ({{ $contract->contract_number }})@endif</strong> · @endif
            Zuständig: <strong>{{ $contract->responsibleParty() }}</strong>. Wir haben kein Mandat für diesen Vertrag.
        </div>
        @if($contract->origin_note)
        <div class="muted-2xs" style="margin-top:2px;">📝 {{ $contract->origin_note }}</div>
        @endif
        @if(empty($noOffer) && \Illuminate\Support\Facades\Route::has('admin.signatures.create'))
        <div style="margin-top:6px;">
            <a href="{{ route('admin.signatures.create', ['vertrag' => $contract->id]) }}" class="btn btn-ghost btn-sm" style="font-size:12px;padding:5px 10px;">🔄 Übernahme anbieten (Maklervollmacht)</a>
        </div>
        @endif
    </div>
</div>
@endif
