@extends('layouts.partner')
@section('content')
<div class="page-title">Meine Kunden</div>
<div class="page-sub">Die Ihnen zugeordneten Kunden.</div>

<div class="card">
    <table>
        <thead><tr><th>Kunde</th><th>Kundennr.</th><th>Aktive Verträge</th><th></th></tr></thead>
        <tbody>
        @forelse($customers as $c)
        <tr>
            <td style="font-weight:600;">{{ $c->user?->name }}</td>
            <td style="color:var(--ink-soft);">{{ $c->customer_number }}</td>
            <td>{{ $c->contracts->count() }}</td>
            <td style="text-align:right;"><a href="{{ route('partner.customer', $c->id) }}" class="btn btn-ghost btn-sm">Ansehen</a></td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:var(--ink-soft);padding:24px;">Ihnen sind noch keine Kunden zugeordnet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($customers->hasPages())
<div style="margin-top:14px;display:flex;gap:8px;">
    @if(!$customers->onFirstPage())<a href="{{ $customers->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>@endif
    @if($customers->hasMorePages())<a href="{{ $customers->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>@endif
</div>
@endif
@endsection
