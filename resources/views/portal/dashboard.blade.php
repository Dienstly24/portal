@extends('layouts.portal')
@section('content')
{{-- Werbebanner-Carousel: volle Breite, responsive, KEIN Beschnitt
     (Bild behält sein Seitenverhältnis – beliebige Formate 1080×1080,
     1200×628 usw. werden vollständig angezeigt). --}}
@if(isset($banners) && $banners->isNotEmpty())
<div id="banner-carousel" style="position:relative;border-radius:14px;overflow:hidden;margin-bottom:24px;border:1px solid var(--line);background:#0e1f1b;">
    @foreach($banners as $i => $b)
    @php $clickUrl = $b->link_url ? route('portal.banner.click', $b->id) : route('portal.banner.interest', $b->id); @endphp
    <div class="banner-slide" data-slide="{{ $i }}" style="display:{{ $i === 0 ? 'block' : 'none' }};position:relative;">
        <a href="{{ $clickUrl }}" @if($b->link_url && $b->link_target === 'blank') target="_blank" rel="noopener" @endif style="display:block;text-decoration:none;">
            @if($b->media_type === 'video')
            <video src="{{ asset('storage/' . $b->media_path) }}" style="width:100%;height:auto;max-height:70vh;display:block;" autoplay muted loop playsinline></video>
            @else
            <img src="{{ asset('storage/' . $b->media_path) }}" style="width:100%;height:auto;max-height:70vh;object-fit:contain;display:block;" alt="{{ $b->title }}">
            @endif
            <span style="position:absolute;left:0;right:0;bottom:0;padding:14px 18px;background:linear-gradient(transparent,rgba(0,0,0,.65));color:#fff;font-weight:700;font-size:15px;">{{ $b->title }} <span style="font-weight:400;font-size:12.5px;">– {{ __('Mehr erfahren') }} →</span></span>
        </a>
        @if($b->dismiss_days)
        <button type="button" class="banner-close" data-banner="{{ $b->id }}" title="Ausblenden"
            style="position:absolute;top:10px;right:10px;width:30px;height:30px;border-radius:50%;border:none;background:rgba(0,0,0,.45);color:#fff;font-size:15px;cursor:pointer;line-height:1;">✕</button>
        @endif
    </div>
    @endforeach
    @if($banners->count() > 1)
    <div style="position:absolute;bottom:10px;right:14px;display:flex;gap:6px;">
        @foreach($banners as $i => $b)
        <span class="banner-dot" data-dot="{{ $i }}" style="width:9px;height:9px;border-radius:50%;background:{{ $i === 0 ? '#fff' : 'rgba(255,255,255,.45)' }};cursor:pointer;"></span>
        @endforeach
    </div>
    @endif
    <script>
    (function(){
        const slides=document.querySelectorAll('#banner-carousel .banner-slide');
        const dots=document.querySelectorAll('#banner-carousel .banner-dot');
        let cur=0;
        function show(n){
            if(slides.length<2)return;
            slides[cur].style.display='none';if(dots[cur])dots[cur].style.background='rgba(255,255,255,.45)';
            cur=((n%slides.length)+slides.length)%slides.length;
            slides[cur].style.display='block';if(dots[cur])dots[cur].style.background='#fff';
        }
        dots.forEach(d=>d.addEventListener('click',e=>{e.preventDefault();show(parseInt(d.dataset.dot));}));
        if(slides.length>1)setInterval(()=>show(cur+1),6000);
        // Schließen: Banner sofort ausblenden und serverseitig für die
        // konfigurierte Dauer merken.
        document.querySelectorAll('#banner-carousel .banner-close').forEach(btn=>{
            btn.addEventListener('click',function(e){
                e.preventDefault();e.stopPropagation();
                const slide=btn.closest('.banner-slide');
                fetch('/portal/banner/'+btn.dataset.banner+'/schliessen',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}}).catch(()=>{});
                if(slide){slide.remove();const rest=document.querySelectorAll('#banner-carousel .banner-slide');if(rest.length===0){document.getElementById('banner-carousel').remove();}else{rest[0].style.display='block';}}
            });
        });
    })();
    </script>
</div>
@endif


<div class="page-title">{{ __('Übersicht') }}</div>
<div class="page-sub">{{ __('Willkommen zurück') }}, {{ auth()->user()->name }}.</div>

{{-- Onboarding: freiwillige E-Mail-Archivierung anbieten, solange keine
     aktive Einwilligung vorliegt. Rein optional (Art. 7 DSGVO); der Kunde
     kann jederzeit "Später" waehlen (lokal ausgeblendet) oder im Portal
     widerrufen. --}}
@unless($customer->hasActiveEmailConsent())
<div id="email-onboarding" class="card" style="border-inline-start:4px solid var(--green,#17A65B);display:none;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
        <div>
            <div class="card-title" style="margin-bottom:4px;">📨 {{ __('E-Mail-Verbindung aktivieren') }}</div>
            <div style="font-size:13.5px;color:var(--ink-soft);line-height:1.55;max-width:640px;">
                {{ __('Lassen Sie vertragsbezogene E-Mails automatisch in Ihrer Kundenakte archivieren, damit wir Sie schneller und besser unterstützen können. Freiwillig und jederzeit widerrufbar.') }}
            </div>
        </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
        <a href="{{ route('portal.email_connection') }}" class="btn" style="padding:8px 18px;font-size:13.5px;">{{ __('Jetzt aktivieren') }} →</a>
        <button type="button" onclick="d24DismissEmailOnboarding()" style="background:none;border:1px solid var(--line);color:var(--ink-soft);font-size:13.5px;padding:8px 16px;border-radius:10px;cursor:pointer;">{{ __('Später') }}</button>
    </div>
</div>
<script>
(function(){
    if(localStorage.getItem('email_onboarding_dismissed')!=='1'){
        var el=document.getElementById('email-onboarding');if(el)el.style.display='block';
    }
    window.d24DismissEmailOnboarding=function(){
        localStorage.setItem('email_onboarding_dismissed','1');
        var el=document.getElementById('email-onboarding');if(el)el.style.display='none';
    };
})();
</script>
@endunless
<div class="grid-3">
    <a href="{{ route('portal.contracts') }}" class="metric metric-link" title="Zur Vertragsübersicht">
        <div class="label">📑 {{ __('Aktive Verträge') }}</div><div class="value">{{ $contractsCount }}</div>
        <div class="metric-cta">{{ __('Verträge ansehen') }} →</div>
    </a>
    <a href="{{ route('portal.tickets') }}" class="metric metric-link" title="Zu Ihren Nachrichten">
        <div class="label">💬 {{ __('Offene Anfragen') }}</div><div class="value">{{ $openTickets }}</div>
        <div class="metric-cta">{{ __('Nachrichten öffnen') }} →</div>
    </a>
    <a href="{{ route('portal.change_requests') }}" class="metric metric-link" title="Status Ihrer Änderungsanfragen">
        <div class="label">🔄 {{ __('Änderungen in Prüfung') }}</div><div class="value">{{ $pendingApprovals }}</div>
        <div class="metric-cta">{{ __('Status ansehen') }} →</div>
    </a>
</div>

{{-- Schnellzugriff (Review Punkt 3: jede Kachel hat eine klare Funktion) --}}
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
    @foreach([
        ['route' => 'portal.documents', 'icon' => '📄', 'label' => __('Dokumente')],
        ['route' => 'portal.profile', 'icon' => '👤', 'label' => __('Meine Daten')],
        ['route' => 'portal.family', 'icon' => '👨‍👩‍👦', 'label' => __('Familie')],
        ['route' => 'portal.contacts', 'icon' => '📞', 'label' => __('Kontakte')],
    ] as $tile)
    <a href="{{ route($tile['route']) }}" class="card metric-link" style="margin-bottom:0;text-align:center;padding:18px 10px;text-decoration:none;color:var(--ink);">
        <div style="font-size:26px;margin-bottom:6px;">{{ $tile['icon'] }}</div>
        <div style="font-size:13px;font-weight:600;">{{ $tile['label'] }}</div>
    </a>
    @endforeach
</div>

{{-- Offene Dokumentenanfragen prominent anzeigen (Priorität 8) --}}
@php $openDocRequests = \App\Models\DocumentRequest::where('customer_id', $customer->id)->openForCustomer()->get(); @endphp
@if($openDocRequests->isNotEmpty())
<div class="card" style="border-left:4px solid #D9A441;">
    <div class="card-title">📄 {{ __('Wir benötigen Unterlagen von Ihnen') }}</div>
    @foreach($openDocRequests as $odr)
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;font-size:14px;">
        <div>
            {{ $odr->title }}
            @if($odr->deadline)<span style="color:{{ $odr->deadline->isPast() ? '#A32D2D' : 'var(--ink-soft)' }};font-size:12.5px;"> · {{ __('Frist') }} {{ $odr->deadline->format('d.m.Y') }}</span>@endif
        </div>
        <a href="{{ route('portal.documents') }}" class="btn btn-gold" style="padding:6px 14px;font-size:13px;flex:none;">{{ __('Hochladen') }}</a>
    </div>
    @endforeach
</div>
@endif

{{-- Kundenakte-Vollständigkeit (Final Polish Punkt 5) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">📋 {{ __('Ihre Kundenakte') }}</div>
        <span style="font-size:20px;font-weight:800;color:{{ $completeness['percent'] >= 80 ? '#17A65B' : ($completeness['percent'] >= 50 ? '#B5651D' : '#A32D2D') }};">{{ $completeness['percent'] }} %</span>
    </div>
    <div style="height:10px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin-bottom:6px;">
        <div style="height:100%;width:{{ $completeness['percent'] }}%;background:{{ $completeness['percent'] >= 80 ? '#17A65B' : ($completeness['percent'] >= 50 ? '#D9A441' : '#E24B4A') }};transition:width .3s;"></div>
    </div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">{{ $completeness['percent'] }} % {{ __('vollständig') }}</div>
    @if(count($completeness['missing']))
    <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($completeness['missing'] as $m)
        <a href="{{ route($m['route']) }}" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:13.5px;{{ !empty($m['optional']) ? 'opacity:.7;' : '' }}">
            <span>⚠ {{ $m['label'] }}</span>
            <span style="color:var(--petrol);font-size:12px;">{{ __('ergänzen') }} →</span>
        </a>
        @endforeach
    </div>
    @else
    <div style="font-size:13.5px;color:#17A65B;">✓ {{ __('Ihre Kundenakte ist vollständig.') }}</div>
    @endif
</div>
<div class="card">
    <div class="card-title">{{ __('Letzte Verträge') }}</div>
    @forelse($contracts as $c)
    <a href="{{ route('portal.contracts.show', $c->id) }}" class="item-row row-link" title="{{ __('Vertrag öffnen') }}" style="color:inherit;text-decoration:none;">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->insurer }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $c->contract_number }} · {{ ucfirst($c->type) }}</div>
        </div>
        <span style="display:flex;gap:6px;align-items:center;">
            <span class="badge badge-{{ $c->status === 'active' ? 'active' : 'pending' }}">{{ $c->status === 'active' ? 'Aktiv' : ucfirst($c->status) }}</span>
            <span style="color:var(--ink-soft);font-size:12px;">→</span>
        </span>
    </a>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Verträge vorhanden.</p>
    @endforelse
</div>
<div class="card">
    <div class="card-title">{{ __('Letzte Anfragen') }}</div>
    @forelse($tickets as $t)
    <a href="{{ route('portal.tickets.show', $t->id) }}" class="item-row row-link" title="{{ __('Anfrage öffnen') }}" style="color:inherit;text-decoration:none;">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $t->subject }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }}</div>
        </div>
        <span style="display:flex;gap:6px;align-items:center;">
            <span class="badge badge-{{ $t->status === 'open' ? 'open' : 'closed' }}">{{ $t->status === 'open' ? 'Offen' : 'In Bearbeitung' }}</span>
            <span style="color:var(--ink-soft);font-size:12px;">→</span>
        </span>
    </a>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Anfragen vorhanden.</p>
    @endforelse
</div>
@endsection
