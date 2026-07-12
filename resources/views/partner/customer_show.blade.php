@extends('layouts.partner')
@section('content')
<div class="page-title">{{ $customer->user?->name }}</div>
<div class="page-sub">Kundennr. {{ $customer->customer_number }}</div>

<a href="{{ route('partner.customers') }}" class="btn btn-ghost btn-sm" style="margin-bottom:16px;">← Zurück zur Liste</a>

<div class="card">
    <div class="card-title">Verträge</div>
    <table>
        <thead><tr><th>Sparte</th><th>Gesellschaft</th><th>Vertragsnr.</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($customer->contracts as $v)
        <tr>
            <td>{{ $v->type }}</td>
            <td>{{ $v->insurer ?? '—' }}</td>
            <td style="color:var(--ink-soft);">{{ $v->contract_number ?? '—' }}</td>
            <td>{{ $v->status }}</td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:var(--ink-soft);padding:20px;">Keine Verträge hinterlegt.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <div class="card-title">Hinweis</div>
    <p style="font-size:13.5px;color:var(--ink-soft);">Schreibende Aktionen (z. B. Verträge bearbeiten, Dokumente hochladen) werden im nächsten Ausbauschritt des Partnerportals ergänzt, sobald der Funktionsumfang final abgestimmt ist.</p>
</div>
@endsection
