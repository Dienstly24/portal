<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Unsere Leistungen') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--paper:#0B1310;--paper2:#15171b;--line:rgba(255,255,255,.14);--muted:#9aa1ab;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#eef1ee;display:flex;flex-direction:column;background:var(--paper);}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 12%, #1A2C24 0%, #0F1512 48%, var(--paper) 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1000px;width:100%;margin:0 auto;padding:16px 24px 0;}
.topbar img{height:36px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;}
.wrap{flex:1;max-width:1000px;width:100%;margin:0 auto;padding:32px 24px 40px;}
.wrap h1{font-size:clamp(26px,4vw,36px);color:#fff;margin-bottom:8px;}
.wrap .lead{color:#c9d1cc;font-size:16px;margin-bottom:26px;}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
@media(max-width:820px){.grid{grid-template-columns:1fr 1fr;}}
@media(max-width:560px){.grid{grid-template-columns:1fr;}}
.card{display:flex;flex-direction:column;background:var(--paper2);border:1px solid var(--line);border-radius:16px;padding:22px 20px;text-decoration:none;color:inherit;transition:transform .2s, border-color .2s;}
.card:hover{transform:translateY(-4px);border-color:var(--green);}
.card .ic{font-size:30px;margin-bottom:12px;}
.card h2{font-size:17px;color:#fff;margin-bottom:6px;}
.card p{font-size:13.5px;color:var(--muted);line-height:1.5;}
.card .go{margin-top:14px;color:var(--mint);font-size:14px;font-weight:600;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:18px 16px;font-size:12.5px;color:var(--muted);border-top:1px solid var(--line);}
.foot a{color:#c2c7cf;text-decoration:none;}.foot a:hover{color:#fff;}
.foot .sep{opacity:.35;}
</style>
@include('partials.favicon')
</head>
<body>
<div class="bg"></div>
<div class="topbar">
    <a href="{{ url('/') }}"><img src="/images/logo-white.png" alt="Dienstly24"></a>
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="wrap">
    <h1>{{ __('Unsere Leistungen') }}</h1>
    <p class="lead">{{ __('Wählen Sie eine Leistung – wir beraten Sie persönlich, auf Deutsch und Arabisch.') }}</p>

    <div class="grid">
        @foreach($pages as $page)
            <a class="card" href="{{ route('services.show', $page->slug) }}">
                @if($page->icon)<div class="ic">{{ $page->icon }}</div>@endif
                <h2>{{ $page->t('title') }}</h2>
                @if($page->t('subtitle'))<p>{{ $page->t('subtitle') }}</p>@endif
                <div class="go">{{ __('Mehr erfahren') }} →</div>
            </a>
        @endforeach
    </div>
</div>

<div class="foot">
    <a href="{{ url('/impressum') }}">{{ __('Impressum') }}</a><span class="sep">·</span>
    <a href="{{ url('/datenschutz') }}">{{ __('Datenschutz') }}</a><span class="sep">·</span>
    <a href="{{ route('login') }}">{{ __('Kundenportal') }}</a>
</div>
</body>
</html>
