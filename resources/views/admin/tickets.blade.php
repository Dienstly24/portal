@extends('layouts.admin')
@section('content')
<div class="page-title">Tickets</div>
<div class="page-sub">Anfragen registrierter Kunden aus dem Kundenportal.</div>
<div class="card">
    <table>
        <thead><tr><th>Betreff</th><th>Kunde</th><th>Typ</th><th>Priorität</th><th>Datum</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $t)
        <tr>
            <td style="font-weight:600;">{{ $t->subject }}</td>
            <td style="color:var(--ink-soft);">{{ $t->customer?->user?->name }}</td>
            <td style="color:var(--ink-soft);">{{ ucfirst(str_replace('_',' ',$t->type)) }}</td>
            <td>{{ ['niedrig'=>'🟢 Niedrig','mittel'=>'🟡 Mittel','hoch'=>'🔴 Hoch'][$t->priority] ?? '🟡 Mittel' }}</td>
            <td style="color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }}</td>
            <td><span class="badge badge-{{ $t->status === 'open' ? 'open' : ($t->status === 'closed' ? 'closed' : 'pending') }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span></td>
            <td><a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        @empty
        <tr><td colspan="7" style="color:var(--ink-soft);text-align:center;padding:24px;">Keine Tickets vorhanden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
