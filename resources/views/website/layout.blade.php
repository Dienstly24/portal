<!DOCTYPE html>
@php
    use App\Support\WebsiteHosts;
    $isAr = app()->getLocale() === 'ar';
    // Pfad der aktuellen Seite auf dem kanonischen Host ('/' = Start).
    $websitePath = $websitePath ?? '/';
    $onHome = $onHome ?? false;
    $deUrl = WebsiteHosts::url($websitePath);
    $arUrl = WebsiteHosts::url(WebsiteHosts::arPath($websitePath));
    $canonicalUrl = $isAr ? $arUrl : $deUrl;
    $homeUrl = $isAr ? '/ar' : '/';
    $anchor = fn (string $a) => ($onHome ? '' : $homeUrl) . '#' . $a;
    $ogImage = \App\Models\MediaAsset::forSlot('og-image-social');
    $ogImageUrl = $ogImage?->fallbackUrl()
        ? 'https://' . WebsiteHosts::canonical() . $ogImage->fallbackUrl()
        : WebsiteHosts::url('/images/og-image.jpg');
    $phoneDisplay = config('website.phone_display');
    $phoneE164 = config('website.phone_e164');
    $mail = config('website.email');
    $addr = config('website.address');
@endphp
<html lang="{{ $isAr ? 'ar' : 'de' }}" dir="{{ $isAr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script @cspNonce>document.documentElement.className='js';</script>
<title>@yield('title', 'Dienstly24 – Versicherung, Kfz-Zulassung & Energie | Beratung DE & AR')</title>
<meta name="description" content="@yield('description', $isAr
    ? 'Dienstly24 من هامبورغ: استشارة مستقلة عن شركات التأمين حول التأمين وتسجيل السيارات والكهرباء والغاز – مجاناً وبالعربية والألمانية.'
    : 'Dienstly24 aus Hamburg: anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Strom & Gas – kostenlos, persönlich und auf Deutsch & Arabisch. Jetzt Angebot anfordern.')">
<meta name="robots" content="@yield('robots', 'index, follow')">
<meta name="author" content="Dienstly24">
<meta name="theme-color" content="#0F1512">
<link rel="canonical" href="{{ $canonicalUrl }}">
<link rel="alternate" hreflang="de" href="{{ $deUrl }}">
<link rel="alternate" hreflang="ar" href="{{ $arUrl }}">
<link rel="alternate" hreflang="x-default" href="{{ $deUrl }}">
@include('partials.favicon')

{{-- Open Graph --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="Dienstly24">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:title" content="@yield('og-title', 'Dienstly24 – Versicherung, Kfz-Zulassung & Energie')">
<meta property="og:description" content="@yield('description', $isAr
    ? 'استشارة مستقلة حول التأمين وتسجيل السيارات والطاقة – مجاناً وبالعربية والألمانية.'
    : 'Anbieterunabhängige Beratung zu Versicherungen, Kfz-Zulassung und Energie – kostenlos, persönlich und auf Deutsch & Arabisch.')">
<meta property="og:image" content="{{ $ogImageUrl }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="{{ $isAr ? 'ar_AR' : 'de_DE' }}">
<meta property="og:locale:alternate" content="{{ $isAr ? 'de_DE' : 'ar_AR' }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@yield('og-title', 'Dienstly24 – Versicherung, Kfz-Zulassung & Energie')">
<meta name="twitter:image" content="{{ $ogImageUrl }}">

{{-- Schriften: lokal gehostet (P0-4, keine Google-Server), je Sprache nur
     das benoetigte Subset; kritische Schrift vorab laden. --}}
@if($isAr)
<link rel="preload" href="/fonts/ibm-plex-sans-arabic-arabic-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/ibm-plex-sans-arabic-arabic-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/amiri-arabic-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/fonts/fonts-ar.css">
@else
<link rel="preload" href="/fonts/plus-jakarta-sans-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/playfair-display-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/fonts/fonts-de.css">
@endif
<link rel="stylesheet" href="/website-assets/site.css?v={{ @filemtime(public_path('website-assets/site.css')) ?: 1 }}">
{{-- Fuer den Website-Assistenten (POST per fetch). --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
@yield('head-extra')
</head>
<body id="top">
{{-- Skip-Link zielt auf die main-Landmark der AKTUELLEN Seite (nicht
     mehr auf /#leistungen - auf Unterseiten verliess das die Seite). --}}
<a href="#main" class="skip">{{ $isAr ? 'الانتقال إلى المحتوى' : 'Zum Inhalt springen' }}</a>

{{-- Top bar --}}
<div class="topbar"><div class="container">
  <span class="telnum"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.13.96.36 1.9.7 2.8a2 2 0 0 1-.45 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.27a2 2 0 0 1 2.1-.45c.9.34 1.84.57 2.8.7A2 2 0 0 1 22 16.9z"/></svg> {{ $phoneDisplay }}</span><span class="sep">·</span>
  <span><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg> {{ $isAr ? 'استشارة مجانية' : 'Kostenlose Beratung' }}</span><span class="sep">·</span>
  <span><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> {{ $isAr ? 'الألمانية والعربية' : 'Deutsch & Arabisch' }}</span>
</div></div>

{{-- Header --}}
<header class="site" id="hdr"><div class="nav">
  <a href="{{ $homeUrl }}" aria-label="Dienstly24"><img class="logo-img" src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24 Logo" width="113" height="40"></a>
  <nav class="nav-links" id="menu" aria-label="{{ $isAr ? 'القائمة الرئيسية' : 'Hauptmenü' }}">
    <a href="{{ $anchor('leistungen') }}">{{ $isAr ? 'الخدمات' : 'Leistungen' }}</a>
    <a href="{{ $anchor('ablauf') }}">{{ $isAr ? 'آلية العمل' : 'Ablauf' }}</a>
    <a href="{{ $anchor('ueber') }}">{{ $isAr ? 'من نحن' : 'Über uns' }}</a>
    <a href="{{ $anchor('faq') }}">{{ $isAr ? 'الأسئلة الشائعة' : 'FAQ' }}</a>
    <a href="{{ $anchor('kontakt') }}">{{ $isAr ? 'اتصل بنا' : 'Kontakt' }}</a>
  </nav>
  <div class="nav-actions">
    {{-- Sprachwahl = echte Links auf die jeweilige Sprachversion derselben
         Seite (kein JavaScript-Textaustausch mehr, P1-3). --}}
    <div class="lang" role="group" aria-label="{{ $isAr ? 'اللغة' : 'Sprache' }}">
      <a href="{{ $deUrl }}" @class(['active' => ! $isAr]) lang="de" hreflang="de">DE</a>
      <a href="{{ $arUrl }}" @class(['active' => $isAr]) lang="ar" hreflang="ar">AR</a>
    </div>
    <a href="https://portal.dienstly24.de/login" class="btn btn-ghost btn-login-text" style="padding:9px 18px;font-size:.86rem;">{{ $isAr ? 'تسجيل الدخول' : 'Login' }}</a>
    <button class="burger" id="burger" aria-label="{{ $isAr ? 'القائمة' : 'Menü' }}" aria-expanded="false"><span></span><span></span><span></span></button>
  </div>
</div></header>

<main id="main">
@yield('content')
</main>

{{-- Footer --}}
<footer class="site"><div class="container">
  <div class="fgrid">
    <div class="fbrand">
      <img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24" width="108" height="38" style="height:38px;width:auto;" loading="lazy">
      <p>{{ $isAr
          ? 'جهة الاتصال الشخصية لكم في شؤون التأمين وتسجيل السيارات والكهرباء والغاز – بالألمانية والعربية.'
          : 'Ihr persönlicher Ansprechpartner für Versicherungen, Kfz-Zulassung sowie Strom & Gas – auf Deutsch und Arabisch.' }}</p>
    </div>
    <div><h3 class="ftitle">{{ $isAr ? 'الخدمات' : 'Leistungen' }}</h3><ul>
      <li><a href="{{ ($isAr ? '/ar' : '') . '/leistungen/kfz-versicherung' }}">{{ $isAr ? 'تأمين السيارات' : 'Kfz-Versicherung' }}</a></li>
      <li><a href="{{ ($isAr ? '/ar' : '') . '/leistungen/krankenversicherung' }}">{{ $isAr ? 'التأمين الصحي' : 'Krankenversicherung' }}</a></li>
      <li><a href="{{ ($isAr ? '/ar' : '') . '/leistungen/kfz-zulassung' }}">{{ $isAr ? 'تسجيل السيارات' : 'Kfz-Zulassung' }}</a></li>
      <li><a href="{{ ($isAr ? '/ar' : '') . '/leistungen/strom-gas' }}">{{ $isAr ? 'الكهرباء والغاز' : 'Strom & Gas' }}</a></li>
    </ul></div>
    <div><h3 class="ftitle">{{ $isAr ? 'الشركة' : 'Unternehmen' }}</h3><ul>
      <li><a href="{{ $anchor('ueber') }}">{{ $isAr ? 'من نحن' : 'Über uns' }}</a></li>
      <li><a href="{{ $anchor('ablauf') }}">{{ $isAr ? 'آلية العمل' : 'Ablauf' }}</a></li>
      <li><a href="{{ $anchor('stimmen') }}">{{ $isAr ? 'آراء العملاء' : 'Kundenstimmen' }}</a></li>
      <li><a href="https://portal.dienstly24.de/login">{{ $isAr ? 'بوابة العملاء' : 'Kundenportal' }}</a></li>
    </ul></div>
    <div><h3 class="ftitle">{{ $isAr ? 'اتصل بنا' : 'Kontakt' }}</h3><ul>
      <li><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.13.96.36 1.9.7 2.8a2 2 0 0 1-.45 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.27a2 2 0 0 1 2.1-.45c.9.34 1.84.57 2.8.7A2 2 0 0 1 22 16.9z"/></svg><a href="tel:{{ $phoneE164 }}" class="telnum">{{ $phoneDisplay }}</a></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg><a href="mailto:{{ $mail }}">{{ $mail }}</a></li>
      <li><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>{{ $addr['street'] }}, {{ $addr['zip'] }} {{ $addr['city'] }}</li>
      <li><a href="{{ config('website.facebook') }}" target="_blank" rel="noopener">Facebook</a></li>
    </ul></div>
  </div>
  <p class="compliance">{{ $isAr
      ? 'تتم وساطة التأمين عبر وسيط تأمين مرخّص وفق المادة 34d الفقرة 1 من قانون مزاولة الحرف الألماني (GewO). تجدون بيانات الوسيط ورقم السجل وجهة الرقابة في صفحة بيانات الناشر (Impressum) وصفحة المعلومات الأولى (Erstinformation).'
      : 'Die Versicherungsvermittlung erfolgt über einen zugelassenen Versicherungsmakler gem. § 34d Abs. 1 GewO. Angaben zum Vermittler, Registernummer und Aufsichtsbehörde finden Sie im Impressum und in der Erstinformation.' }}</p>
  <div class="fbottom">
    <span>© {{ date('Y') }} Dienstly24</span>
    <div class="fl">
      <a href="/impressum">Impressum</a><a href="/datenschutz">{{ $isAr ? 'حماية البيانات' : 'Datenschutz' }}</a><a href="/agb">AGB</a><a href="/widerruf">{{ $isAr ? 'حق الرجوع' : 'Widerruf' }}</a><a href="/erstinformation">Erstinformation</a><a href="/cookie-richtlinie">Cookies</a><a href="/bildnachweise">{{ $isAr ? 'مصادر الصور' : 'Bildnachweise' }}</a>
    </div>
  </div>
</div></footer>

@include('website.partials.whatsapp')
@include('website.partials.assistant')
<script src="/website-assets/site.js?v={{ @filemtime(public_path('website-assets/site.js')) ?: 1 }}" defer></script>
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
