@extends('layouts.partner')
@section('content')
<div class="page-title">Willkommen, {{ $partner->name }}</div>
<div class="page-sub">Ihre Übersicht als Vertriebspartner von Dienstly24.</div>

<div class="grid-3">
    <div class="stat"><div class="stat-label">Meine Kunden</div><div class="stat-value">{{ $customerCount }}</div></div>
    <div class="stat"><div class="stat-label">Gebuchte Provisionen</div><div class="stat-value">{{ number_format($bookedTotal, 2, ',', '.') }} €</div></div>
    <div class="stat"><div class="stat-label">Offene Provisionen</div><div class="stat-value">{{ $openCommissions }}</div></div>
</div>

<div class="card">
    <div class="card-title">Letzte Provisionen</div>
    <table>
        <thead><tr><th>Datum</th><th>Gutschrift-Nr.</th><th>Betrag</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($recentCommissions as $c)
        <tr>
            <td>{{ $c->statement_date?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $c->credit_note_number ?? '—' }}</td>
            <td>{{ number_format((float) $c->amount, 2, ',', '.') }} {{ $c->currency ?? 'EUR' }}</td>
            <td>
                @php $s = ['booked'=>'badge-booked','pending_review'=>'badge-pending','rejected'=>'badge-rejected'][$c->status] ?? 'badge-pending'; @endphp
                <span class="badge {{ $s }}">{{ $c->status }}</span>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:var(--ink-soft);padding:20px;">Noch keine Provisionen erfasst.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
