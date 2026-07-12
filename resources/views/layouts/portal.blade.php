<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 Portal</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--petrol:#1a3c34;--petrol-dark:#142e27;--gold:#2d9c6e;--gold-soft:#d8f3dc;--canvas:#F6F4EE;--surface:#FFFFFF;--line:#E4E0D4;--ink:#152826;--ink-soft:#4A5C59;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--canvas);color:var(--ink);}
.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:var(--petrol);color:#fff;display:flex;flex-direction:column;padding:24px 18px;z-index:100;}
.brand{font-size:22px;font-weight:700;padding:0 6px 24px;border-bottom:1px solid rgba(255,255,255,.12);margin-bottom:18px;}
.brand span{color:var(--gold);}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;color:rgba(255,255,255,.75);font-size:14px;text-decoration:none;margin-bottom:2px;transition:.2s;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.12);color:#fff;font-weight:600;}
.main{margin-left:240px;padding:32px 40px;min-height:100vh;}
.page-title{font-size:22px;font-weight:700;margin-bottom:6px;}
.page-sub{color:var(--ink-soft);font-size:14px;margin-bottom:28px;}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px;}
.metric{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px 20px;}
.metric .label{font-size:13px;color:var(--ink-soft);margin-bottom:8px;}
.metric .value{font-size:26px;font-weight:700;color:var(--ink);}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px 24px;margin-bottom:16px;}
.card-title{font-size:15px;font-weight:600;margin-bottom:16px;}
.item-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line);}
.item-row:last-child{border-bottom:none;}
.badge{font-size:12px;padding:4px 10px;border-radius:999px;font-weight:600;}
.badge-active{background:#E4F0E7;color:#3B7A57;}
.badge-pending{background:#F7E7D6;color:#B5651D;}
.badge-open{background:#E6F1FB;color:#185FA5;}
.badge-closed{background:#EDEBE3;color:#5F5E5A;}
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
.alert-success{background:#E4F0E7;color:#3B7A57;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;}
.alert-error{background:#F9E3E3;color:#A32D2D;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;}
.notice{background:#F7E7D6;color:#B5651D;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:20px;}
form .field{margin-bottom:18px;}
form label{display:block;font-size:13px;color:var(--ink-soft);margin-bottom:6px;}
form input,form select,form textarea{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:var(--canvas);color:var(--ink);font-family:inherit;}
form input:focus,form select:focus,form textarea:focus{outline:2px solid var(--gold);outline-offset:1px;background:#fff;}
form textarea{min-height:90px;resize:vertical;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}

.metric-link{display:block;text-decoration:none;color:var(--ink);cursor:pointer;transition:transform .12s, box-shadow .12s;}
.metric-link:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.08);border-color:var(--petrol);}
.metric-cta{font-size:11.5px;color:var(--petrol);margin-top:6px;font-weight:600;}
/* Responsive (Final Polish Punkt 8) */
@media (max-width: 1024px) {
    .grid-3{grid-template-columns:repeat(2,1fr);}
}
@media (max-width: 768px) {
    .sidebar{transform:translateX(-100%);transition:transform .25s;box-shadow:0 0 30px rgba(0,0,0,.3);}
    .sidebar.open{transform:translateX(0);}
    .main{margin-left:0;padding:20px 16px;}
    .grid-2,.grid-3{grid-template-columns:1fr;}
    .mobile-menu-btn{display:inline-flex;}
    .toolbar{flex-direction:column;align-items:stretch;gap:12px;}
}
.mobile-menu-btn{display:none;position:fixed;top:14px;left:14px;z-index:120;background:var(--petrol);color:#fff;border:none;border-radius:8px;width:42px;height:42px;font-size:20px;cursor:pointer;}
/* RTL (Arabisch): Sidebar rechts, Inhalt spiegeln */
[dir=rtl] .sidebar{left:auto;right:0;}
[dir=rtl] .main{margin-left:0;margin-right:240px;}
[dir=rtl] .mobile-menu-btn{left:auto;right:14px;}
@media (max-width: 768px){[dir=rtl] .sidebar{transform:translateX(100%);}[dir=rtl] .sidebar.open{transform:translateX(0);}[dir=rtl] .main{margin-right:0;}}
</style>
    @include('partials.favicon')
</head>
<body>
<button class="mobile-menu-btn" type="button" id="m-btn" aria-label="Menü öffnen">☰</button>
<div class="sidebar" id="portal-sidebar">
    <div class="brand"><img src="/images/logo-white.png" alt="Dienstly24" style="height:45px;width:auto;object-fit:contain;"></div>
    <a href="{{ route('portal.dashboard') }}" class="nav-item {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}">{{ __('Dashboard') }}</a>
    <a href="{{ route('portal.contracts') }}" class="nav-item {{ request()->routeIs('portal.contracts*') ? 'active' : '' }}">{{ __('Meine Verträge') }}</a>
    <a href="{{ route('portal.documents') }}" class="nav-item {{ request()->routeIs('portal.documents*') ? 'active' : '' }}">{{ __('Dokumente') }}</a>
    <a href="{{ route('portal.family') }}" class="nav-item {{ request()->routeIs('portal.family*') ? 'active' : '' }}">{{ __('Familie') }}</a>
    <a href="{{ route('portal.profile') }}" class="nav-item {{ request()->routeIs('portal.profile*') ? 'active' : '' }}">{{ __('Meine Daten') }}</a>
    <a href="{{ route('portal.contacts') }}" class="nav-item {{ request()->routeIs('portal.contacts*') ? 'active' : '' }}">{{ __('Kontaktinformationen') }}</a>
    <a href="{{ route('portal.change_requests') }}" class="nav-item {{ request()->routeIs('portal.change_requests*') ? 'active' : '' }}">{{ __('Änderungsanfragen') }}</a>
    <a href="{{ route('portal.tickets') }}" class="nav-item {{ request()->routeIs('portal.tickets*') ? 'active' : '' }}">{{ __('Nachrichten') }}</a>
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
    <div style="position:fixed;top:14px;right:18px;z-index:150;">
        <button type="button" id="p-bell" title="Benachrichtigungen" style="position:relative;width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:#fff;font-size:18px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.08);">
            🔔<span id="p-bell-dot" style="display:none;position:absolute;top:6px;right:7px;width:9px;height:9px;border-radius:50%;background:#E24B4A;border:2px solid #fff;"></span>
        </button>
        <div id="p-bell-dd" style="display:none;position:absolute;top:50px;right:0;width:330px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;">
            <div style="padding:11px 14px;border-bottom:1px solid var(--line);font-size:13px;font-weight:700;">Benachrichtigungen</div>
            <div id="p-bell-list" style="max-height:340px;overflow-y:auto;"><p style="padding:14px;font-size:13px;color:var(--ink-soft);">Laden…</p></div>
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
<script>
document.getElementById('m-btn')?.addEventListener('click', function(){ document.getElementById('portal-sidebar').classList.toggle('open'); });

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
        dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
        if (dd.style.display === 'block') load();
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#p-bell') && !e.target.closest('#p-bell-dd')) document.getElementById('p-bell-dd').style.display = 'none';
    });
    load();
    setInterval(load, 60000);
})();
</script>
</body>
</html>
