@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.show', $signature->id) }}">{{ Str::limit($signature->title, 40) }}</a>
        <span class="breadcrumb-sep">›</span><span>Audit-Protokoll</span>
    </div>
    <div>
        <div class="page-title">Audit-Protokoll</div>
        <div class="page-sub">
            Vollständiger Hergang der Signaturanfrage. Einträge lassen sich nicht ändern und nicht löschen –
            ein Protokoll, das der Protokollierte ändern kann, belegt nichts.
        </div>
    </div>
</div>

<div class="card card-flush">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:10px 20px;">Zeitpunkt</th>
            <th>Ereignis</th>
            <th>Wer</th>
            <th>Angaben</th>
            <th>IP</th>
            <th>Gerät</th>
        </tr></thead>
        <tbody>
        @forelse($events as $event)
        <tr>
            <td style="padding:10px 20px;font-size:13px;white-space:nowrap;">{{ $event->created_at?->lokal()->format('d.m.Y H:i:s') }}</td>
            <td style="font-size:13px;font-weight:600;">{{ $event->label() }}</td>
            <td style="font-size:13px;">{{ $event->user?->name ?? $event->actor ?? 'System' }}</td>
            <td style="font-size:13px;">{{ $event->description ?? '—' }}</td>
            <td style="font-size:12.5px;">{{ $event->ip ?? '—' }}</td>
            <td style="font-size:12px;color:var(--ink-soft);max-width:280px;">{{ Str::limit($event->user_agent ?? '—', 70) }}</td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--ink-soft);">Keine Einträge.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
