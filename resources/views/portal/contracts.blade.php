@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div><div class="page-title">{{ __('Meine Verträge') }}</div><div class="page-sub">{{ __('Alle Ihre Verträge im Überblick.') }}</div></div>
    <button onclick="document.getElementById('report-contract-modal').style.display='flex'" class="btn btn-gold">+ {{ __('Neuen Vertrag melden') }}</button>
</div>
@php
$typeIcons = [
    'kfz' => '🚗', 'schutzbrief' => '🆘', 'strom' => '⚡', 'gas' => '🔥', 'strom_gas' => '⚡', 'internet' => '📶', 'haftpflicht' => '🛡️',
    'hausrat' => '🏠', 'rechtsschutz' => '⚖️', 'krankenversicherung' => '🏥',
    'leben' => '❤️', 'unfall' => '🚑', 'andere' => '📋',
];
$typeLabels = [
    'kfz' => 'KFZ', 'schutzbrief' => 'Schutzbrief / Mobilclub', 'strom' => 'Strom', 'gas' => 'Gas', 'strom_gas' => 'Strom/Gas', 'internet' => 'Internet', 'haftpflicht' => 'Haftpflicht',
    'hausrat' => 'Hausrat', 'rechtsschutz' => 'Rechtsschutz', 'krankenversicherung' => 'Krankenversicherung',
    'leben' => 'Leben', 'unfall' => 'Unfall', 'andere' => 'Andere',
];
@endphp

@if($contracts->isEmpty())
<div class="card"><p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">{{ __('Noch keine Verträge vorhanden. Melden Sie Ihren ersten Vertrag über den Button oben.') }}</p></div>
@else
{{-- Kosten-Statistik: auf den Monat normierte Summe aller aktiven Vertraege,
     damit unterschiedliche Zahlweisen (monatlich/jaehrlich ...) vergleichbar sind. --}}
@php
    // Aktive Vertraege zentral aus dem Modell (Contract::isCurrentlyActive) -
    // gleiche Definition wie in der Beraterwelt. Ein zum Ablauf gekuendigter
    // oder abgelaufener Vertrag zaehlt nicht mehr zu den laufenden Kosten.
    $activeContracts = $contracts->filter(fn($c) => $c->isCurrentlyActive());
    $pendingContracts = $contracts->filter(fn($c) => $c->isPendingStatus());
    $historyContracts = $contracts->filter(fn($c) => $c->isHistoric());
    $monthlyTotal = $activeContracts->sum(fn($c) => $c->monthlyPremium());
    $yearlyTotal  = $activeContracts->sum(fn($c) => $c->yearlyPremium());
    $withPremium  = $activeContracts->filter(fn($c) => $c->hasPremium())->count();
    $eur = fn($v) => number_format((float) $v, 2, ',', '.') . ' €';
@endphp
@if($withPremium > 0)
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:14px;">💶 {{ __('Kostenübersicht') }}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
        <div style="background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px 16px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">{{ __('Monatlich gesamt') }}</div>
            <div style="font-size:22px;font-weight:700;">{{ $eur($monthlyTotal) }}</div>
        </div>
        <div style="background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px 16px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">{{ __('Jährlich gesamt') }}</div>
            <div style="font-size:22px;font-weight:700;">{{ $eur($yearlyTotal) }}</div>
        </div>
    </div>
    <div style="font-size:11.5px;color:var(--ink-soft);margin-top:10px;">{{ __('Basierend auf :count aktiven Verträgen mit hinterlegtem Beitrag.', ['count' => $withPremium]) }}</div>
</div>
@endif
{{-- Laufende Vertraege zuerst, beendete Vertraege in einem eigenen Abschnitt
     darunter: die Kundin/der Kunde sieht auf einen Blick, was heute gilt, und
     verwechselt einen gekuendigten Vertrag nicht mit einem laufenden. --}}
@php
    $abschnitte = [
        ['contracts' => $activeContracts,  'title' => 'Laufende Verträge',    'hint' => null, 'muted' => false],
        ['contracts' => $pendingContracts, 'title' => 'In Bearbeitung',       'hint' => 'Diese Verträge sind noch nicht abgeschlossen.', 'muted' => true],
        ['contracts' => $historyContracts, 'title' => 'Beendete Verträge',    'hint' => 'Diese Verträge sind beendet und gelten nicht mehr. Sie bleiben für Ihre Unterlagen sichtbar.', 'muted' => true],
    ];
@endphp
@foreach($abschnitte as $abschnitt)
@if($abschnitt['contracts']->isNotEmpty())
<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin:4px 0 12px;">
    <div class="card-title" style="margin-bottom:0;">{{ __($abschnitt['title']) }}</div>
    <span style="font-size:12.5px;color:var(--ink-soft);">({{ $abschnitt['contracts']->count() }})</span>
</div>
@if($abschnitt['hint'])
<div style="font-size:12.5px;color:var(--ink-soft);background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:9px 12px;margin-bottom:14px;line-height:1.55;">
    {{ __($abschnitt['hint']) }}
</div>
@endif
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:24px;">
    @foreach($abschnitt['contracts'] as $c)
    <a href="{{ route('portal.contracts.show', $c->id) }}" class="card metric-link" style="margin-bottom:0;text-decoration:none;color:var(--ink);{{ $abschnitt['muted'] ? 'background:var(--canvas);opacity:.85;' : '' }}">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:34px;line-height:1;">{{ $c->typeIcon() }}</span>
            @php $st = $c->displayStatus(); @endphp
            <span class="badge badge-{{ $st['badge'] }}" style="white-space:nowrap;">{{ __($st['label_key'], $st['params']) }}</span>
        </div>
        <div style="font-weight:700;font-size:15px;margin-bottom:2px;">{{ $c->insurer }}</div>
        <div style="font-size:12.5px;color:var(--ink-soft);">{{ __($c->typeLabel()) }}@if($c->contract_number) · {{ $c->contract_number }}@endif</div>
        @if($c->hasPremium())
        <div style="font-size:13px;font-weight:600;margin-top:8px;">{{ $eur($c->premium_amount) }} <span style="font-size:11.5px;color:var(--ink-soft);font-weight:500;">/ {{ __(\App\Models\Contract::PREMIUM_INTERVALS[$c->premium_interval]['label'] ?? 'Monatlich') }}</span></div>
        @endif
        @if($c->start_date)<div style="font-size:12px;color:var(--ink-soft);margin-top:6px;">{{ __('Seit :date', ['date' => \Carbon\Carbon::parse($c->start_date)->format('d.m.Y')]) }}</div>@endif
        <div class="metric-cta" style="margin-top:12px;">{{ __('Details ansehen') }} →</div>
    </a>
    @endforeach
</div>
@endif
@endforeach
@endif

{{-- Modal: Neuen Vertrag melden (erzeugt nur einen Change Request) --}}
<div id="report-contract-modal" class="d24-modal">
    <div class="d24-modal-box">
        <button onclick="document.getElementById('report-contract-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">{{ __('Neuen Vertrag melden') }}</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">{{ __('Unser Team prüft Ihre Meldung und nimmt den Vertrag anschließend auf.') }}</p>
        <form method="POST" action="{{ route('portal.contracts.report') }}" enctype="multipart/form-data">
            @csrf
            <div class="grid-2">
                <div class="field"><label>{{ __('Versicherungsart *') }}</label>
                    <select name="type" required>
                        <option value="kfz">{{ __('🚗 KFZ') }}</option>
                        <option value="schutzbrief">{{ __('🆘 Schutzbrief / Mobilclub (z. B. ADAC)') }}</option>
                        <option value="krankenversicherung">{{ __('🏥 Krankenversicherung') }}</option>
                        <option value="haftpflicht">{{ __('🛡️ Haftpflicht') }}</option>
                        <option value="rechtsschutz">{{ __('⚖️ Rechtsschutz') }}</option>
                        <option value="hausrat">{{ __('🏠 Hausrat') }}</option>
                        <option value="leben">{{ __('❤️ Leben') }}</option>
                        <option value="unfall">{{ __('🚑 Unfall') }}</option>
                        <option value="internet">{{ __('📶 Internet') }}</option>
                        <option value="strom">{{ __('⚡ Strom') }}</option>
                        <option value="gas">{{ __('🔥 Gas') }}</option>
                        <option value="andere">{{ __('📋 Andere') }}</option>
                    </select>
                </div>
                <div class="field"><label>{{ __('Gesellschaft *') }}</label><input type="text" name="insurer" required maxlength="255" placeholder="{{ __('z.B. Allianz') }}"></div>
            </div>
            <div class="field"><label>{{ __('Vertragsnummer') }}</label><input type="text" name="contract_number" maxlength="100" placeholder="Optional"></div>
            <div class="field"><label>{{ __('Dokument (PDF/JPG/PNG, max. 10 MB)') }}</label><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"></div>
            <button type="submit" class="btn btn-primary" style="width:100%;">{{ __('Vertrag melden') }}</button>
        </form>
    </div>
</div>
@endsection
