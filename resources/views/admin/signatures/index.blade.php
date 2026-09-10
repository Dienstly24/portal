@extends('layouts.admin')
@section('content')
@php use App\Support\SignatureStatus; @endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Signaturen</span></div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;">
        <div>
            <div class="page-title">Signaturen</div>
            <div class="page-sub">Dokumente zur Unterschrift versenden – mit oder ohne Kundenakte.</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            @can('firmensignatur-verwalten')
            <a href="{{ route('admin.signatures.company.index') }}" class="btn btn-ghost">Unternehmenssignaturen</a>
            @endcan
            <a href="{{ route('admin.signatures.create') }}" class="btn btn-emerald">+ Neue Signaturanfrage</a>
        </div>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

{{-- Reiter und Suche sind normale Links bzw. ein GET-Formular: jeder Stand
     der Liste ist damit teilbar, zurueck-tauglich und als Lesezeichen
     speicherbar (Lehre aus den grossen Listen, 20.08.2026). --}}
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
    @foreach(SignatureStatus::TABS as $key => $definition)
    <a href="{{ route('admin.signatures.index', array_merge(request()->only('suche','von','bis','ersteller','kunde'), ['reiter' => $key])) }}"
       class="badge {{ $tab === $key ? 'badge-success' : 'badge-muted' }}"
       style="text-decoration:none;padding:7px 13px;font-size:13px;">
        {{ $definition['label'] }} <strong>{{ $counts[$key] ?? 0 }}</strong>
    </a>
    @endforeach
</div>

<form method="GET" action="{{ route('admin.signatures.index') }}" class="card"
      style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding:14px 16px;">
    <input type="hidden" name="reiter" value="{{ $tab }}">
    <div style="flex:1;min-width:240px;">
        <label class="muted-sm" for="suche">Suche</label>
        <input id="suche" type="search" name="suche" value="{{ $search }}" maxlength="120"
               placeholder="Dokument, Unterzeichner, E-Mail, Kunde, Referenz"
               style="width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    </div>
    <div>
        <label class="muted-sm" for="von">Erstellt von</label>
        <input id="von" type="date" name="von" value="{{ $filters['von'] ?? '' }}"
               style="padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    </div>
    <div>
        <label class="muted-sm" for="bis">bis</label>
        <input id="bis" type="date" name="bis" value="{{ $filters['bis'] ?? '' }}"
               style="padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    </div>
    <button type="submit" class="btn btn-sm btn-emerald">Filtern</button>
    @if($search !== '' || array_filter($filters))
    <a href="{{ route('admin.signatures.index', ['reiter' => $tab]) }}" class="btn btn-sm btn-ghost">Filter zurücksetzen</a>
    @endif
</form>

<div class="card card-flush">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:10px 20px;">Dokument</th>
            <th>Unterzeichner</th>
            <th>Kunde</th>
            <th>Status</th>
            <th>Erstellt</th>
            <th>Letzte Aktivität</th>
            <th>Ablauf</th>
            <th style="text-align:right;padding-right:20px;">Aktionen</th>
        </tr></thead>
        <tbody>
        @forelse($signatures as $signature)
        <tr>
            <td style="padding:12px 20px;font-size:13px;">
                <a href="{{ route('admin.signatures.show', $signature->id) }}" style="font-weight:600;">{{ $signature->title }}</a>
                <div class="muted-sm">{{ $signature->page_count }} Seite{{ $signature->page_count === 1 ? '' : 'n' }}@if($signature->reference) · Ref. {{ $signature->reference }}@endif</div>
            </td>
            <td style="font-size:13px;">
                @forelse($signature->signers as $signer)
                    <div>{{ $signer->hasSigned() ? '✓' : ($signer->hasDeclined() ? '✕' : '⏳') }} {{ $signer->name }}</div>
                @empty
                    <span class="muted-sm">noch keiner</span>
                @endforelse
            </td>
            <td style="font-size:13px;">
                @if($signature->customer)
                    <a href="{{ route('admin.customer', $signature->customer_id) }}">{{ $signature->customer->user?->name ?? $signature->customer->customer_number }}</a>
                @else
                    <span class="muted-sm">nicht zugeordnet</span>
                @endif
            </td>
            <td><span class="badge {{ $signature->statusTone() }}">{{ $signature->statusLabel() }}</span></td>
            <td style="font-size:13px;">{{ $signature->created_at?->lokal()->format('d.m.Y') }}</td>
            <td style="font-size:13px;">{{ $signature->last_activity_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</td>
            <td style="font-size:13px;color:{{ $signature->hasExpired() ? '#B3261E' : 'var(--ink-soft)' }};">
                {{ $signature->expires_at?->lokal()->format('d.m.Y') ?? '—' }}
            </td>
            <td style="text-align:right;padding-right:20px;white-space:nowrap;">
                <a href="{{ route('admin.signatures.show', $signature->id) }}" class="btn btn-sm btn-ghost">Öffnen</a>
                @if($signature->isCompleted())
                <a href="{{ route('admin.signatures.download', [$signature->id, 'signed']) }}" class="btn btn-sm btn-ghost">PDF</a>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;padding:34px;color:var(--ink-soft);">
            Keine Signaturanfragen in dieser Ansicht.
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">{{ $signatures->links() }}</div>
@endsection
