<!DOCTYPE html>
<html lang="{{ auth()->user()->customer?->preferred_lang ?? 'de' }}">
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
</style>
</head>
<body>
<div class="sidebar">
    <div class="brand"><img src="/images/logo.png" alt="Dienstly24" style="height:45px;width:auto;object-fit:contain;"></div>
    <a href="{{ route('portal.dashboard') }}" class="nav-item {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}">Dashboard</a>
    <a href="{{ route('portal.contracts') }}" class="nav-item {{ request()->routeIs('portal.contracts*') ? 'active' : '' }}">Meine Verträge</a>
    <a href="{{ route('portal.documents') }}" class="nav-item {{ request()->routeIs('portal.documents*') ? 'active' : '' }}">Dokumente</a>
    <a href="{{ route('portal.family') }}" class="nav-item {{ request()->routeIs('portal.family*') ? 'active' : '' }}">Familie</a>
    <a href="{{ route('portal.profile') }}" class="nav-item {{ request()->routeIs('portal.profile*') ? 'active' : '' }}">Meine Daten</a>
    <a href="{{ route('portal.addresses') }}" class="nav-item {{ request()->routeIs('portal.addresses*') ? 'active' : '' }}">Adressen</a>
    <a href="{{ route('portal.contacts') }}" class="nav-item {{ request()->routeIs('portal.contacts*') ? 'active' : '' }}">Kontaktinformationen</a>
    <a href="{{ route('portal.bank') }}" class="nav-item {{ request()->routeIs('portal.bank*') ? 'active' : '' }}">Bankverbindung</a>
    <a href="{{ route('portal.change_requests') }}" class="nav-item {{ request()->routeIs('portal.change_requests*') ? 'active' : '' }}">Änderungsanfragen</a>
    <a href="{{ route('portal.tickets') }}" class="nav-item {{ request()->routeIs('portal.tickets*') ? 'active' : '' }}">Nachrichten</a>
    <div class="sidebar-foot">
        <div class="user-chip">
            <div class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</div>
            <div style="font-weight:600;font-size:13px;">{{ auth()->user()->name }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout">Abmelden</button>
        </form>
    </div>
</div>
<div class="main">
    @if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert-error">{{ session('error') }}</div>@endif
    @yield('content')
</div>
</body>
</html>
