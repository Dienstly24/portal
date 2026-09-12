<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — Partnerportal</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
/* Markenfarben: resources/css/brand.css (UX-1). */
/* Bausteine: resources/css/components.css (UX-2). Hier nur die bewussten
   Abweichungen des Partnerportals. */
:root{--card-bg:#fff;--page-sub-mb:24px;--card-title-mb:14px;--grid-3-mb:8px;--field-mb:16px;--badge-dot:none;}
.field input{max-width:420px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;background:var(--canvas);color:var(--ink);}
.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;height:100dvh;background:var(--graphite);color:#fff;display:flex;flex-direction:column;padding:24px 18px;z-index:100;overflow-y:auto;-webkit-overflow-scrolling:touch;}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.brand img{max-height:40px;max-width:150px;object-fit:contain;background:#fff;border-radius:6px;padding:3px;}
.brand-name{font-weight:700;font-size:15px;}
.brand-sub{font-size:11px;color:rgba(255,255,255,.5);margin-bottom:20px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;color:rgba(255,255,255,.75);font-size:14px;text-decoration:none;margin-bottom:2px;transition:.2s;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.12);color:#fff;font-weight:600;}
.sidebar-foot{margin-top:auto;padding-top:16px;border-top:1px solid rgba(255,255,255,.12);}
.user-chip{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--emerald);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
.logout{background:none;border:none;color:rgba(255,255,255,.55);font-size:13px;cursor:pointer;padding:0;}
.logout:hover{color:#fff;}
.main{margin-left:240px;padding:32px 36px;}
.stat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;}
.stat-label{font-size:12.5px;color:var(--ink-soft);margin-bottom:8px;}
.stat-value{font-size:28px;font-weight:700;line-height:1;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);text-transform:uppercase;letter-spacing:.05em;}
td{padding:12px;border-bottom:1px solid var(--line);}
tr:last-child td{border-bottom:none;}
.field input{width:100%;max-width:420px;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;}
/* Die Schaltflaeche existiert nur auf schmalen Bildschirmen - auf dem
   Rechner steht die Navigation ohnehin dauerhaft da.

   ACHTUNG, REIHENFOLGE: diese Grundregel MUSS vor der Medienabfrage
   stehen, die sie auf `inline-flex` setzt. Beide haben dieselbe
   Spezifitaet, bei Gleichstand gewinnt die spaetere Regel - stuende
   `display:none` danach, waere der Knopf auf jeder Breite unsichtbar
   und die Navigation unerreichbar. Genau dieser Fehler steckte in
   layouts/admin.blade.php. */
.p-navbtn{display:none;position:fixed;top:11px;inset-inline-start:12px;z-index:130;background:var(--graphite-deep);color:#fff;border:none;border-radius:8px;width:44px;height:44px;font-size:20px;cursor:pointer;align-items:center;justify-content:center;}
.p-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99;}

/*
   Schubladen-Navigation (Responsive-Audit 12.09.2026).

   VORHER WAR DAS PARTNERPORTAL AUF DEM TELEFON UNBENUTZBAR: die Regel
   lautete nur `.sidebar{transform:translateX(-100%)}` - die Navigation
   mit ihren sieben Punkten wurde also aus dem Bild geschoben, und es
   gab WEDER eine Schaltflaeche zum Oeffnen NOCH ueberhaupt ein <script>
   in dieser Vorlage. Ein Partner auf dem Telefon konnte damit
   ausschliesslich die Seite benutzen, auf der er gelandet war; es gab
   keinen Weg zu "Meine Kunden", "Provisionen" oder "Firmenprofil".
   Das war kein Darstellungsfehler, sondern der vollstaendige Verlust
   der Navigation.

   Geloest wird es mit demselben Muster wie im Kundenportal (Overlay,
   ESC, Schliessen bei Linkklick, Wischgeste) - nicht mit einem zweiten
   Entwurf. */
@media(max-width:900px){
    .sidebar{transform:translateX(-100%);transition:transform .25s ease;box-shadow:0 0 30px rgba(0,0,0,.35);width:min(280px,82vw);}
    .sidebar.open{transform:translateX(0);}
    .main{margin-left:0;padding:20px;padding-top:64px;}
    .grid-3{grid-template-columns:1fr;}
    .p-navbtn{display:inline-flex;}
    .p-overlay.show{display:block;}
}
@media print{.p-navbtn,.p-overlay{display:none !important;}}
</style>
    @include('partials.favicon')
</head>
<body>
<button class="p-navbtn" type="button" id="p-navbtn" aria-label="Menü öffnen" aria-controls="partner-sidebar" aria-expanded="false">☰</button>
<div class="p-overlay" id="p-overlay" hidden></div>
<div class="sidebar" id="partner-sidebar">
    <div class="brand">
        @if($partner?->logo_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($partner->logo_path) }}" alt="Logo">
        @endif
        <div>
            <div class="brand-name">{{ $partner?->name ?? 'Partner' }}</div>
        </div>
    </div>
    <div class="brand-sub">Partnerportal · Dienstly24</div>
    <a href="{{ route('partner.dashboard') }}" class="nav-item {{ request()->routeIs('partner.dashboard') ? 'active' : '' }}">Übersicht</a>
    <a href="{{ route('partner.customers') }}" class="nav-item {{ request()->routeIs('partner.customer*') ? 'active' : '' }}">Meine Kunden</a>
    <a href="{{ route('partner.commissions') }}" class="nav-item {{ request()->routeIs('partner.commissions') ? 'active' : '' }}">Provisionen</a>
    <a href="{{ route('partner.profile') }}" class="nav-item {{ request()->routeIs('partner.profile') ? 'active' : '' }}">Firmenprofil</a>
    <div class="sidebar-foot">
        <div class="user-chip">
            <div class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</div>
            <div style="font-weight:600;font-size:13px;">{{ auth()->user()->name }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="logout">Abmelden</button></form>
    </div>
</div>
<div class="main">
    @if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif
    @yield('content')
</div>

{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4). Die Bloecke landen
     hier am Ende des Body, damit sie auch aus Partials heraus (etwa
     einer Tabellenzeile) gueltiges HTML ergeben - ein <script @cspNonce> mitten
     in einer <table> wuerde der Browser herausloesen. --}}
@pushOnce('cspScripts')
<script @cspNonce>
/* Schubladen-Navigation des Partnerportals. Gleiches Verhalten wie im
   Kundenportal: Overlay, ESC, Schliessen beim Linkklick und Wischgeste.
   `inert`/`aria-expanded` halten Screenreader und Tastatur im Takt. */
(function(){
    var sb=document.getElementById('partner-sidebar');
    var ov=document.getElementById('p-overlay');
    var btn=document.getElementById('p-navbtn');
    if(!sb||!btn) return;
    function open(){ sb.classList.add('open'); ov.hidden=false; ov.classList.add('show'); document.body.style.overflow='hidden'; btn.setAttribute('aria-expanded','true'); }
    function close(){ sb.classList.remove('open'); ov.classList.remove('show'); ov.hidden=true; document.body.style.overflow=''; btn.setAttribute('aria-expanded','false'); }
    function toggle(){ sb.classList.contains('open') ? close() : open(); }
    btn.addEventListener('click', toggle);
    ov.addEventListener('click', close);
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
    /* Ein Linkklick fuehrt auf eine neue Seite - die Schublade darf
       dort nicht offen "nachhaengen". */
    sb.querySelectorAll('a').forEach(function(a){ a.addEventListener('click', close); });
    /* Wischen zum Schliessen, Richtung je nach LTR/RTL. */
    var x0=null;
    sb.addEventListener('touchstart',function(e){ x0=e.touches[0].clientX; },{passive:true});
    sb.addEventListener('touchend',function(e){
        if(x0===null) return;
        var dx=e.changedTouches[0].clientX-x0; x0=null;
        var rtl=document.documentElement.dir==='rtl';
        if((!rtl&&dx<-60)||(rtl&&dx>60)) close();
    },{passive:true});
    /* Wird das Fenster auf Rechnerbreite gezogen, steht die Navigation
       wieder fest - ein zurueckgebliebenes Overlay wuerde die Seite
       blockieren. */
    window.addEventListener('resize',function(){ if(window.innerWidth>900) close(); });
})();
</script>
@endPushOnce

@stack('cspScripts')
</body>
</html>
