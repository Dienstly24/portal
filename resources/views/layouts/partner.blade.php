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
.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:var(--graphite);color:#fff;display:flex;flex-direction:column;padding:24px 18px;z-index:100;}
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
@media(max-width:900px){.sidebar{transform:translateX(-100%);}.main{margin-left:0;padding:20px;}.grid-3{grid-template-columns:1fr;}}
</style>
    @include('partials.favicon')
</head>
<body>
<div class="sidebar">
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
@stack('cspScripts')
</body>
</html>
