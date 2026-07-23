<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#131A17">
<title>Dienstly24 Portal</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--petrol:#131A17;--petrol-dark:#0F1512;--gold:#17A65B;--gold-soft:#d9f4e6;--akzent:#B8A16B;--akzent-hell:#D1C18F;--canvas:#F1EEE5;--surface:#FBFAF6;--line:#E0DCD0;--ink:#16211C;--ink-soft:#5F6B62;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--canvas);color:var(--ink);}
.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:var(--petrol);color:#fff;display:flex;flex-direction:column;padding:24px 18px;z-index:100;overflow-y:auto;}
.brand{font-size:22px;font-weight:700;padding:0 6px 24px;border-bottom:1px solid rgba(255,255,255,.12);margin-bottom:18px;}
.brand span{color:var(--akzent-hell);}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;color:rgba(255,255,255,.75);font-size:14px;text-decoration:none;margin-bottom:2px;transition:.2s;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.12);color:#fff;font-weight:600;position:relative;}
.nav-item.active::before{content:'';position:absolute;inset-inline-start:0;top:8px;bottom:8px;width:3px;border-radius:3px;background:var(--akzent-hell);}
.main{margin-left:240px;padding:32px 40px;min-height:100vh;}
.page-title{font-size:22px;font-weight:700;margin-bottom:6px;}
.page-sub{color:var(--ink-soft);font-size:14px;margin-bottom:28px;}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px;}
.metric{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:18px 20px;}
.metric .label{font-size:13px;color:var(--ink-soft);margin-bottom:8px;}
.metric .value{font-size:26px;font-weight:700;color:var(--ink);}
.card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px 24px;margin-bottom:16px;}
.card-title{font-size:15px;font-weight:600;margin-bottom:16px;}
.item-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line);}
.item-row:last-child{border-bottom:none;}
/* Klickbare Listenzeilen: ganze Zeile fuehrt zum verknuepften Datensatz */
.row-link{cursor:pointer;transition:background .12s;}
.row-link:hover{background:var(--canvas);}
.badge{font-size:12px;padding:4px 10px;border-radius:999px;font-weight:600;}
.badge-active{background:#D9F4E6;color:#128a4b;}
.badge-pending{background:#F7E7D6;color:#B5651D;}
.badge-open{background:#E6F1FB;color:#185FA5;}
.badge-closed{background:#EAECEF;color:#5F5E5A;}
.badge-waiting{background:#EEE9F7;color:#6B4FA3;}
.badge-approved{background:#D9F4E6;color:#128a4b;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:8px;border:none;cursor:pointer;font-size:14px;font-weight:600;text-decoration:none;transition:.2s;}
.btn-primary{background:var(--petrol);color:#fff;}
.btn-primary:hover{background:var(--petrol-dark);}
.btn-gold{background:var(--gold);color:#ffffff;}
.btn-ghost{background:transparent;border:1px solid var(--line);color:var(--ink);}
.sidebar-foot{margin-top:auto;padding-top:16px;border-top:1px solid rgba(255,255,255,.12);}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#ffffff;}
.user-chip{display:flex;align-items:center;gap:10px;font-size:13px;color:rgba(255,255,255,.85);}
.logout{display:block;margin-top:10px;font-size:12px;color:rgba(255,255,255,.55);cursor:pointer;text-decoration:none;}
.logout:hover{color:#fff;}
.alert-success{background:#D9F4E6;color:#128a4b;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;}
.alert-error{background:#F9E3E3;color:#A32D2D;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;}
.notice{background:#F7E7D6;color:#B5651D;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:20px;}
form .field{margin-bottom:18px;}
form label{display:block;font-size:13px;color:var(--ink-soft);margin-bottom:6px;}
form input,form select,form textarea{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#F7F5EF;color:var(--ink);font-family:inherit;}
form input:focus,form select:focus,form textarea:focus{outline:2px solid var(--gold);outline-offset:1px;background:#fff;}
form textarea{min-height:90px;resize:vertical;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}

.metric-link{display:block;text-decoration:none;color:var(--ink);cursor:pointer;transition:transform .12s, box-shadow .12s;}
.metric-link:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.08);border-color:var(--petrol);}
.metric-cta{font-size:11.5px;color:var(--petrol);margin-top:6px;font-weight:600;}

/* Benachrichtigungs-Glocke */
.bell-wrap{position:fixed;top:14px;right:18px;z-index:145;}
.bell-btn{position:relative;width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:#fff;font-size:18px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.bell-dot{display:none;position:absolute;top:6px;right:7px;width:9px;height:9px;border-radius:50%;background:#E24B4A;border:2px solid #fff;}
.bell-dd{display:none;position:absolute;top:50px;right:0;width:min(330px,calc(100vw - 16px));background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;}
[dir=rtl] .bell-wrap{right:auto;left:18px;}
[dir=rtl] .bell-dot{right:auto;left:7px;}
[dir=rtl] .bell-dd{right:auto;left:0;}

/* Gemeinsame Modal-Klassen (Seiten nutzen .d24-modal / .d24-modal-box) */
.d24-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;}
.d24-modal-box{background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;max-height:min(92vh,92dvh);overflow-y:auto;-webkit-overflow-scrolling:touch;}

/* ===== Chat (Nachrichten-Seite + schwebendes Widget) ================ */
/* Gemeinsame d24c-Bausteine kommen aus partials/chat_styles.           */

/* Chat-Seite: fuellt die Hoehe zwischen Kopf und Fussleisten */
.chatpage{display:flex;flex-direction:column;height:calc(100dvh - 64px);min-height:420px;max-width:920px;background:var(--surface);border:1px solid var(--line);border-radius:16px;overflow:hidden;}
.chatpage-head{display:flex;align-items:center;gap:11px;padding:13px 16px;background:linear-gradient(135deg,var(--petrol),var(--petrol-dark));color:#fff;}
.chatpage-name{font-weight:700;font-size:15px;}
.chatpage-status{font-size:11.5px;color:var(--akzent-hell);display:flex;align-items:center;gap:5px;}
.chatpage-status::before{content:'';width:7px;height:7px;border-radius:50%;background:#2ecc71;}

/* Schwebendes Chat-Widget (Desktop/Tablet) */
.cw-wrap{position:fixed;bottom:22px;inset-inline-end:22px;z-index:160;display:flex;flex-direction:column;align-items:flex-end;gap:12px;}
[dir=rtl] .cw-wrap{align-items:flex-start;}
.cw-fab{position:relative;width:58px;height:58px;border-radius:50%;border:none;background:linear-gradient(135deg,#19b463,#128a4b);color:#fff;font-size:25px;cursor:pointer;box-shadow:0 8px 24px rgba(18,138,75,.45);display:flex;align-items:center;justify-content:center;transition:transform .15s;}
.cw-fab:hover{transform:scale(1.06);}
.cw-badge{position:absolute;top:-2px;inset-inline-end:-2px;min-width:20px;height:20px;border-radius:999px;background:#E24B4A;color:#fff;border:2px solid var(--canvas);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 5px;}
.cw-badge[hidden]{display:none;}
.cw-panel{width:min(360px,calc(100vw - 44px));height:min(540px,calc(100dvh - 130px));background:var(--surface);border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.24);overflow:hidden;display:flex;flex-direction:column;}
.cw-panel[hidden]{display:none;}
.cw-head{display:flex;align-items:center;gap:10px;padding:11px 13px;background:linear-gradient(135deg,var(--petrol),var(--petrol-dark));color:#fff;}
.cw-head .d24c-av{width:34px;height:34px;font-size:11.5px;}
.cw-name{font-weight:700;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cw-status{font-size:10.5px;color:var(--akzent-hell);display:flex;align-items:center;gap:4px;}
.cw-status::before{content:'';width:6px;height:6px;border-radius:50%;background:#2ecc71;}
.cw-expand,.cw-close{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:none;border-radius:8px;background:transparent;color:rgba(255,255,255,.7);font-size:15px;cursor:pointer;text-decoration:none;flex:none;}
.cw-expand:hover,.cw-close:hover{background:rgba(255,255,255,.1);color:#fff;}
.cw-expand{margin-inline-start:auto;}

/* ===== Responsive Navigation (Mobile-UX-Ausbau) ===================== */
/* Topbar: nur auf Mobile sichtbar, ersetzt schwebende Buttons */
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:calc(56px + env(safe-area-inset-top));z-index:140;background:var(--petrol);color:#fff;align-items:center;gap:8px;padding:0 8px;padding-top:env(safe-area-inset-top);box-shadow:0 2px 12px rgba(0,0,0,.25);}
.topbar-btn{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border:none;background:transparent;color:#fff;font-size:21px;border-radius:10px;cursor:pointer;flex:none;}
.topbar-btn:active{background:rgba(255,255,255,.12);}
.topbar-logo{flex:1;display:flex;align-items:center;justify-content:center;}
.topbar-logo img{height:30px;width:auto;display:block;}

/* Drawer-Overlay */
.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:130;opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s;}
.sidebar-overlay.show{opacity:1;visibility:visible;}
.sidebar-close{display:none;position:absolute;top:14px;inset-inline-end:12px;width:40px;height:40px;border:none;border-radius:10px;background:rgba(255,255,255,.08);color:#fff;font-size:18px;cursor:pointer;}

/* Bottom-Tab-Bar (App-Gefuehl auf Mobile) */
.tabbar{display:none;position:fixed;left:0;right:0;bottom:0;z-index:140;background:var(--petrol);border-top:1px solid rgba(255,255,255,.10);padding-bottom:env(safe-area-inset-bottom);box-shadow:0 -4px 16px rgba(0,0,0,.22);}
.tabbar-inner{display:flex;align-items:stretch;}
.tab-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:56px;padding:7px 2px 6px;text-decoration:none;color:rgba(255,255,255,.62);border:none;background:transparent;cursor:pointer;font-family:inherit;-webkit-tap-highlight-color:transparent;}
.tab-item .tab-ico{font-size:20px;line-height:1;}
.tab-item .tab-label{font-size:10.5px;font-weight:600;letter-spacing:.01em;}
.tab-item.active{color:#fff;}
.tab-item.active .tab-ico{transform:translateY(-1px);}
.tab-item.active .tab-label{color:var(--gold);}
.tab-item:active{background:rgba(255,255,255,.07);}

/* Tablet: kompaktere Abstaende, 2-spaltige Metriken */
@media (max-width: 1024px) {
    .grid-3{grid-template-columns:repeat(2,1fr);}
    .main{padding:26px 24px;}
}
/* Mobile: Drawer + Topbar + Tabbar */
@media (max-width: 820px) {
    .topbar{display:flex;}
    .tabbar{display:block;}
    .sidebar{transform:translateX(-100%);transition:transform .28s cubic-bezier(.33,1,.5,1);box-shadow:none;width:280px;max-width:85vw;z-index:150;padding-top:calc(24px + env(safe-area-inset-top));}
    .sidebar.open{transform:translateX(0);box-shadow:0 0 40px rgba(0,0,0,.4);}
    .sidebar-close{display:inline-flex;align-items:center;justify-content:center;}
    .main{margin-left:0;padding:18px 14px;padding-top:calc(56px + 18px + env(safe-area-inset-top));padding-bottom:calc(76px + env(safe-area-inset-bottom));}
    .grid-2,.grid-3{grid-template-columns:1fr;}
    .grid-3{gap:12px;}
    .toolbar{flex-direction:column;align-items:stretch;gap:12px;}
    .toolbar .btn{justify-content:center;}
    .page-title{font-size:20px;}
    .page-sub{margin-bottom:20px;}
    .card{padding:16px;border-radius:14px;}
    .nav-item{padding:13px 12px;font-size:15px;border-radius:10px;}
    .btn{min-height:44px;}
    /* 16px verhindert Auto-Zoom von iOS beim Fokussieren */
    form input,form select,form textarea{font-size:16px;}
    .d24-modal{padding:12px;align-items:flex-end;}
    .d24-modal-box{max-width:none;border-radius:16px 16px 0 0;padding:22px 18px calc(22px + env(safe-area-inset-bottom));}
    /* Glocke wandert optisch in die Topbar */
    .bell-wrap{top:calc(6px + env(safe-area-inset-top));right:8px;z-index:145;}
    .bell-btn{width:44px;height:44px;border:none;background:transparent;box-shadow:none;font-size:20px;border-radius:10px;}
    .bell-dot{border-color:var(--petrol);top:8px;right:9px;}
    .bell-dd{position:fixed;top:calc(58px + env(safe-area-inset-top));right:8px;left:8px;width:auto;}
    [dir=rtl] .bell-wrap{right:auto;left:8px;}
    [dir=rtl] .bell-dd{left:8px;right:8px;}
    /* Chat: Vollbild zwischen Topbar und Tab-Bar; Widget uebernimmt die
       Nachrichten-Seite selbst (Tab "Nachrichten"), daher ausgeblendet */
    .chatpage{height:calc(100dvh - 168px - env(safe-area-inset-top) - env(safe-area-inset-bottom));min-height:340px;border-radius:14px;}
    .d24c-bub{max-width:85%;}
    .cw-wrap{display:none;}
}
/* RTL (Arabisch): Sidebar rechts, Inhalt spiegeln */
[dir=rtl] .sidebar{left:auto;right:0;}
[dir=rtl] .main{margin-left:0;margin-right:240px;}
@media (max-width: 820px){
    [dir=rtl] .sidebar{transform:translateX(100%);}
    [dir=rtl] .sidebar.open{transform:translateX(0);}
    [dir=rtl] .main{margin-right:0;}
}
</style>
    @include('partials.chat_styles')
    @include('partials.favicon')
</head>
<body>
{{-- Mobile Topbar: Hamburger + Logo, Glocke sitzt fix rechts daneben --}}
<header class="topbar">
    <button class="topbar-btn" type="button" id="m-btn" aria-label="Menü öffnen" aria-controls="portal-sidebar" aria-expanded="false">☰</button>
    <a class="topbar-logo" href="{{ route('portal.dashboard') }}" title="Dienstly24"><img src="/images/logo-white.png" alt="Dienstly24"></a>
    {{-- Platzhalter haelt das Logo mittig (rechts steht die fixe Glocke) --}}
    <span style="width:44px;flex:none;"></span>
</header>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<div class="sidebar" id="portal-sidebar">
    <button class="sidebar-close" type="button" id="sidebar-close" aria-label="Menü schließen">✕</button>
    {{-- Kompakte Marke wie bei grossen Panels (nur das D-Symbol) --}}
    <div class="brand"><a href="{{ route('portal.dashboard') }}" title="Dienstly24"><img src="/images/logo-icon-white.png" alt="Dienstly24" style="height:46px;width:auto;"></a></div>
    <a href="{{ route('portal.dashboard') }}" class="nav-item {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}">{{ __('Dashboard') }}</a>
    <a href="{{ route('portal.contracts') }}" class="nav-item {{ request()->routeIs('portal.contracts*') ? 'active' : '' }}">{{ __('Meine Verträge') }}</a>
    <a href="{{ route('portal.documents') }}" class="nav-item {{ request()->routeIs('portal.documents*') ? 'active' : '' }}">{{ __('Dokumente') }}</a>
    <a href="{{ route('portal.family') }}" class="nav-item {{ request()->routeIs('portal.family*') ? 'active' : '' }}">{{ __('Familie') }}</a>
    <a href="{{ route('portal.profile') }}" class="nav-item {{ request()->routeIs('portal.profile*') ? 'active' : '' }}">{{ __('Meine Daten') }}</a>
    <a href="{{ route('portal.contacts') }}" class="nav-item {{ request()->routeIs('portal.contacts*') ? 'active' : '' }}">{{ __('Kontaktinformationen') }}</a>
    <a href="{{ route('portal.change_requests') }}" class="nav-item {{ request()->routeIs('portal.change_requests*') ? 'active' : '' }}">{{ __('Änderungsanfragen') }}</a>
    @php
        // Ungelesene Beraternachrichten fuer die Badge (eine kleine Abfrage pro Seitenaufruf)
        $navCustomerId = auth()->user()->customer?->id;
        $unreadMsgs = $navCustomerId
            ? \App\Models\CustomerMessage::where('customer_id', $navCustomerId)->fromStaff()->unread()->count()
            : 0;
    @endphp
    <a href="{{ route('portal.messages') }}" class="nav-item {{ request()->routeIs('portal.messages*') ? 'active' : '' }}">{{ __('Nachrichten') }}
        @if($unreadMsgs > 0)<span style="margin-inline-start:auto;background:#E24B4A;color:#fff;font-size:11px;font-weight:700;border-radius:999px;padding:1px 8px;">{{ $unreadMsgs }}</span>@endif
    </a>
    <a href="{{ route('portal.tickets') }}" class="nav-item {{ request()->routeIs('portal.tickets*') ? 'active' : '' }}">{{ __('Anfragen') }}</a>
    <a href="{{ route('portal.datenschutz') }}" class="nav-item {{ request()->routeIs('portal.datenschutz') ? 'active' : '' }}">{{ __('Datenschutz') }}</a>
    <div class="sidebar-foot">
        <div class="user-chip">
            <div class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</div>
            <div style="font-weight:600;font-size:13px;">{{ auth()->user()->name }}</div>
        </div>
        <a href="{{ route('locale.switch', app()->getLocale() === 'ar' ? 'de' : 'ar') }}" style="display:block;margin-top:10px;font-size:12.5px;color:rgba(255,255,255,.75);text-decoration:none;">🌐 {{ app()->getLocale() === 'ar' ? 'Deutsch' : 'العربية' }}</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout">{{ __('Abmelden') }}</button>
        </form>
    </div>
</div>
<div class="main">
    {{-- Portal-Glocke (Review Punkt 8/10) --}}
    <div class="bell-wrap">
        <button type="button" id="p-bell" class="bell-btn" title="Benachrichtigungen">
            🔔<span id="p-bell-dot" class="bell-dot"></span>
        </button>
        <div id="p-bell-dd" class="bell-dd">
            <div style="padding:11px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700;">{{ __('Benachrichtigungen') }}</div>
            <div id="p-bell-list" style="max-height:340px;overflow-y:auto;"><p style="padding:14px;font-size:13px;color:var(--ink-soft);">{{ __('Laden…') }}</p></div>
        </div>
    </div>
    @if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert-error">{{ session('error') }}</div>@endif
    @if($errors->any())
    <div class="alert-error">
        <strong>Bitte prüfen Sie Ihre Eingaben:</strong>
        <ul style="margin:6px 0 0;padding-left:18px;">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif
    @yield('content')
</div>
{{-- Bottom-Tab-Bar: schneller Wechsel zwischen den Kernbereichen (nur Mobile) --}}
<nav class="tabbar" aria-label="Schnellnavigation">
    <div class="tabbar-inner">
        <a href="{{ route('portal.dashboard') }}" class="tab-item {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}"><span class="tab-ico">🏠</span><span class="tab-label">{{ __('Übersicht') }}</span></a>
        <a href="{{ route('portal.contracts') }}" class="tab-item {{ request()->routeIs('portal.contracts*') ? 'active' : '' }}"><span class="tab-ico">📑</span><span class="tab-label">{{ __('Verträge') }}</span></a>
        <a href="{{ route('portal.documents') }}" class="tab-item {{ request()->routeIs('portal.documents*') ? 'active' : '' }}"><span class="tab-ico">📄</span><span class="tab-label">{{ __('Dokumente') }}</span></a>
        <a href="{{ route('portal.messages') }}" class="tab-item {{ request()->routeIs('portal.messages*') ? 'active' : '' }}" style="position:relative;"><span class="tab-ico">💬</span><span class="tab-label">{{ __('Nachrichten') }}</span>@if($unreadMsgs > 0)<span style="position:absolute;top:6px;inset-inline-end:22%;width:9px;height:9px;border-radius:50%;background:#E24B4A;border:2px solid var(--petrol);"></span>@endif</a>
        <button type="button" class="tab-item" id="tab-more" aria-label="Menü öffnen"><span class="tab-ico">☰</span><span class="tab-label">{{ __('Mehr') }}</span></button>
    </div>
</nav>
<script>
// Drawer-Navigation: Overlay, ESC, Auto-Schliessen bei Linkklick, Swipe
(function(){
    const sb = document.getElementById('portal-sidebar');
    const ov = document.getElementById('sidebar-overlay');
    const btn = document.getElementById('m-btn');
    function openNav(){ sb.classList.add('open'); ov.classList.add('show'); document.body.style.overflow='hidden'; btn?.setAttribute('aria-expanded','true'); }
    function closeNav(){ sb.classList.remove('open'); ov.classList.remove('show'); document.body.style.overflow=''; btn?.setAttribute('aria-expanded','false'); }
    function toggleNav(){ sb.classList.contains('open') ? closeNav() : openNav(); }
    btn?.addEventListener('click', toggleNav);
    document.getElementById('tab-more')?.addEventListener('click', toggleNav);
    document.getElementById('sidebar-close')?.addEventListener('click', closeNav);
    ov?.addEventListener('click', closeNav);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeNav(); });
    // Modals (.d24-modal): Klick auf den Hintergrund oder ESC schliesst
    document.addEventListener('click', function(e){
        if(e.target.classList && e.target.classList.contains('d24-modal')) e.target.style.display = 'none';
    });
    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape') document.querySelectorAll('.d24-modal').forEach(function(m){ m.style.display = 'none'; });
    });
    sb.querySelectorAll('a.nav-item').forEach(function(a){ a.addEventListener('click', closeNav); });
    // Wischgeste zum Schliessen (Richtung je nach LTR/RTL)
    let x0 = null;
    sb.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, {passive:true});
    sb.addEventListener('touchend', function(e){
        if(x0 === null) return;
        const dx = e.changedTouches[0].clientX - x0; x0 = null;
        const rtl = document.documentElement.dir === 'rtl';
        if((!rtl && dx < -60) || (rtl && dx > 60)) closeNav();
    }, {passive:true});
})();

// Portal-Benachrichtigungen
(function() {
    const bell = document.getElementById('p-bell');
    if (!bell) return;
    const esc = t => { const d = document.createElement('div'); d.textContent = t ?? ''; return d.innerHTML; };
    function load() {
        fetch('{{ route('portal.notifications') }}', {headers: {'Accept': 'application/json'}})
            .then(r => r.json())
            .then(data => {
                document.getElementById('p-bell-dot').style.display = data.unread > 0 ? 'block' : 'none';
                const list = document.getElementById('p-bell-list');
                if (!data.items.length) { list.innerHTML = '<p style="padding:14px;font-size:13px;color:#6B7280;">Keine Benachrichtigungen.</p>'; return; }
                list.innerHTML = data.items.map(function(n) { return ''
                    + '<a href="' + n.url + '" onclick="pMarkRead(\'' + n.id + '\')" style="display:block;padding:10px 14px;text-decoration:none;color:#152826;border-bottom:1px solid #EEE;background:' + (n.read ? 'transparent' : '#F0F7F3') + ';">'
                    + '<span style="display:block;font-size:12.5px;font-weight:600;">' + esc(n.title) + '</span>'
                    + '<span style="display:block;font-size:12px;color:#6B7280;margin-top:2px;">' + esc(n.body) + '</span>'
                    + '<span style="display:block;font-size:11px;color:#9CA3AF;margin-top:2px;">' + esc(n.time) + '</span></a>';
                }).join('');
            }).catch(function(){});
    }
    window.pMarkRead = function(id) {
        fetch('/portal/notifications/' + id + '/read', {method: 'POST', headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'}}).catch(function(){});
    };
    bell.addEventListener('click', function() {
        const dd = document.getElementById('p-bell-dd');
        dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
        if (dd.style.display === 'block') load();
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#p-bell') && !e.target.closest('#p-bell-dd')) document.getElementById('p-bell-dd').style.display = 'none';
    });
    load();
    // Naeher an Echtzeit: haeufiger pollen und zusaetzlich sofort
    // aktualisieren, sobald der Tab wieder in den Vordergrund kommt.
    setInterval(load, 30000);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') load();
    });
})();
</script>
{{-- Chat: gemeinsamer JS-Kern (Seite + Widget); das schwebende Widget
     entfaellt auf der Nachrichten-Seite (dort laeuft der Chat im Vollbild) --}}
@include('partials.chat_core')
@unless(request()->routeIs('portal.messages'))
    @include('portal.partials.chat_widget')
@endunless
@include('partials.cookie_consent')
</body>
</html>
