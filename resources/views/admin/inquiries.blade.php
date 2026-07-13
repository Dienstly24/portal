@extends('layouts.admin')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">Anfragen</div>
        <div class="page-sub">Leads von der Website (dienstly24.com) und per E-Mail (info@).</div>
    </div>
    <a href="{{ route('admin.inquiries.create') }}" class="btn btn-primary">+ Anfrage erfassen</a>
</div>
<div class="card">
    <table>
        <thead><tr><th>Nr.</th><th>Betreff</th><th>Name</th><th>Kontakt</th><th>Quelle</th><th>Priorität</th><th>Datum</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $t)
        <tr>
            <td style="color:var(--ink-soft);font-size:13px;white-space:nowrap;">{{ $t->ticket_number }}</td>
            <td style="font-weight:600;">{{ $t->subject }}</td>
            <td>{{ $t->guest_name ?? '—' }}</td>
            <td style="color:var(--ink-soft);font-size:13px;">{{ $t->guest_email }}@if($t->guest_phone)<br>{{ $t->guest_phone }}@endif</td>
            <td>{{ $t->source === 'website' ? '🌐 Website' : '📧 E-Mail' }}</td>
            <td>{{ \App\Models\Ticket::PRIORITIES[$t->priority]['icon'] ?? '🟡' }}</td>
            <td style="color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }}</td>
            <td><span class="badge badge-{{ $t->statusBadge() }}">{{ $t->statusLabel() }}</span></td>
            <td><a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        @empty
        <tr><td colspan="9" style="color:var(--ink-soft);text-align:center;padding:24px;">Keine Anfragen vorhanden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if($tickets->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
    <div style="font-size:13px;color:var(--ink-soft);">
        {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} von {{ $tickets->total() }} Anfragen
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        @if(!$tickets->onFirstPage())
            <a href="{{ $tickets->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
        @endif
        <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $tickets->currentPage() }} / {{ $tickets->lastPage() }}</span>
        @if($tickets->hasMorePages())
            <a href="{{ $tickets->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>
        @endif
    </div>
</div>
@endif
@endsection
