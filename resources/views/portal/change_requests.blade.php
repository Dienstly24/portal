@extends('layouts.portal')
@section('content')
<div class="page-title">🔄 {{ __('Meine Änderungsanfragen') }}</div>
<div class="page-sub">{{ __('Alle von Ihnen eingereichten Änderungen und deren Status.') }}</div>

<div class="card">
    @forelse($requests as $r)
    <div class="item-row">
        <div>
            <div style="font-size:14px;font-weight:600;">{{ __($r->typeLabel()) }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">
                {{ __('Eingereicht:') }} {{ $r->created_at->format('d.m.Y H:i') }}
                @if($r->reviewed_at) · {{ __('Bearbeitet:') }} {{ $r->reviewed_at->format('d.m.Y H:i') }} @endif
                @if($r->status === 'rejected' && $r->notes) · {{ __('Grund:') }} {{ $r->notes }} @endif
            </div>
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
