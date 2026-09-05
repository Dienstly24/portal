@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.system_health') }}">Systemzustand</a><span class="breadcrumb-sep">›</span><span>Fehler</span>
    </div>
    <div class="page-title">Fehler</div>
    <div class="page-sub">Was im Betrieb wirklich kaputtgeht – zusammengefasst, nicht jedes Auftreten einzeln.</div>
</div>

@if(session('success'))
<div style="background:var(--emerald-soft);border:1px solid #BFE6D2;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13.5px;color:#0E7A41;">
    {{ session('success') }}
</div>
@endif

<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:16px;flex-wrap:wrap;">
    <a href="{{ route('admin.errors') }}"
        style="padding:12px 20px;text-decoration:none;font-size:14px;margin-bottom:-2px;
               font-weight:{{ $zeigeErledigte ? '500' : '700' }};
               color:{{ $zeigeErledigte ? 'var(--ink-soft)' : 'var(--graphite)' }};
               border-bottom:2px solid {{ $zeigeErledigte ? 'transparent' : 'var(--graphite)' }};">
        Offen ({{ $zaehler['offen'] }})
    </a>
    <a href="{{ route('admin.errors', ['erledigt' => 1]) }}"
        style="padding:12px 20px;text-decoration:none;font-size:14px;margin-bottom:-2px;
               font-weight:{{ $zeigeErledigte ? '700' : '500' }};
               color:{{ $zeigeErledigte ? 'var(--graphite)' : 'var(--ink-soft)' }};
               border-bottom:2px solid {{ $zeigeErledigte ? 'var(--graphite)' : 'transparent' }};">
        Erledigt ({{ $zaehler['erledigt'] }})
    </a>
</div>

<div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;line-height:1.6;background:#EEF0F3;border:1px solid #E0DCD0;border-radius:8px;padding:10px 14px;">
    Gespeichert werden nur technische Angaben – Fehlerklasse, Meldung, Datei, Route.
    <strong>Nie</strong> Formularinhalte, Query-Parameter oder IP-Adressen.
    Der vollständige Stacktrace steht weiterhin in <code>storage/logs/laravel.log</code>.
</div>

<div class="card card-flush">
    <table>
        <thead>
            <tr style="background:#F8F9FA;">
                <th style="padding:12px 20px;">Fehler</th>
                <th>Ort</th>
                <th>Route</th>
                <th class="num">Anzahl</th>
                <th>Zuletzt</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($fehler as $f)
        <tr>
            <td style="padding:13px 20px;max-width:340px;">
                <div style="font-weight:700;font-size:13.5px;">{{ $f->shortClass() }}</div>
                <div style="font-size:12px;color:var(--ink-soft);word-break:break-word;">{{ $f->message }}</div>
            </td>
            <td style="font-size:12px;color:var(--ink-soft);word-break:break-all;">
                {{ $f->shortFile() }}@if($f->line):{{ $f->line }}@endif
            </td>
            <td style="font-size:12px;">
                {{ $f->route ?? '—' }}
                @if($f->method)<div class="muted">{{ $f->method }}</div>@endif
            </td>
            <td style="text-align:right;font-weight:700;font-size:14px;">{{ $f->occurrences }}</td>
            <td style="font-size:12px;color:var(--ink-soft);white-space:nowrap;">
                {{ $f->last_seen_at?->lokal()->format('d.m. H:i') ?? '—' }}
                @if($f->lastUser)<div>{{ $f->lastUser->name }}</div>@endif
            </td>
            <td style="padding-right:20px;white-space:nowrap;">
                @if($f->resolved_at)
                <form method="POST" action="{{ route('admin.errors.reopen', $f->id) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Wieder öffnen</button>
                </form>
                @else
                <form method="POST" action="{{ route('admin.errors.resolve', $f->id) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Erledigt</button>
                </form>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--ink-soft);">
            @if($zeigeErledigte)Keine erledigten Fehler.@else Keine offenen Fehler – gut so.@endif
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($fehler->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 2px;flex-wrap:wrap;">
    <div class="muted-sm">
        {{ $fehler->firstItem() }}–{{ $fehler->lastItem() }} von {{ $fehler->total() }}
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        @if($fehler->onFirstPage())
            <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">← Zurück</span>
        @else
            <a href="{{ $fehler->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
        @endif
        <span class="muted-sm">Seite {{ $fehler->currentPage() }} / {{ $fehler->lastPage() }}</span>
        @if($fehler->hasMorePages())
            <a href="{{ $fehler->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>
        @else
            <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">Weiter →</span>
        @endif
    </div>
</div>
@endif
@endsection
