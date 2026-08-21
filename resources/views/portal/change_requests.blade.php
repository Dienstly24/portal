@extends('layouts.portal')
@section('content')
<div class="page-title">{{ __('🔄 Meine Änderungsanfragen') }}</div>
<div class="page-sub">{{ __('Alle von Ihnen eingereichten Änderungen und deren Status.') }}</div>

<div class="card">
    @forelse($requests as $r)
    <div class="item-row">
        <div>
            <div style="font-size:14px;font-weight:600;">{{ $r->typeLabel() }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">
                Eingereicht: {{ $r->created_at->lokal()->format('d.m.Y H:i') }}
                @if($r->effective_from) · Gültig ab: {{ $r->effective_from->format('d.m.Y') }} @endif
                @if($r->reviewed_at) · Bearbeitet: {{ $r->reviewed_at->lokal()->format('d.m.Y H:i') }} @endif
                @if($r->status === 'rejected' && $r->notes) · Grund: {{ $r->notes }} @endif
            </div>
            @if($r->status === 'pending' && $r->proof_status === 'missing')
            <div style="font-size:12px;color:#A32D2D;margin-top:2px;">{{ __('📎 Es fehlt noch ein Nachweis – bitte reichen Sie ihn nach.') }}</div>
            @elseif(($r->documents_count ?? 0) > 0)
            <div style="font-size:12px;color:var(--ink-soft);margin-top:2px;">{{ __('📎 Nachweis eingereicht') }}</div>
            @endif
        </div>
        @if($r->status === 'pending')<span class="badge badge-pending">{{ __('Prüfung ausstehend') }}</span>
        @elseif($r->status === 'approved')<span class="badge badge-active">{{ __('Genehmigt') }}</span>
        @else<span class="badge" style="background:#F9E3E3;color:#A32D2D;">{{ __('Abgelehnt') }}</span>@endif
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">{{ __('Sie haben noch keine Änderungsanfragen gestellt.') }}</p>
    @endforelse
</div>
@endsection
