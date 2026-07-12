@extends('layouts.portal')
@section('content')
{{-- Werbebanner-Carousel (Punkt 3/4) --}}
@if(isset($banners) && $banners->isNotEmpty())
<div id="banner-carousel" style="position:relative;border-radius:14px;overflow:hidden;margin-bottom:24px;border:1px solid var(--line);">
    @foreach($banners as $i => $b)
    <a href="{{ route('portal.banner.interest', $b->id) }}" class="banner-slide" data-slide="{{ $i }}" style="display:{{ $i === 0 ? 'block' : 'none' }};position:relative;text-decoration:none;">
        @if($b->media_type === 'video')
        <video src="{{ asset('storage/' . $b->media_path) }}" style="width:100%;max-height:260px;object-fit:cover;display:block;" autoplay muted loop playsinline></video>
        @else
        <img src="{{ asset('storage/' . $b->media_path) }}" style="width:100%;max-height:260px;object-fit:cover;display:block;" alt="{{ $b->title }}">
        @endif
        <span style="position:absolute;left:0;right:0;bottom:0;padding:14px 18px;background:linear-gradient(transparent,rgba(0,0,0,.65));color:#fff;font-weight:700;font-size:15px;">{{ $b->title }} <span style="font-weight:400;font-size:12.5px;">– Jetzt anfragen →</span></span>
    </a>
    @endforeach
    @if($banners->count() > 1)
    <div style="position:absolute;bottom:10px;right:14px;display:flex;gap:6px;">
        @foreach($banners as $i => $b)
        <span class="banner-dot" data-dot="{{ $i }}" style="width:9px;height:9px;border-radius:50%;background:{{ $i === 0 ? '#fff' : 'rgba(255,255,255,.45)' }};cursor:pointer;"></span>
        @endforeach
    </div>
    <script>
    (function(){
        const slides=document.querySelectorAll('#banner-carousel .banner-slide');
        const dots=document.querySelectorAll('#banner-carousel .banner-dot');
        let cur=0;
        function show(n){slides[cur].style.display='none';dots[cur].style.background='rgba(255,255,255,.45)';cur=n%slides.length;slides[cur].style.display='block';dots[cur].style.background='#fff';}
        dots.forEach(d=>d.addEventListener('click',e=>{e.preventDefault();show(parseInt(d.dataset.dot));}));
        setInterval(()=>show(cur+1),6000);
    })();
    </script>
    @endif
</div>
@endif


<div class="page-title">Übersicht</div>
<div class="page-sub">Willkommen zurück, {{ auth()->user()->name }}.</div>
<div class="grid-3">
    <a href="{{ route('portal.contracts') }}" class="metric metric-link" title="Zur Vertragsübersicht">
        <div class="label">📑 Aktive Verträge</div><div class="value">{{ $contractsCount }}</div>
        <div class="metric-cta">Verträge ansehen →</div>
    </a>
    <a href="{{ route('portal.tickets') }}" class="metric metric-link" title="Zu Ihren Nachrichten">
        <div class="label">💬 Offene Anfragen</div><div class="value">{{ $openTickets }}</div>
        <div class="metric-cta">Nachrichten öffnen →</div>
    </a>
    <a href="{{ route('portal.change_requests') }}" class="metric metric-link" title="Status Ihrer Änderungsanfragen">
        <div class="label">🔄 Änderungen in Prüfung</div><div class="value">{{ $pendingApprovals }}</div>
        <div class="metric-cta">Status ansehen →</div>
    </a>
</div>

{{-- Schnellzugriff (Review Punkt 3: jede Kachel hat eine klare Funktion) --}}
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
    @foreach([
        ['route' => 'portal.documents', 'icon' => '📄', 'label' => 'Dokumente'],
        ['route' => 'portal.profile', 'icon' => '👤', 'label' => 'Meine Daten'],
        ['route' => 'portal.family', 'icon' => '👨‍👩‍👦', 'label' => 'Familie'],
        ['route' => 'portal.contacts', 'icon' => '📞', 'label' => 'Kontakte'],
    ] as $tile)
    <a href="{{ route($tile['route']) }}" class="card metric-link" style="margin-bottom:0;text-align:center;padding:18px 10px;text-decoration:none;color:var(--ink);">
        <div style="font-size:26px;margin-bottom:6px;">{{ $tile['icon'] }}</div>
        <div style="font-size:13px;font-weight:600;">{{ $tile['label'] }}</div>
    </a>
    @endforeach
</div>

{{-- Kundenakte-Vollständigkeit (Final Polish Punkt 5) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">📋 Ihre Kundenakte</div>
        <span style="font-size:20px;font-weight:800;color:{{ $completeness['percent'] >= 80 ? '#3B7A57' : ($completeness['percent'] >= 50 ? '#B5651D' : '#A32D2D') }};">{{ $completeness['percent'] }} %</span>
    </div>
    <div style="height:10px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin-bottom:6px;">
        <div style="height:100%;width:{{ $completeness['percent'] }}%;background:{{ $completeness['percent'] >= 80 ? '#3B7A57' : ($completeness['percent'] >= 50 ? '#D9A441' : '#E24B4A') }};transition:width .3s;"></div>
    </div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">{{ $completeness['percent'] }} % vollständig</div>
    @if(count($completeness['missing']))
    <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($completeness['missing'] as $m)
        <a href="{{ route($m['route']) }}" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:13.5px;{{ !empty($m['optional']) ? 'opacity:.7;' : '' }}">
            <span>⚠ {{ $m['label'] }}</span>
            <span style="color:var(--petrol);font-size:12px;">ergänzen →</span>
        </a>
        @endforeach
    </div>
    @else
    <div style="font-size:13.5px;color:#3B7A57;">✓ Ihre Kundenakte ist vollständig.</div>
    @endif
</div>

{{-- Offene Dokumentenanfragen prominent anzeigen (Priorität 8) --}}
@php $openDocRequests = \App\Models\DocumentRequest::where('customer_id', $customer->id)->openForCustomer()->get(); @endphp
@if($openDocRequests->isNotEmpty())
<div class="card" style="border-left:4px solid #D9A441;">
    <div class="card-title">📄 Wir benötigen Unterlagen von Ihnen</div>
    @foreach($openDocRequests as $odr)
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;font-size:14px;">
        <div>
            {{ $odr->title }}
            @if($odr->deadline)<span style="color:{{ $odr->deadline->isPast() ? '#A32D2D' : 'var(--ink-soft)' }};font-size:12.5px;"> · Frist {{ $odr->deadline->format('d.m.Y') }}</span>@endif
        </div>
        <a href="{{ route('portal.documents') }}" class="btn btn-gold" style="padding:6px 14px;font-size:13px;flex:none;">Hochladen</a>
    </div>
    @endforeach
</div>
@endif

{{-- Kundenakte-Vollständigkeit (Final Polish Punkt 5) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">📋 Ihre Kundenakte</div>
        <span style="font-size:20px;font-weight:800;color:{{ $completeness['percent'] >= 80 ? '#3B7A57' : ($completeness['percent'] >= 50 ? '#B5651D' : '#A32D2D') }};">{{ $completeness['percent'] }} %</span>
    </div>
    <div style="height:10px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin-bottom:6px;">
        <div style="height:100%;width:{{ $completeness['percent'] }}%;background:{{ $completeness['percent'] >= 80 ? '#3B7A57' : ($completeness['percent'] >= 50 ? '#D9A441' : '#E24B4A') }};transition:width .3s;"></div>
    </div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">{{ $completeness['percent'] }} % vollständig</div>
    @if(count($completeness['missing']))
    <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($completeness['missing'] as $m)
        <a href="{{ route($m['route']) }}" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:13.5px;{{ !empty($m['optional']) ? 'opacity:.7;' : '' }}">
            <span>⚠ {{ $m['label'] }}</span>
            <span style="color:var(--petrol);font-size:12px;">ergänzen →</span>
        </a>
        @endforeach
    </div>
    @else
    <div style="font-size:13.5px;color:#3B7A57;">✓ Ihre Kundenakte ist vollständig.</div>
    @endif
</div>
<div class="card">
    <div class="card-title">Letzte Verträge</div>
    @forelse($contracts as $c)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->insurer }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $c->contract_number }} · {{ ucfirst($c->type) }}</div>
        </div>
        <span class="badge badge-{{ $c->status === 'active' ? 'active' : 'pending' }}">{{ $c->status === 'active' ? 'Aktiv' : ucfirst($c->status) }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Verträge vorhanden.</p>
    @endforelse
</div>
<div class="card">
    <div class="card-title">Letzte Anfragen</div>
    @forelse($tickets as $t)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $t->subject }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }}</div>
        </div>
        <span class="badge badge-{{ $t->status === 'open' ? 'open' : 'closed' }}">{{ $t->status === 'open' ? 'Offen' : 'In Bearbeitung' }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Anfragen vorhanden.</p>
    @endforelse
</div>
@endsection
