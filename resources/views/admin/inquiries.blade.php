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
        <thead><tr><th>Betreff</th><th>Name</th><th>Kontakt</th><th>Quelle</th><th>Priorität</th><th>Datum</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $t)
        <tr>
            <td style="font-weight:600;">{{ $t->subject }}</td>
            <td>{{ $t->guest_name ?? '—' }}</td>
            <td style="color:var(--ink-soft);font-size:13px;">{{ $t->guest_email }}@if($t->guest_phone)<br>{{ $t->guest_phone }}@endif</td>
            <td>{{ $t->source === 'website' ? '🌐 Website' : '📧 E-Mail' }}</td>
            <td>{{ ['niedrig'=>'🟢','mittel'=>'🟡','hoch'=>'🔴'][$t->priority] ?? '🟡' }}</td>
            <td style="color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }}</td>
            <td><span class="badge badge-{{ $t->status === 'open' ? 'open' : ($t->status === 'closed' ? 'closed' : 'pending') }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span></td>
            <td><a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        @empty
        <tr><td colspan="8" style="color:var(--ink-soft);text-align:center;padding:24px;">Keine Anfragen vorhanden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
