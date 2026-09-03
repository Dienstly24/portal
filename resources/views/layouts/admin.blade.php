<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — Admin</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- Chart.js lokal gehostet (DSGVO: kein Abfluss von Besucher-IPs an CDN-Drittanbieter) --}}
<script src="/js/chart.umd.min.js"></script>
<style>
:root{--petrol:#131A17;--petrol-dark:#0F1512;--gold:#17A65B;--akzent:#B8A16B;--akzent-hell:#D1C18F;--canvas:#F1EEE5;--surface:#FBFAF6;--line:#E0DCD0;--ink:#16211C;--ink-soft:#5F6B62;--sidebar-w:260px;--header-h:64px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--canvas);color:var(--ink);}
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--petrol-dark);color:#fff;display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-logo{padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.1);}
.sidebar-logo img{height:38px;width:auto;object-fit:contain;}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;color:rgba(255,255,255,.7);font-size:13.5px;text-decoration:none;transition:.15s;position:relative;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.1);color:#fff;font-weight:600;}
.nav-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--akzent-hell);border-radius:0 3px 3px 0;}
.nav-icon{width:18px;height:18px;opacity:.8;flex:none;}
.nav-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* Badges: GOLD = wartet auf uns, ROT = ein Mensch wartet auf Antwort.
   Mehr als zwei Toene waeren keine Rangfolge mehr, sondern Dekoration. */
.nav-badge{margin-left:auto;flex:none;background:var(--akzent);color:#0F1512;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;line-height:1.4;}
.nav-badge-urgent{background:#E24B4A;color:#fff;}
/* Einklappbare Nav-Gruppen (Akkordeon) */
.nav-group-header{display:flex;align-items:center;gap:8px;width:100%;background:none;border:none;cursor:pointer;padding:16px 20px 6px;color:rgba(255,255,255,.35);font-size:10.5px;text-transform:uppercase;letter-spacing:.1em;font-weight:600;font-family:inherit;text-align:left;}
.nav-group-header:hover{color:rgba(255,255,255,.6);}
.nav-group-title{flex:1;}
.nav-group-caret{width:14px;height:14px;flex:none;opacity:.7;transition:transform .18s;}
.nav-group.collapsed .nav-group-caret{transform:rotate(-90deg);}
.nav-group.collapsed .nav-group-body{display:none;}
/* Zusammengehoerige Punkte sichtbar an EINE Gruppe binden (senkrechte Linie) */
.nav-group-body{position:relative;}
.nav-group-body::before{content:'';position:absolute;left:29px;top:2px;bottom:2px;width:1px;background:rgba(255,255,255,.08);}
.nav-group-header:focus-visible,.nav-item:focus-visible{outline:2px solid var(--akzent-hell);outline-offset:-2px;}
/* Summen-Badge nur im eingeklappten Zustand zeigen, damit offene Vorgaenge sichtbar bleiben */
.nav-group-badge{margin-left:0;display:none;padding:1px 6px;font-size:10px;}
.nav-group.collapsed .nav-group-badge{display:inline-block;}
.sidebar-nav{padding-bottom:8px;}
.sidebar-foot{margin-top:auto;padding:16px 20px;border-top:1px solid rgba(255,255,255,.1);}
.user-row{display:flex;align-items:center;gap:10px;}
.avatar-sm{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#ffffff;flex:none;}
.user-name{font-size:13px;font-weight:600;color:#fff;}
.user-role{font-size:11px;color:rgba(255,255,255,.45);}
.logout-btn{background:none;border:none;color:rgba(255,255,255,.45);font-size:12px;cursor:pointer;margin-top:10px;padding:0;}
.logout-btn:hover{color:#fff;}
.header{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 32px;gap:16px;z-index:90;}
.header-search{flex:1;max-width:480px;position:relative;}
.header-search input{width:100%;padding:9px 14px 9px 38px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#EDEAE0;color:var(--ink);}
.header-search input:focus{outline:2px solid var(--gold);outline-offset:1px;background:#fff;color:var(--ink);}
.search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ink-soft);font-size:15px;}
.header-actions{margin-left:auto;display:flex;align-items:center;gap:12px;}
.icon-btn{width:38px;height:38px;border-radius:8px;border:1px solid var(--line);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--ink-soft);font-size:16px;position:relative;text-decoration:none;}
.icon-btn:hover{background:var(--canvas);color:var(--ink);}
.notif-dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:#E24B4A;border:2px solid #fff;}
.header-avatar{width:36px;height:36px;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;cursor:pointer;}
.main{margin-left:var(--sidebar-w);padding-top:var(--header-h);}
.main-inner{padding:28px 32px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--ink-soft);margin-bottom:16px;}
.breadcrumb a{color:var(--ink-soft);text-decoration:none;}
.breadcrumb a:hover{color:var(--ink);}
.breadcrumb-sep{color:var(--line);}
.page-header{margin-bottom:24px;}
.page-title{font-size:24px;font-weight:700;margin-bottom:4px;}
.page-sub{color:var(--ink-soft);font-size:14px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px 24px;margin-bottom:20px;}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.card-title{font-size:15px;font-weight:600;}
.card-link{font-size:13px;color:var(--gold);text-decoration:none;}
.card-link:hover{text-decoration:underline;}
/* Klickbare Listenzeilen: ganze Zeile fuehrt zum verknuepften Datensatz */
.row-link{cursor:pointer;transition:background .12s;}
.row-link:hover{background:var(--canvas);}
tr.row-link:hover td{background:var(--canvas);}
/* Sprungziel (z.B. #task-42) beim Oeffnen aus einer Verknuepfung markieren */
.card:target{outline:2px solid var(--gold);outline-offset:2px;}
.metrics-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.metric-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px;}
.metric-label{font-size:12.5px;color:var(--ink-soft);margin-bottom:10px;font-weight:500;}
.metric-value{font-size:30px;font-weight:700;line-height:1;}
.metric-sub{font-size:12px;color:var(--ink-soft);margin-top:6px;}
.metric-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:18px;}
.icon-green{background:#D9F4E6;color:#128a4b;}
.icon-blue{background:#E6F1FB;color:#185FA5;}
.icon-amber{background:#FEF3C7;color:#92400E;}
.icon-red{background:#F9E3E3;color:#A32D2D;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
table th{text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);font-weight:600;text-transform:uppercase;letter-spacing:.05em;}
table td{padding:13px 12px;border-bottom:1px solid var(--line);vertical-align:middle;}
table tr:last-child td{border-bottom:none;}
table tr:hover td{background:#EDEAE0;}
.badge{font-size:11.5px;padding:3px 10px;border-radius:999px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;flex:none;}
.badge-active{background:#D9F4E6;color:#128a4b;}.badge-active::before{background:#17A65B;}
.badge-pending{background:#F7E7D6;color:#B5651D;}.badge-pending::before{background:#B5651D;}
.badge-open{background:#E6F1FB;color:#185FA5;}.badge-open::before{background:#185FA5;}
.badge-closed{background:#EEF0F3;color:#5F5E5A;}.badge-closed::before{background:#5F5E5A;}
.badge-approved{background:#D9F4E6;color:#128a4b;}.badge-approved::before{background:#17A65B;}
.badge-rejected{background:#F9E3E3;color:#A32D2D;}.badge-rejected::before{background:#A32D2D;}
.badge-danger{background:#F9E3E3;color:#A32D2D;}.badge-danger::before{background:#A32D2D;}
.badge-waiting{background:#EEE9F7;color:#6B4FA3;}.badge-waiting::before{background:#6B4FA3;}
.tab-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;}
.tab-row .tab{padding:7px 14px;border-radius:999px;border:1px solid var(--line);font-size:13px;font-weight:600;color:var(--ink-soft);text-decoration:none;background:var(--surface);transition:.15s;}
.tab-row .tab:hover{border-color:var(--ink-soft);color:var(--ink);}
.tab-row .tab.active{background:var(--petrol);border-color:var(--petrol);color:#fff;}
.tab-row .tab .tab-count{font-weight:700;margin-left:4px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:8px;border:none;cursor:pointer;font-size:13.5px;font-weight:600;text-decoration:none;transition:.15s;}
.btn-primary{background:var(--petrol);color:#fff;}.btn-primary:hover{background:var(--petrol-dark);}
.btn-gold{background:var(--gold);color:#ffffff;}.btn-gold:hover{opacity:.9;}
.btn-ghost{background:transparent;border:1px solid var(--line);color:var(--ink);}.btn-ghost:hover{border-color:var(--ink-soft);}
.btn-danger{background:#F9E3E3;color:#A32D2D;border:1px solid #F0A0A0;}
.btn-sm{padding:6px 12px;font-size:12.5px;}
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.field{margin-bottom:18px;}
.field label{display:block;font-size:13px;color:var(--ink-soft);margin-bottom:6px;font-weight:500;}
.field input,.field select,.field textarea{width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#F7F5EF;color:var(--ink);font-family:inherit;transition:.15s;}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid var(--gold);outline-offset:1px;}
.field textarea{min-height:90px;resize:vertical;}
.alert{border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#D9F4E6;color:#128a4b;}
.alert-error{background:#F9E3E3;color:#A32D2D;}
.alert-warning{background:#FEF3C7;color:#92400E;}
.item-row{display:flex;align-items:center;justify-content:space-between;padding:13px 0;border-bottom:1px solid var(--line);}
.item-row:last-child{border-bottom:none;}
.customer-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:12px;}
.customer-card{border:1px solid var(--line);border-radius:10px;padding:14px;cursor:pointer;transition:.15s;text-decoration:none;color:inherit;display:block;}
.customer-card:hover{border-color:var(--gold);background:#F8F9FA;}
.customer-card .name{font-weight:600;font-size:13.5px;margin-bottom:4px;}
.customer-card .meta{font-size:12px;color:var(--ink-soft);}

.metric-card-link{display:block;text-decoration:none;color:inherit;cursor:pointer;transition:transform .12s,box-shadow .12s;}
.metric-card-link:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.1);}
/* Responsive (Final Polish Punkt 8) */
@media (max-width: 1200px) {
    .metrics-grid{grid-template-columns:repeat(2,1fr);}
    .grid-3{grid-template-columns:repeat(2,1fr);}
}
@media (max-width: 900px) {
    .sidebar{transform:translateX(-100%);transition:transform .25s;box-shadow:0 0 30px rgba(0,0,0,.35);}
    .sidebar.open{transform:translateX(0);}
    .header{left:0;padding:0 14px 0 60px;}
    .main{margin-left:0;}
    .grid-2,.grid-3,.metrics-grid{grid-template-columns:1fr;}
    .admin-mobile-btn{display:inline-flex;}
    .card{overflow-x:auto;}
    .cust-tabs{overflow-x:auto;}
}
.admin-mobile-btn{display:none;position:fixed;top:11px;left:12px;z-index:130;background:var(--petrol-dark);color:#fff;border:none;border-radius:8px;width:42px;height:42px;font-size:20px;cursor:pointer;align-items:center;justify-content:center;}
/* Treffer der globalen Suche: Hover als CSS statt als
   onmouseover-Handler (Audit SEC-4) - Darstellung gehoert ohnehin
   nicht in ein Ereignis-Attribut. */
.gs-treffer:hover{background:#F7F5EF;}
</style>
    @include('partials.favicon')
</head>
<body>
<button class="admin-mobile-btn" type="button" id="am-btn" aria-label="Menü öffnen">☰</button>
<div class="sidebar" id="admin-sidebar">
    {{-- Kompakte Marke wie bei grossen Panels (nur das D-Symbol) --}}
    <div class="sidebar-logo"><a href="{{ route('admin.dashboard') }}" title="Dienstly24"><img src="{{ \App\Support\BrandAssets::logoSymbolLight() }}" alt="Dienstly24" style="height:42px;width:auto;"></a></div>
    {{-- Navigation: Struktur in App\Support\Navigation\AdminNavigation --}}
    <x-admin.sidebar-nav />
    <div class="sidebar-foot">
        <div class="user-row">
            <div class="avatar-sm">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</div>
            <div>
                <div class="user-name">{{ auth()->user()->name }}</div>
                <div class="user-role">{{ ucfirst(auth()->user()->role) }}</div>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-btn">Abmelden →</button>
        </form>
    </div>
</div>
<div class="header">
    <a href="{{ route('admin.dashboard') }}" title="Dienstly24" style="flex:none;margin-right:6px;"><img src="{{ \App\Support\BrandAssets::logoDark() }}" alt="Dienstly24" style="height:30px;width:auto;display:block;"></a>
    <div class="header-search">
        <span class="search-icon">🔍</span>
        <input type="text" id="global-search" placeholder="Suche nach Kunden, Verträge, Tickets..."
        data-h-input="0ee4d7cadb" data-h-keydown="6044971a98" autocomplete="off">
    <div id="search-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-height:320px;overflow-y:auto;z-index:200;margin-top:4px;"></div>
    </div>
    <div class="header-actions">
        {{-- Einheitliches Notification Center: EINE Glocke, EIN Dropdown --}}
        <div style="position:relative;">
            <button type="button" class="icon-btn" id="notif-bell" title="Benachrichtigungen" data-h-click="93c2b711c3">
                🔔
                <span class="notif-dot" id="notif-dot" style="display:none;"></span>
            </button>
            <div id="notif-dropdown" style="display:none;position:absolute;top:46px;right:0;width:380px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;overflow:hidden;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--line);">
                    <span style="font-size:13px;font-weight:700;">Benachrichtigungen</span>
                    <button type="button" data-h-click="a5390eb93d" style="border:none;background:none;color:var(--ink-soft);font-size:12px;cursor:pointer;">Alle gelesen</button>
                </div>
                <div id="notif-list" style="max-height:400px;overflow-y:auto;">
                    <p style="padding:16px;font-size:13px;color:var(--ink-soft);">Laden…</p>
                </div>
            </div>
        </div>
                <div class="header-avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</div>
    </div>
</div>
<div class="main">
    <div class="main-inner">
        @if(session('success'))<div class="alert alert-success">✓ {{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-error">✗ {{ session('error') }}</div>@endif
        @if(session('warning'))<div class="alert alert-warning">⚠ {{ session('warning') }}</div>@endif
        @yield('content')
    </div>
</div>
<script @cspNonce>
let searchTimeout;
// Enter in der Kopfzeilen-Suche: zur vollstaendigen Kundenliste springen, die
// serverseitig ueber ALLE Kundenfelder sucht (Name, Nummer, Telefon, Anschrift,
// Kennzeichen, Zaehlernummer ...) und alle Treffer seitenweise anzeigt.
function globalSearchKey(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const q = e.target.value.trim();
    if (q.length < 1) return;
    window.location = '{{ route('admin.customers') }}?q=' + encodeURIComponent(q);
}
function globalSearch(q) {
    clearTimeout(searchTimeout);
    const results = document.getElementById('search-results');
    if (q.length < 2) { results.style.display = 'none'; return; }
    searchTimeout = setTimeout(() => {
        fetch('/admin/search?q=' + encodeURIComponent(q), {
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        })
        .then(r => r.json())
        .then(data => {
            if (!data.length) { results.style.display = 'none'; return; }
            results.innerHTML = data.map(item => `
                <a href="${item.url}" class="gs-treffer" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:#152826;border-bottom:1px solid #E5E1D6;">
                    <span style="font-size:18px;">${item.icon}</span>
                    <div>
                        <div style="font-weight:600;font-size:13px;">${item.title}</div>
                        <div style="font-size:11px;color:#6B7280;">${item.sub || ''}</div>
                    </div>
                </a>
            `).join('');
            results.style.display = 'block';
        });
    }, 300);
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('#global-search') && !e.target.closest('#search-results')) {
        document.getElementById('search-results').style.display = 'none';
    }
    if (!e.target.closest('#notif-bell') && !e.target.closest('#notif-dropdown')) {
        document.getElementById('notif-dropdown').style.display = 'none';
    }
});

// ===== Einheitliches Notification Center =====
const csrfToken = '{{ csrf_token() }}';
function escapeHtml(t){const d=document.createElement('div');d.textContent=t??'';return d.innerHTML;}
// Ganze Tabellenzeile klickbar machen, ohne Buttons/Links/Formulare in der
// Zeile zu stoeren (die behalten ihre eigene Aktion).
function rowNav(e, url) {
    if (e.target.closest('a,button,form,input,select,textarea,label,details,summary')) return;
    window.location = url;
}
function loadNotifications() {
    fetch('{{ route('admin.notifications') }}', {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(data => {
            document.getElementById('notif-dot').style.display = data.unread > 0 ? 'block' : 'none';
            const list = document.getElementById('notif-list');
            if (!data.items.length) {
                list.innerHTML = '<p style="padding:16px;font-size:13px;color:#6B7280;">Keine Benachrichtigungen.</p>';
                return;
            }
            list.innerHTML = data.items.map(function(n) { return ''
                + '<a href="' + n.url + '" data-h-click="notif-gelesen" data-a0="' + n.id + '" '
                + 'style="display:flex;gap:10px;padding:11px 16px;text-decoration:none;color:#152826;border-bottom:1px solid #E5E1D6;background:' + (n.read ? 'transparent' : '#F0F7F3') + ';">'
                + '<span style="font-size:18px;line-height:1.2;flex:none;">' + n.icon + '</span>'
                + '<span style="min-width:0;">'
                + '<span style="display:block;font-size:12.5px;font-weight:600;">' + escapeHtml(n.title) + '</span>'
                + '<span style="display:block;font-size:12px;color:#6B7280;margin-top:2px;">' + escapeHtml(n.preview) + '</span>'
                + '<span style="display:block;font-size:11px;color:#9CA3AF;margin-top:2px;">' + escapeHtml(n.time) + '</span>'
                + '</span></a>';
            }).join('');
        }).catch(function(){});
}
function toggleNotifications() {
    const dd = document.getElementById('notif-dropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
    if (dd.style.display === 'block') loadNotifications();
}
// Wird an per JavaScript erzeugten Links gebraucht; die ID steht als
// Datenwert am Link, nie als Code (Audit SEC-4).
window.__h = window.__h || {};
window.__h["notif-gelesen"] = function (event) { markNotifRead(this.dataset.a0); };

function markNotifRead(id) {
    fetch('/admin/notifications/' + id + '/read', {method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'}}).catch(function(){});
}
function markAllNotifsRead() {
    fetch('{{ route('admin.notifications.read_all') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'}})
        .then(function(){ loadNotifications(); }).catch(function(){});
}
loadNotifications();
// Naeher an Echtzeit: haeufiger pollen und sofort aktualisieren, sobald
// der Tab wieder aktiv wird (statt bis zu 60s zu warten).
setInterval(loadNotifications, 30000);
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') loadNotifications();
});
</script>
<script @cspNonce>
document.getElementById('am-btn')?.addEventListener('click', function(){ document.getElementById('admin-sidebar').classList.toggle('open'); });

// ===== Navigation: Aufklappen und gemerkter Zustand =====
// Gespeichert wird BEIDE Richtungen ('1' zu, '0' offen) - nur "zugeklappt"
// zu merken hiesse, dass ein bewusst geoeffneter Vertriebsbereich beim
// naechsten Aufruf wieder zufaellt.
const NAV_STATE_PREFIX = 'nav-group:';
function navReadState(key) {
    try { return localStorage.getItem(NAV_STATE_PREFIX + key); } catch (e) { return null; }
}
function navWriteState(key, collapsed) {
    try { localStorage.setItem(NAV_STATE_PREFIX + key, collapsed ? '1' : '0'); } catch (e) {}
}
function toggleNavGroup(btn) {
    const g = btn.closest('.nav-group');
    if (!g) return;
    const collapsed = g.classList.toggle('collapsed');
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    navWriteState(g.dataset.group, collapsed);
}
// Gemerkten Zustand anwenden. Die Gruppe der AKTIVEN Seite bleibt immer
// offen (data-has-active) - ein aktiver Punkt, den man nicht sieht, waere
// schlimmer als eine Gruppe zu viel.
(function () {
    document.querySelectorAll('.nav-group').forEach(function (g) {
        if (g.dataset.hasActive === '1') return;
        const stored = navReadState(g.dataset.group);
        if (stored === null) return; // noch keine Entscheidung: Vorgabe des Servers gilt
        const collapsed = stored === '1';
        g.classList.toggle('collapsed', collapsed);
        const h = g.querySelector('.nav-group-header');
        if (h) h.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
})();
</script>

{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4). Die Bloecke landen
     hier am Ende des Body, damit sie auch aus Partials heraus (etwa
     einer Tabellenzeile) gueltiges HTML ergeben - ein <script @cspNonce> mitten
     in einer <table> wuerde der Browser herausloesen. --}}
@stack('cspScripts')
</body>
</html>

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["0ee4d7cadb"] = function (event) { globalSearch(this.value) };
window.__h["6044971a98"] = function (event) { globalSearchKey(event) };
window.__h["93c2b711c3"] = function (event) { toggleNotifications() };
window.__h["a5390eb93d"] = function (event) { markAllNotifsRead() };
</script>
@endPushOnce
