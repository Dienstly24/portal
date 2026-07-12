@extends('layouts.partner')
@section('content')
<div class="page-title">Provisionen</div>
<div class="page-sub">Ihre Provisionshistorie.</div>

<div class="card">
    <table>
        <thead><tr><th>Datum</th><th>Gutschrift-Nr.</th><th>Betrag</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($commissions as $c)
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
        <tr><td colspan="4" style="text-align:center;color:var(--ink-soft);padding:24px;">Noch keine Provisionen erfasst.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($commissions->hasPages())
<div style="margin-top:14px;display:flex;gap:8px;">
    @if(!$commissions->onFirstPage())<a href="{{ $commissions->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>@endif
    @if($commissions->hasMorePages())<a href="{{ $commissions->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>@endif
</div>
@endif
@endsection
