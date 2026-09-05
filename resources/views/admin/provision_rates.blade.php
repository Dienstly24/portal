@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.provisions') }}">Vermittler-Provisionen</a><span class="breadcrumb-sep">›</span><span>Sätze</span></div>
    <div class="page-title">Provisions-Sätze</div>
    <div class="page-sub">Je Mitarbeiter und Partner ein eigener Satz pro Sparte - neue Verträge werden damit automatisch vergütet. Ohne Satz keine automatische Buchung.</div>
</div>

@include('admin.partials.provision_tabs', ['active' => 'saetze'])

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="grid-2" style="align-items:start;">
    {{-- Empfaenger-Uebersicht --}}
    <div class="card card-flush">
        <div class="card-head-bar">Empfänger wählen</div>
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 20px;">Name</th>
                <th>Sparten-Sätze</th>
                <th>Globaler Satz</th>
                <th style="padding-right:20px;"></th>
            </tr></thead>
            <tbody>
            @foreach($employees as $e)
            <tr style="{{ $selectedKey === 'u:' . $e->id ? 'background:#F4F7F5;' : '' }}">
                <td style="padding:10px 20px;"><span class="wb-badge wb-mit">👤</span> <strong>{{ $e->name }}</strong></td>
                <td>{{ $userRateCounts[$e->id] ?? 0 }} Sparten</td>
                <td style="font-size:12.5px;color:var(--ink-soft);">
                    {{ $e->provision_fixed !== null ? number_format((float) $e->provision_fixed, 2, ',', '.') . ' €' : '—' }}
                    @if($e->provision_percent !== null) + {{ number_format((float) $e->provision_percent, 1, ',', '.') }} % @endif
                </td>
                <td style="padding-right:20px;text-align:right;"><a href="{{ route('admin.provisions.rates', ['empfaenger' => 'u:' . $e->id]) }}" class="btn btn-ghost btn-sm">Sätze pflegen</a></td>
            </tr>
            @endforeach
            @foreach($partners as $p)
            <tr style="{{ $selectedKey === 'p:' . $p->id ? 'background:#F4F7F5;' : '' }}">
                <td style="padding:10px 20px;"><span class="wb-badge wb-par">🤝</span> <strong>{{ $p->name }}</strong></td>
                <td>{{ $partnerRateCounts[$p->id] ?? 0 }} Sparten</td>
                <td style="font-size:12.5px;color:var(--ink-soft);">
                    {{ $p->provision_fixed !== null ? number_format((float) $p->provision_fixed, 2, ',', '.') . ' €' : '—' }}
                    @if($p->provision_percent !== null) + {{ number_format((float) $p->provision_percent, 1, ',', '.') }} % @endif
                </td>
                <td style="padding-right:20px;text-align:right;"><a href="{{ route('admin.provisions.rates', ['empfaenger' => 'p:' . $p->id]) }}" class="btn btn-ghost btn-sm">Sätze pflegen</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- Saetze des gewaehlten Empfaengers --}}
    <div class="card card-flush">
        @if($selected)
        <div class="card-head-bar">
            Sätze für {{ $selected->name }}
            <div style="font-size:12px;font-weight:400;color:var(--ink-soft);margin-top:2px;">
                Fester Betrag je Neuvertrag und/oder Prozent vom Jahresbeitrag. Beide Felder leer = kein Satz für die Sparte (dann greift der globale Satz).
            </div>
        </div>
        <form method="POST" action="{{ route('admin.provisions.rates.save') }}" style="margin:0;">
            @csrf
            <input type="hidden" name="empfaenger" value="{{ $selectedKey }}">
            <table>
                <thead><tr style="background:#F8F9FA;">
                    <th style="padding:10px 20px;">Sparte</th>
                    <th>Fix (EUR je Vertrag)</th>
                    <th style="padding-right:20px;">% vom Jahresbeitrag</th>
                </tr></thead>
                <tbody>
                <tr style="background:#FBFAF6;">
                    <td style="padding:10px 20px;font-weight:700;">🌐 Globaler Satz (Fallback)</td>
                    <td><input type="number" step="0.01" min="0" name="global_fixed" value="{{ $selected->provision_fixed }}" placeholder="—" class="rate-inp"></td>
                    <td style="padding-right:20px;"><input type="number" step="0.1" min="0" max="100" name="global_percent" value="{{ $selected->provision_percent }}" placeholder="—" class="rate-inp"></td>
                </tr>
                @foreach(\App\Models\Contract::TYPES as $key => $cfg)
                <tr>
                    <td style="padding:8px 20px;">{{ $cfg['icon'] }} {{ $cfg['label'] }}</td>
                    <td><input type="number" step="0.01" min="0" name="saetze[{{ $key }}][fixed]" value="{{ $rates[$key]->amount_fixed ?? '' }}" placeholder="—" class="rate-inp"></td>
                    <td style="padding-right:20px;"><input type="number" step="0.1" min="0" max="100" name="saetze[{{ $key }}][percent]" value="{{ $rates[$key]->amount_percent ?? '' }}" placeholder="—" class="rate-inp"></td>
                </tr>
                @endforeach
                </tbody>
            </table>
            <div style="padding:14px 20px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Sätze speichern</button>
            </div>
        </form>
        @else
        <div style="padding:40px 24px;text-align:center;color:var(--ink-soft);">
            <div style="font-size:32px;margin-bottom:10px;">💶</div>
            Links einen Mitarbeiter oder Partner wählen, um dessen Provisions-Sätze je Sparte zu pflegen.<br>
            <span style="font-size:12.5px;">Beispiel: GKV 50 € für Mitarbeiter A, 40 € für Mitarbeiter B, 60 € für Partner X.</span>
        </div>
        @endif
    </div>
</div>
@include('admin.partials.provision_styles')
<style>.rate-inp{width:130px;padding:7px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;}</style>
@endsection
