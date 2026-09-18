<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('Unsere Leistungen') }} | Dienstly24</title>
{{-- Die Uebersicht hatte KEINE Beschreibung. Ohne sie baut Google sich
     eine aus dem Seitenanfang zusammen - bei einer reinen Kachelliste
     also aus Ueberschriften ohne Satzbau. Genau diese Seite ist aber der
     Einstieg in alle 22 Leistungen. --}}
<meta name="description" content="{{ $rtl
    ? 'خدمات Dienstly24 بلمحة: التأمين وتسجيل السيارات والكهرباء والغاز. استشارة مستقلة عن الشركات، مجاناً وبالعربية والألمانية، في جميع أنحاء ألمانيا.'
    : 'Alle Leistungen von Dienstly24 im Überblick: Versicherungen, Kfz-Zulassung sowie Strom & Gas. Anbieterunabhängige Beratung – kostenlos, deutschlandweit, auf Deutsch und Arabisch.' }}">
<meta name="robots" content="index, follow">
<meta name="author" content="Dienstly24">
{{-- Canonical/hreflang auf dem Website-Host (P1-3/P1-4) --}}
<link rel="canonical" href="{{ \App\Support\WebsiteHosts::url($rtl ? '/ar/leistungen' : '/leistungen') }}">
<link rel="alternate" hreflang="de" href="{{ \App\Support\WebsiteHosts::url('/leistungen') }}">
<link rel="alternate" hreflang="ar" href="{{ \App\Support\WebsiteHosts::url('/ar/leistungen') }}">
<link rel="alternate" hreflang="x-default" href="{{ \App\Support\WebsiteHosts::url('/leistungen') }}">
@php
    $ogAsset = \App\Models\MediaAsset::forSlot('og-image-social');
    $ogUrl = $ogAsset?->fallbackUrl()
        ? 'https://' . \App\Support\WebsiteHosts::canonical() . $ogAsset->fallbackUrl()
        : \App\Support\WebsiteHosts::url('/images/og-image.jpg');
@endphp
<meta property="og:type" content="website">
<meta property="og:site_name" content="Dienstly24">
<meta property="og:title" content="{{ __('Unsere Leistungen') }} – Dienstly24">
<meta property="og:url" content="{{ \App\Support\WebsiteHosts::url($rtl ? '/ar/leistungen' : '/leistungen') }}">
<meta property="og:image" content="{{ $ogUrl }}">
<meta property="og:locale" content="{{ $rtl ? 'ar_AR' : 'de_DE' }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{{ $ogUrl }}">
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::organization()) !!}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::breadcrumbList([
    [__('Startseite'), $rtl ? '/ar' : '/'],
    [__('Leistungen'), $rtl ? '/ar/leistungen' : '/leistungen'],
])) !!}
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
/* Marke: resources/css/brand.css (UX-1). Lokal bleiben nur die dunklen
   Flaechentoene dieser Leistungsseiten. */
:root{--paper:var(--graphite-black);--paper2:#15171b;--muted:#9aa1ab;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;min-height:100dvh;color:#eef1ee;display:flex;flex-direction:column;background:var(--paper);}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 12%, #1A2C24 0%, var(--graphite-deep) 48%, var(--paper) 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1000px;width:100%;margin:0 auto;padding:16px 24px 0;}
.topbar img{height:36px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--glass-line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;}
.wrap{flex:1;max-width:1000px;width:100%;margin:0 auto;padding:32px 24px 40px;}
.krumen{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:16px;font-size:13.5px;color:var(--muted);}
.krumen a{color:var(--muted);text-decoration:none;}
.krumen a:hover{color:#fff;text-decoration:underline;}
.krumen [aria-current]{color:#dfe4e0;}
.krumen .tr{opacity:.45;}
.wrap h1{font-size:clamp(26px,4vw,36px);color:#fff;margin-bottom:8px;}
.wrap .lead{color:#c9d1cc;font-size:16px;margin-bottom:26px;}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
@media(max-width:820px){.grid{grid-template-columns:1fr 1fr;}}
@media(max-width:560px){.grid{grid-template-columns:1fr;}}
.card{display:flex;flex-direction:column;background:var(--paper2);border:1px solid var(--glass-line);border-radius:16px;padding:22px 20px;text-decoration:none;color:inherit;transition:transform .2s, border-color .2s;}
.card:hover{transform:translateY(-4px);border-color:var(--emerald);}
.card .ic{font-size:30px;margin-bottom:12px;}
.card h2{font-size:17px;color:#fff;margin-bottom:6px;}
.card p{font-size:13.5px;color:var(--muted);line-height:1.5;}
.card .go{margin-top:14px;color:var(--emerald-mint);font-size:14px;font-weight:600;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:18px 16px;font-size:12.5px;color:var(--muted);border-top:1px solid var(--glass-line);}
.foot a{color:#c2c7cf;text-decoration:none;}.foot a:hover{color:#fff;}
.foot .sep{opacity:.35;}
</style>
@include('partials.favicon')
</head>
<body>
<div class="bg"></div>
<div class="topbar">
    <a href="{{ url('/') }}"><img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24"></a>
    <div class="lang-switch"><a href="{{ \App\Support\WebsiteHosts::url($rtl ? '/leistungen' : '/ar/leistungen') }}" lang="{{ $rtl ? 'de' : 'ar' }}" hreflang="{{ $rtl ? 'de' : 'ar' }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="wrap">
    <nav class="krumen" aria-label="{{ __('Brotkrumen') }}">
        <a href="{{ $rtl ? '/ar' : '/' }}">{{ __('Startseite') }}</a>
        <span class="tr" aria-hidden="true">›</span>
        <span aria-current="page">{{ __('Leistungen') }}</span>
    </nav>
    <h1>{{ __('Unsere Leistungen') }}</h1>
    <p class="lead">{{ __('Wählen Sie eine Leistung – wir beraten Sie persönlich, auf Deutsch und Arabisch.') }}</p>

    <div class="grid">
        @foreach($pages as $page)
            {{-- NICHT route('services.show'): dieser Name zeigt immer auf die
                 DEUTSCHE Adresse. Auf /ar/leistungen fuehrte jede Kachel
                 damit aus der arabischen Fassung heraus - der Besucher
                 landete auf einer deutschen Seite, und Google sah eine
                 arabische Seite, die ausschliesslich auf deutsche Seiten
                 verlinkt. --}}
            <a class="card" href="{{ ($rtl ? '/ar' : '') . '/leistungen/' . $page->slug }}">
                @if($page->icon)<div class="ic">{{ $page->icon }}</div>@endif
                <h2>{{ $page->t('title') }}</h2>
                @if($page->t('subtitle'))<p>{{ $page->t('subtitle') }}</p>@endif
                <div class="go">{{ __('Mehr erfahren') }} →</div>
            </a>
        @endforeach
    </div>
</div>

<div class="foot">
    <span>© {{ date('Y') }} Dienstly24</span><span class="sep">·</span>
    <a href="{{ url('/impressum') }}">{{ __('Impressum') }}</a><span class="sep">·</span>
    <a href="{{ url('/datenschutz') }}">{{ __('Datenschutz') }}</a><span class="sep">·</span>
    <a href="{{ url('/erstinformation') }}">{{ __('Erstinformation') }}</a><span class="sep">·</span>
    <a href="{{ url('/agb') }}">AGB</a><span class="sep">·</span>
    <a href="{{ url('/widerruf') }}">{{ __('Widerruf') }}</a><span class="sep">·</span>
    <a href="{{ route('login') }}">{{ __('Kundenportal') }}</a>
</div>
@include('website.partials.whatsapp')
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
