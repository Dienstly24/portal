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
:root{--petrol:#17191d;--petrol-dark:#101216;--gold:#17A65B;--canvas:#DCDEE3;--surface:#ECEEF1;--line:#CDD1D8;--ink:#152826;--ink-soft:#6B7280;--sidebar-w:260px;--header-h:64px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--canvas);color:var(--ink);}
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--petrol-dark);color:#fff;display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-logo{padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.1);}
.sidebar-logo img{height:38px;width:auto;object-fit:contain;}
.nav-section{font-size:10.5px;color:rgba(255,255,255,.35);padding:20px 20px 6px;text-transform:uppercase;letter-spacing:.1em;font-weight:600;}
.nav-item{display:flex;align-items:center;gap:12px;padding:10px 20px;color:rgba(255,255,255,.7);font-size:13.5px;text-decoration:none;transition:.15s;position:relative;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.1);color:#fff;font-weight:600;}
.nav-item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--gold);border-radius:0 3px 3px 0;}
.nav-icon{width:18px;height:18px;opacity:.8;flex:none;}
.nav-badge{margin-left:auto;background:var(--gold);color:#ffffff;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;}
.sidebar-foot{margin-top:auto;padding:16px 20px;border-top:1px solid rgba(255,255,255,.1);}
.user-row{display:flex;align-items:center;gap:10px;}
.avatar-sm{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#ffffff;flex:none;}
.user-name{font-size:13px;font-weight:600;color:#fff;}
.user-role{font-size:11px;color:rgba(255,255,255,.45);}
.logout-btn{background:none;border:none;color:rgba(255,255,255,.45);font-size:12px;cursor:pointer;margin-top:10px;padding:0;}
.logout-btn:hover{color:#fff;}
.header{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 32px;gap:16px;z-index:90;}
.header-search{flex:1;max-width:480px;position:relative;}
.header-search input{width:100%;padding:9px 14px 9px 38px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#E3E6EA;color:var(--ink);}
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
.icon-green{background:#E4F0E7;color:#3B7A57;}
.icon-blue{background:#E6F1FB;color:#185FA5;}
.icon-amber{background:#FEF3C7;color:#92400E;}
.icon-red{background:#F9E3E3;color:#A32D2D;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
table th{text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);font-weight:600;text-transform:uppercase;letter-spacing:.05em;}
table td{padding:13px 12px;border-bottom:1px solid var(--line);vertical-align:middle;}
table tr:last-child td{border-bottom:none;}
table tr:hover td{background:#E3E6EA;}
.badge{font-size:11.5px;padding:3px 10px;border-radius:999px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;flex:none;}
.badge-active{background:#E4F0E7;color:#3B7A57;}.badge-active::before{background:#3B7A57;}
.badge-pending{background:#F7E7D6;color:#B5651D;}.badge-pending::before{background:#B5651D;}
.badge-open{background:#E6F1FB;color:#185FA5;}.badge-open::before{background:#185FA5;}
.badge-closed{background:#EEF0F3;color:#5F5E5A;}.badge-closed::before{background:#5F5E5A;}
.badge-approved{background:#E4F0E7;color:#3B7A57;}.badge-approved::before{background:#3B7A57;}
.badge-rejected{background:#F9E3E3;color:#A32D2D;}.badge-rejected::before{background:#A32D2D;}
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
.field input,.field select,.field textarea{width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#F4F5F7;color:var(--ink);font-family:inherit;transition:.15s;}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid var(--gold);outline-offset:1px;}
.field textarea{min-height:90px;resize:vertical;}
.alert{border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#E4F0E7;color:#3B7A57;}
.alert-error{background:#F9E3E3;color:#A32D2D;}
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
</style>
    @include('partials.favicon')
</head>
<body>
<button class="admin-mobile-btn" type="button" id="am-btn" aria-label="Menü öffnen">☰</button>
<div class="sidebar" id="admin-sidebar">
    {{-- Kompakte Marke wie bei grossen Panels (nur das D-Symbol) --}}
    <div class="sidebar-logo"><a href="{{ route('admin.dashboard') }}" title="Dienstly24"><img src="/images/logo-icon-white.png" alt="Dienstly24" style="height:42px;width:auto;"></a></div>
    <div class="nav-section">Beraterwelt</div>
    <a href="{{ route('admin.dashboard') }}" class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        Dashboard
    </a>
    <a href="{{ route('admin.customers') }}" class="nav-item {{ request()->routeIs('admin.customers*','admin.customer*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Kunden
    </a>
    <a href="{{ route('admin.contracts') }}" class="nav-item {{ request()->routeIs('admin.contracts*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Verträge
    </a>
    <a href="{{ route('admin.tickets') }}" class="nav-item {{ request()->routeIs('admin.tickets*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
        Tickets
        @php
            $navUser = auth()->user();
            $navIds = ($navUser && !$navUser->canSeeAllCustomers()) ? $navUser->visibleCustomerIdsWithSubstitution() : null;
            // Badge = NEUE, noch nicht uebernommene Kundentickets (Status "Offen").
            // Nach "In Bearbeitung uebernehmen" verschwindet die Zahl.
            // Gast-Anfragen zaehlen hier nicht mit - die stehen unter "Anfragen".
            $openT = \App\Models\Ticket::customerOnly()->where('status', 'open')->when($navIds !== null, fn($q) => $q->whereIn('customer_id', $navIds))->count();
        @endphp
        @if($openT > 0)<span class="nav-badge">{{ $openT }}</span>@endif
    </a>
    @if(in_array(auth()->user()->role, ['admin','manager','support']))
    <a href="{{ route('admin.inquiries') }}" class="nav-item {{ request()->routeIs('admin.inquiries*') ? 'active' : '' }}">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        Anfragen
    </a>
    @endif
    <a href="{{ route('admin.tasks') }}" class="nav-item {{ request()->routeIs('admin.tasks*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
        Aufgaben
        @php $openTasks = \App\Models\Task::where('assigned_to', auth()->id())->where('status','!=','done')->count(); @endphp
        @if($openTasks > 0)<span class="nav-badge">{{ $openTasks }}</span>@endif
    </a>
    @if(in_array(auth()->user()->role, ['admin','manager']))
    <a href="{{ route('admin.import_export') }}" class="nav-item {{ request()->routeIs('admin.import*','admin.export*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
        Import / Export
    </a>
    @endif
        <a href="{{ route('admin.email_marketing') }}" class="nav-item {{ request()->routeIs('admin.email_marketing*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        E-Mail Marketing
    </a>
        <a href="{{ route('admin.reports') }}" class="nav-item {{ request()->routeIs('admin.reports*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        Berichte
    </a>
        @if(in_array(auth()->user()->role, ['admin','manager']))
        <a href="{{ route('admin.tarifrechner') }}" class="nav-item {{ request()->routeIs('admin.tarifrechner*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        Tarifrechner
    </a>
        @endif
    @if(in_array(auth()->user()->role, ['admin','manager','support']))
    <a href="{{ route('admin.email_inbox') }}" class="nav-item {{ request()->routeIs('admin.email_inbox*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        E-Mail-Posteingang
        @php $suggestedMails = \App\Models\EmailMessage::where('match_status', 'suggested')->count(); @endphp
        @if($suggestedMails > 0)<span class="nav-badge">{{ $suggestedMails }}</span>@endif
    </a>
    @endif
    <a href="{{ route('admin.document_requests') }}" class="nav-item {{ request()->routeIs('admin.document_requests*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Dokumentenanfragen
        @php $docReqCount = \App\Models\DocumentRequest::awaitingReview()->count(); @endphp
        @if($docReqCount > 0)<span class="nav-badge">{{ $docReqCount }}</span>@endif
    </a>
    <a href="{{ route('admin.documents.inbox') }}" class="nav-item {{ request()->routeIs('admin.documents.inbox') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M4 8h16M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-6l2 2 4-4"/></svg>
        Dokumenten-Eingang
        @php
            // Konsistent mit der Listen-Ansicht: eingeschraenkte Mitarbeiter
            // sehen dort nur eigene Uploads, die Badge-Zahl muss dazu passen.
            $docInboxCount = \App\Models\Document::inbox()
                ->when(!auth()->user()->canSeeAllCustomers(), fn($q) => $q->where('uploaded_by', auth()->id()))
                ->count();
        @endphp
        @if($docInboxCount > 0)<span class="nav-badge">{{ $docInboxCount }}</span>@endif
    </a>
    <a href="{{ route('admin.change_requests') }}" class="nav-item {{ request()->routeIs('admin.change_requests*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Kundenänderungen
        @php
            $crQ = \App\Models\CustomerChangeRequest::where('status','pending');
            if (!auth()->user()->canSeeAllCustomers()) { $crQ->whereIn('customer_id', auth()->user()->visibleCustomerIdsWithSubstitution()); }
            $pendingCR = $crQ->count();
        @endphp
        @if($pendingCR > 0)<span class="nav-badge">{{ $pendingCR }}</span>@endif
    </a>

    <a href="{{ route('admin.chat.index') }}" class="nav-item {{ request()->routeIs('admin.chat*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.5-4A7.96 7.96 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        Interner Chat
        @php
            $unreadChat = \App\Models\InternalConversationParticipant::where('user_id', auth()->id())
                ->whereHas('conversation', function ($q) {
                    $q->whereColumn('internal_conversations.last_message_at', '>', 'internal_conversation_participants.last_read_at')
                      ->orWhereNull('internal_conversation_participants.last_read_at');
                })->count();
        @endphp
        @if($unreadChat > 0)<span class="nav-badge">{{ $unreadChat }}</span>@endif
    </a>
    <a href="{{ route('admin.announcements') }}" class="nav-item {{ request()->routeIs('admin.announcements*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
        Ankündigungen
        @php $activeAnn = \App\Models\Announcement::where(function($q){ $q->whereNull('expires_at')->orWhere('expires_at','>=',now()); })->count(); @endphp
        @if($activeAnn > 0)<span class="nav-badge">{{ $activeAnn }}</span>@endif
    </a>
        @if(in_array(auth()->user()->role, ['admin','manager']))
        <a href="{{ route('admin.employees') }}" class="nav-item {{ request()->routeIs('admin.employees*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        Mitarbeiter
    </a>
        @endif
        
        @if(in_array(auth()->user()->role, ['admin','manager']))

        <a href="{{ route('admin.activity_log') }}" class="nav-item {{ request()->routeIs('admin.activity_log*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        Aktivitätslog
    </a>
        <a href="{{ route('admin.activity.index') }}" class="nav-item {{ request()->routeIs('admin.activity.*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Aktivität &amp; Zeiten
    </a>

        @endif
        @if(in_array(auth()->user()->role, ['admin','manager']))
        <a href="{{ route('admin.partners') }}" class="nav-item {{ request()->routeIs('admin.partners*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3"/></svg>
        Partner
    </a>
        <a href="{{ route('admin.commissions') }}" class="nav-item {{ request()->routeIs('admin.commissions*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Provisionen
        @php $pendingCommissions = \App\Models\Commission::pendingReview()->count(); @endphp
        @if($pendingCommissions > 0)<span class="nav-badge">{{ $pendingCommissions }}</span>@endif
    </a>
        @endif
        <div class="nav-section">Integrationen</div>
    @if(in_array(auth()->user()->role, ['admin','manager']))
    <a href="{{ route('admin.lexoffice.contacts') }}" class="nav-item {{ request()->is('admin/lexoffice*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        lexoffice
    </a>
    @endif
    @if(auth()->user()->role === 'admin')
    <a href="{{ route('admin.email_accounts.index') }}" class="nav-item {{ request()->routeIs('admin.email_accounts*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        E-Mail-Postfächer
    </a>
    @endif
        <a href="{{ route('admin.appointments') }}" class="nav-item {{ request()->routeIs('admin.appointments*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        Termine
        @php $todayAppt = \App\Models\Appointment::whereDate('starts_at', today())->where('status','scheduled')->count(); @endphp
        @if($todayAppt > 0)<span class="nav-badge">{{ $todayAppt }}</span>@endif
    </a>
    @if(in_array(auth()->user()->role, ['admin','manager']))
    <a href="{{ route('admin.service_pages') }}" class="nav-item {{ request()->routeIs('admin.service_pages*') ? 'active' : '' }}">🧩 Leistungsseiten</a>
    <a href="{{ route('admin.banners') }}" class="nav-item {{ request()->routeIs('admin.banners*') ? 'active' : '' }}">📢 Banner</a>
    <a href="{{ route('admin.settings') }}" class="nav-item {{ request()->routeIs('admin.settings*') ? 'active' : '' }}">
        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Einstellungen
    </a>
    @endif
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
    <a href="{{ route('admin.dashboard') }}" title="Dienstly24" style="flex:none;margin-right:6px;"><img src="/images/logo-transparent.png" alt="Dienstly24" style="height:30px;width:auto;display:block;"></a>
    <div class="header-search">
        <span class="search-icon">🔍</span>
        <input type="text" id="global-search" placeholder="Suche nach Kunden, Verträge, Tickets..."
        oninput="globalSearch(this.value)" autocomplete="off">
    <div id="search-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-height:320px;overflow-y:auto;z-index:200;margin-top:4px;"></div>
    </div>
    <div class="header-actions">
        {{-- Einheitliches Notification Center: EINE Glocke, EIN Dropdown --}}
        <div style="position:relative;">
            <button type="button" class="icon-btn" id="notif-bell" title="Benachrichtigungen" onclick="toggleNotifications()">
                🔔
                <span class="notif-dot" id="notif-dot" style="display:none;"></span>
            </button>
            <div id="notif-dropdown" style="display:none;position:absolute;top:46px;right:0;width:380px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;overflow:hidden;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--line);">
                    <span style="font-size:13px;font-weight:700;">Benachrichtigungen</span>
                    <button type="button" onclick="markAllNotifsRead()" style="border:none;background:none;color:var(--ink-soft);font-size:12px;cursor:pointer;">Alle gelesen</button>
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
        @yield('content')
    </div>
</div>
<script>
let searchTimeout;
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
                <a href="${item.url}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:#152826;border-bottom:1px solid #E4E6EA;"
                   onmouseover="this.style.background='#F4F5F7'" onmouseout="this.style.background='transparent'">
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
    if (e.target.closest('a,button,form,input,select,textarea,label')) return;
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
                + '<a href="' + n.url + '" onclick="markNotifRead(\'' + n.id + '\')" '
                + 'style="display:flex;gap:10px;padding:11px 16px;text-decoration:none;color:#152826;border-bottom:1px solid #E4E6EA;background:' + (n.read ? 'transparent' : '#F0F7F3') + ';">'
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
function markNotifRead(id) {
    fetch('/admin/notifications/' + id + '/read', {method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'}}).catch(function(){});
}
function markAllNotifsRead() {
    fetch('{{ route('admin.notifications.read_all') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'}})
        .then(function(){ loadNotifications(); }).catch(function(){});
}
loadNotifications();
setInterval(loadNotifications, 60000);
</script>
<script>
document.getElementById('am-btn')?.addEventListener('click', function(){ document.getElementById('admin-sidebar').classList.toggle('open'); });
</script>
</body>
</html>
