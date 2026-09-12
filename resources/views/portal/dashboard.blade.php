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
        <button type="button" class="banner-close" data-banner="{{ $b->id }}" title="{{ __('Ausblenden') }}"
            aria-label="{{ __('Ausblenden') }}"><span>✕</span></button>
        @endif
    </div>
    @endforeach
    @if($banners->count() > 1)
    {{-- Punkte als ECHTE Schaltflaechen mit Fingermass (Responsive-Audit
         12.09.2026). Vorher waren es 9x9-px-<span>: unter dem Finger
         (WCAG 2.5.5 verlangt 44px), ohne Tastaturfokus und ohne Rolle
         fuer Screenreader. Die Trefferflaeche ist jetzt 44x44, der
         SICHTBARE Punkt bleibt 9px - die Optik aendert sich nicht. --}}
    <div class="banner-dots" role="tablist" aria-label="{{ __('Banner wählen') }}">
        @foreach($banners as $i => $b)
        <button type="button" class="banner-dot{{ $i === 0 ? ' is-active' : '' }}" data-dot="{{ $i }}"
                role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                aria-label="{{ __('Banner') }} {{ $i + 1 }}"></button>
        @endforeach
    </div>
    @endif
    @push('styles')
    <style>
    /* Bannerpunkte: 44px Trefferflaeche, 9px sichtbarer Punkt. Die
       Flaeche ist unsichtbar und ueberlappt das Bild - deshalb sitzt
       der Streifen buendig am Rand statt mit Abstand. */
    #banner-carousel .banner-dots{position:absolute;bottom:0;inset-inline-end:4px;display:flex;gap:2px;z-index:3;}
    #banner-carousel .banner-dot{width:44px;height:44px;padding:0;border:none;background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;}
    #banner-carousel .banner-dot::before{content:'';width:9px;height:9px;border-radius:50%;background:rgba(255,255,255,.45);transition:background .18s,transform .18s;}
    #banner-carousel .banner-dot.is-active::before{background:#fff;transform:scale(1.15);}
    #banner-carousel .banner-dot:focus-visible{outline:2px solid #fff;outline-offset:-8px;border-radius:50%;}
    /* Schliessen-Kreuz: sichtbarer Kreis bleibt 30px, Trefferflaeche 44px. */
    #banner-carousel .banner-close{position:absolute;top:0;inset-inline-end:0;width:44px;height:44px;border:none;background:none;color:#fff;font-size:15px;cursor:pointer;line-height:1;z-index:3;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;}
    #banner-carousel .banner-close::before{content:'';position:absolute;width:30px;height:30px;border-radius:50%;background:rgba(0,0,0,.45);}
    #banner-carousel .banner-close span{position:relative;z-index:1;}
    #banner-carousel .banner-close:focus-visible{outline:2px solid #fff;outline-offset:-10px;border-radius:50%;}
    /* Wischen darf nicht mit dem senkrechten Seitenlauf kollidieren:
       `pan-y` gibt das senkrechte Scrollen frei und laesst uns die
       waagerechte Geste auswerten. */
    #banner-carousel{touch-action:pan-y;}
    /* Im Querformat ist 70vh fast der ganze Bildschirm - der Banner
       verdeckt dann die Uebersicht darunter vollstaendig. */
    @media (max-height: 520px) and (orientation: landscape){
        #banner-carousel img, #banner-carousel video{max-height:52vh;}
    }
    @media (prefers-reduced-motion: reduce){
        #banner-carousel .banner-dot::before{transition:none;}
    }
    </style>
    @endpush

    <script @cspNonce>
    (function(){
        var wrap=document.getElementById('banner-carousel');
        if(!wrap) return;

        /* LEHRE (Responsive-Audit 12.09.2026): die Liste der Folien
           wurde frueher EINMAL mit querySelectorAll geholt und in einer
           Konstanten gehalten. Eine solche NodeList ist statisch - sie
           kennt das spaetere `slide.remove()` des Ausblendens nicht.
           Blendete ein Kunde einen Banner aus, zeigte die Liste
           weiterhin drei Eintraege, von denen einer nicht mehr im
           Dokument stand. Sobald die Selbstschaltung (alle 6 s) auf
           genau diesen Eintrag traf, setzte sie `display:block` an
           einem abgehaengten Knoten: das Karussell war LEER, dauerhaft,
           ohne Fehlermeldung. Nachgestellt und gemessen - nach dem
           Ausblenden war spaetestens beim dritten Weiterschalten, also
           nach rund 18 Sekunden, nichts mehr zu sehen.
           Deshalb wird die Liste bei JEDEM Zugriff frisch gelesen. */
        function slides(){ return Array.prototype.slice.call(wrap.querySelectorAll('.banner-slide')); }
        function dots(){ return Array.prototype.slice.call(wrap.querySelectorAll('.banner-dot')); }

        var cur=0, timer=null;

        function show(n){
            var sl=slides();
            if(!sl.length) return;
            cur=((n%sl.length)+sl.length)%sl.length;
            sl.forEach(function(s,i){ s.style.display = (i===cur ? 'block' : 'none'); });
            dots().forEach(function(d,i){
                d.classList.toggle('is-active', i===cur);
                d.setAttribute('aria-selected', i===cur ? 'true' : 'false');
            });
        }

        function start(){
            stop();
            if(slides().length>1) timer=setInterval(function(){ show(cur+1); },6000);
        }
        function stop(){ if(timer){ clearInterval(timer); timer=null; } }

        /* Nach einer Bedienung nicht sofort weiterschalten: wer gerade
           einen Banner ausgewaehlt hat, will ihn ansehen und nicht nach
           zwei Sekunden den naechsten bekommen. */
        function restart(){ stop(); start(); }

        wrap.addEventListener('click', function(e){
            var d=e.target.closest('.banner-dot');
            if(!d) return;
            e.preventDefault();
            show(parseInt(d.dataset.dot,10));
            restart();
        });

        /* Tastatur: Pfeiltasten auf den Punkten. */
        wrap.addEventListener('keydown', function(e){
            if(!e.target.closest('.banner-dot')) return;
            if(e.key==='ArrowRight'||e.key==='ArrowLeft'){
                e.preventDefault();
                var rtl=document.documentElement.dir==='rtl';
                var fwd=(e.key==='ArrowRight')!==rtl;
                show(cur+(fwd?1:-1)); restart();
                (dots()[cur]||{}).focus && dots()[cur].focus();
            }
        });

        /* WISCHGESTE - fehlte vollstaendig. Auf einem Telefon ist
           Wischen die erwartete Bedienung eines Karussells; die 9px
           grossen Punkte waren vorher der EINZIGE Weg, die Folie zu
           wechseln. Nur auswerten, wenn die Geste deutlich waagerecht
           ist, sonst wuerde jeder Scrollversuch die Folie umschalten. */
        var x0=null,y0=null;
        wrap.addEventListener('touchstart',function(e){
            if(e.touches.length!==1){ x0=null; return; }
            x0=e.touches[0].clientX; y0=e.touches[0].clientY; stop();
        },{passive:true});
        wrap.addEventListener('touchend',function(e){
            if(x0===null){ start(); return; }
            var dx=e.changedTouches[0].clientX-x0;
            var dy=e.changedTouches[0].clientY-y0;
            x0=null;
            if(Math.abs(dx)>40 && Math.abs(dx)>Math.abs(dy)*1.5){
                var rtl=document.documentElement.dir==='rtl';
                show(cur + ((dx<0)!==rtl ? 1 : -1));
            }
            start();
        },{passive:true});
        wrap.addEventListener('touchcancel',function(){ x0=null; start(); },{passive:true});

        /* Im Hintergrundtab nicht weiterlaufen (spart Arbeit auf dem
           Telefon und verhindert einen Sprung beim Zurueckkehren). */
        document.addEventListener('visibilitychange', function(){
            document.visibilityState==='visible' ? start() : stop();
        });

        /* Ausblenden: Folie entfernen, zugehoerigen Punkt entfernen und
           neu aufsetzen. Weil `slides()` jetzt frisch liest, ist der
           Zustand danach in sich stimmig. */
        wrap.addEventListener('click', function(e){
            var btn=e.target.closest('.banner-close');
            if(!btn) return;
            e.preventDefault(); e.stopPropagation();
            var slide=btn.closest('.banner-slide');
            fetch('/portal/banner/'+btn.dataset.banner+'/schliessen',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}}).catch(function(){});
            if(!slide) return;
            var i=slides().indexOf(slide);
            var d=dots()[i];
            if(d) d.remove();
            slide.remove();
            var rest=slides();
            if(!rest.length){ stop(); wrap.remove(); return; }
            /* Punkte neu durchnummerieren - sonst zeigt data-dot auf
               eine Folie, die es nicht mehr gibt. */
            dots().forEach(function(dd,k){ dd.dataset.dot=k; });
            if(rest.length<2){ var dc=wrap.querySelector('.banner-dots'); if(dc) dc.remove(); stop(); }
            show(cur>=rest.length ? 0 : cur);
            if(rest.length>1) restart();
        });

        show(0);
        start();
    })();
    </script>
</div>
@endif


<div class="page-title">{{ __('Übersicht') }}</div>
<div class="page-sub">{{ __('Willkommen zurück') }}, {{ auth()->user()->name }}.</div>

{{-- Kontakt-Hero: Chat, Anfrage und Dokument-Upload immer einen Klick
     entfernt. "Chat starten" oeffnet auf Desktop/Tablet direkt das
     schwebende Chat-Widget, auf Mobile die Nachrichten-Seite. --}}
<style>
.hero-contact{background:linear-gradient(135deg,var(--graphite),var(--graphite-deep));border-radius:14px;padding:22px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.hero-contact::after{content:'';position:absolute;top:-50px;inset-inline-end:-50px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(23,166,91,.22),transparent 70%);pointer-events:none;}
.hero-kicker{color:var(--gold-soft);font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;}
.hero-title{font-size:19px;font-weight:700;margin:6px 0 4px;}
.hero-text{color:rgba(255,255,255,.72);font-size:13px;margin-bottom:16px;}
.hero-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;position:relative;}
.hero-act{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:11px;padding:13px 12px;text-decoration:none;color:#fff;display:block;transition:.15s;}
.hero-act:hover{background:rgba(23,166,91,.18);border-color:rgba(23,166,91,.55);transform:translateY(-2px);}
.hero-act.hero-act-primary{background:linear-gradient(135deg,var(--emerald-bright),var(--emerald-deep));border-color:transparent;}
.hero-act .hero-ico{font-size:22px;line-height:1;display:block;}
.hero-act .hero-lbl{font-weight:700;font-size:13.5px;margin-top:6px;display:flex;align-items:center;gap:7px;}
.hero-act .hero-sub{font-size:11.5px;color:rgba(255,255,255,.62);margin-top:2px;display:block;line-height:1.45;}
.hero-act.hero-act-primary .hero-sub{color:rgba(255,255,255,.85);}
.hero-badge{background:#E24B4A;color:#fff;font-size:10.5px;font-weight:800;border-radius:999px;padding:1px 7px;}
@media (max-width:820px){.hero-actions{grid-template-columns:1fr;}.hero-contact{padding:18px;}}
</style>
<div class="hero-contact">
    <div class="hero-kicker">{{ __('Schneller Draht zu uns') }}</div>
    <div class="hero-title">{{ __('Wie können wir Ihnen helfen?') }}</div>
    <div class="hero-text">{{ __('Schreiben Sie uns einfach – wie in Ihrem Messenger. Wir melden uns schnellstmöglich.') }}</div>
    <div class="hero-actions">
        <a href="{{ route('portal.messages') }}" class="hero-act hero-act-primary" id="hero-chat">
            <span class="hero-ico">💬</span>
            <span class="hero-lbl">{{ __('Chat starten') }}@if(($unreadMessages ?? 0) > 0)<span class="hero-badge">{{ $unreadMessages }}</span>@endif</span>
            <span class="hero-sub">{{ __('Direkt an Ihr Team') }}</span>
        </a>
        <a href="{{ route('portal.tickets.create') }}" class="hero-act">
            <span class="hero-ico">📝</span>
            <span class="hero-lbl">{{ __('Anfrage stellen') }}</span>
            <span class="hero-sub">{{ __('Schaden, Änderung, Angebot') }}</span>
        </a>
        <a href="{{ route('portal.documents') }}" class="hero-act">
            <span class="hero-ico">📤</span>
            <span class="hero-lbl">{{ __('Dokument senden') }}</span>
            <span class="hero-sub">{{ __('Foto oder PDF – wir kümmern uns') }}</span>
        </a>
    </div>
</div>
<script @cspNonce>
document.getElementById('hero-chat').addEventListener('click', function (e) {
    if (window.d24ChatOpen && window.matchMedia('(min-width:821px)').matches) {
        e.preventDefault();
        window.d24ChatOpen();
    }
});
</script>

{{-- Onboarding: freiwillige E-Mail-Archivierung anbieten, solange keine
     aktive Einwilligung vorliegt. Rein optional (Art. 7 DSGVO); der Kunde
     kann jederzeit "Später" waehlen (lokal ausgeblendet) oder im Portal
     widerrufen. --}}
@unless($customer->hasActiveEmailConsent())
<div id="email-onboarding" class="card" style="border-inline-start:4px solid var(--emerald);display:none;">
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
        <button type="button" data-h-click="25a14582c5" style="background:none;border:1px solid var(--line);color:var(--ink-soft);font-size:13.5px;padding:8px 16px;border-radius:10px;cursor:pointer;">{{ __('Später') }}</button>
    </div>
</div>
<script @cspNonce>
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
    <a href="{{ route('portal.contracts') }}" class="metric metric-link" title="{{ __('Zur Vertragsübersicht') }}">
        <div class="label">📑 {{ __('Aktive Verträge') }}</div><div class="value">{{ $contractsCount }}</div>
        <div class="metric-cta">{{ __('Verträge ansehen') }} →</div>
    </a>
    <a href="{{ route('portal.tickets') }}" class="metric metric-link" title="{{ __('Zu Ihren Nachrichten') }}">
        <div class="label">💬 {{ __('Offene Anfragen') }}</div><div class="value">{{ $openTickets }}</div>
        <div class="metric-cta">{{ __('Nachrichten öffnen') }} →</div>
    </a>
    <a href="{{ route('portal.change_requests') }}" class="metric metric-link" title="{{ __('Status Ihrer Änderungsanfragen') }}">
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
        <a href="{{ route('portal.documents') }}" class="btn btn-emerald" style="padding:6px 14px;font-size:13px;flex:none;">{{ __('Hochladen') }}</a>
    </div>
    @endforeach
</div>
@endif

{{-- Kundenakte-Vollständigkeit (Final Polish Punkt 5) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">📋 {{ __('Ihre Kundenakte') }}</div>
        <span style="font-size:20px;font-weight:800;color:{{ $completeness['percent'] >= 80 ? 'var(--emerald)' : ($completeness['percent'] >= 50 ? '#B5651D' : '#A32D2D') }};">{{ $completeness['percent'] }} %</span>
    </div>
    <div style="height:10px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin-bottom:6px;">
        <div style="height:100%;width:{{ $completeness['percent'] }}%;background:{{ $completeness['percent'] >= 80 ? 'var(--emerald)' : ($completeness['percent'] >= 50 ? '#D9A441' : '#E24B4A') }};transition:width .3s;"></div>
    </div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">{{ $completeness['percent'] }} % {{ __('vollständig') }}</div>
    @if(count($completeness['missing']))
    <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($completeness['missing'] as $m)
        <a href="{{ route($m['route']) }}" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:13.5px;{{ !empty($m['optional']) ? 'opacity:.7;' : '' }}">
            <span>⚠ {{ __($m['label']) }}</span>
            <span style="color:var(--graphite);font-size:12px;">{{ __('ergänzen') }} →</span>
        </a>
        @endforeach
    </div>
    @else
    <div style="font-size:13.5px;color:var(--emerald);">✓ {{ __('Ihre Kundenakte ist vollständig.') }}</div>
    @endif
</div>
<div class="card">
    <div class="card-title">{{ __('Letzte Verträge') }}</div>
    @forelse($contracts as $c)
    <a href="{{ route('portal.contracts.show', $c->id) }}" class="item-row row-link" title="{{ __('Vertrag öffnen') }}" style="color:inherit;text-decoration:none;">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->insurer }}</div>
            <div class="muted-sm">{{ $c->contract_number }} · {{ __($c->typeLabel()) }}</div>
        </div>
        <span style="display:flex;gap:6px;align-items:center;">
            @php $st = $c->displayStatus(); @endphp
            <span class="badge badge-{{ $st['badge'] }} nowrap">{{ __($st['label_key'], $st['params']) }}</span>
            <span style="color:var(--ink-soft);font-size:12px;">→</span>
        </span>
    </a>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">{{ __('Noch keine Verträge vorhanden.') }}</p>
    @endforelse
</div>
<div class="card">
    <div class="card-title">{{ __('Letzte Anfragen') }}</div>
    @forelse($tickets as $t)
    <a href="{{ route('portal.tickets.show', $t->id) }}" class="item-row row-link" title="{{ __('Anfrage öffnen') }}" style="color:inherit;text-decoration:none;">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $t->subject }}</div>
            <div class="muted-sm">{{ $t->created_at->lokal()->format('d.m.Y') }}</div>
        </div>
        <span style="display:flex;gap:6px;align-items:center;">
            <span class="badge badge-{{ $t->status === 'open' ? 'open' : 'closed' }}">{{ __($t->status === 'open' ? 'Offen' : 'In Bearbeitung') }}</span>
            <span style="color:var(--ink-soft);font-size:12px;">→</span>
        </span>
    </a>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">{{ __('Noch keine Anfragen vorhanden.') }}</p>
    @endforelse
</div>
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["25a14582c5"] = function (event) { d24DismissEmailOnboarding() };
</script>
@endPushOnce
