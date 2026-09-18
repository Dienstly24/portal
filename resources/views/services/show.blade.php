<!DOCTYPE html>
@php
    $rtl = app()->getLocale() === 'ar';
    $highlights = $page->highlightList();
    $faq = $page->faqList();
    $customFields = $page->fieldList();
    $body = $page->bodyHtml();
    $hasLeft = $body !== '' || count($highlights) || count($faq);
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
{{-- Titel: LEISTUNG zuerst, Marke hinten (SEO-Auftrag Abschnitt 8).
     Google kuerzt den Titel von rechts, und in der Trefferliste entscheidet
     das erste Wort. "Dienstly24 - Kfz-Versicherung" verschenkte bei 21
     Seiten jedes Mal den Anfang an einen Markennamen, den niemand sucht,
     der Dienstly24 noch nicht kennt. --}}
<title>{{ $page->t('title') }} | Dienstly24</title>
@if($page->t('meta_description'))<meta name="description" content="{{ $page->t('meta_description') }}">@endif
<meta name="robots" content="index, follow">
<meta name="author" content="Dienstly24">
{{-- Canonical/hreflang IMMER auf dem Website-Host (www.dienstly24.de):
     DE- und AR-Version sind echte URLs (P1-3/P1-4). --}}
@php $sPath = '/leistungen/' . $page->slug; @endphp
<link rel="canonical" href="{{ \App\Support\WebsiteHosts::url($rtl ? '/ar' . $sPath : $sPath) }}">
<link rel="alternate" hreflang="de" href="{{ \App\Support\WebsiteHosts::url($sPath) }}">
<link rel="alternate" hreflang="ar" href="{{ \App\Support\WebsiteHosts::url('/ar' . $sPath) }}">
<link rel="alternate" hreflang="x-default" href="{{ \App\Support\WebsiteHosts::url($sPath) }}">
{{-- Open Graph / Twitter vollstaendig (Abschnitt 32): ohne og:image
     zeigte jedes Teilen dieser Seite bei WhatsApp, Facebook und LinkedIn
     nur einen grauen Kasten - ausgerechnet auf dem Weg, ueber den der
     Betrieb seine Kunden erreicht. Das Bild kommt aus dem Medien-Slot,
     ersatzweise aus dem mitgelieferten Bestand. --}}
@php
    $ogAsset = \App\Models\MediaAsset::forSlot('og-image-social');
    $ogUrl = $ogAsset?->fallbackUrl()
        ? 'https://' . \App\Support\WebsiteHosts::canonical() . $ogAsset->fallbackUrl()
        : \App\Support\WebsiteHosts::url('/images/og-image.jpg');
@endphp
<meta property="og:type" content="website">
<meta property="og:site_name" content="Dienstly24">
<meta property="og:title" content="{{ $page->t('title') }} – Dienstly24">
@if($page->t('meta_description'))<meta property="og:description" content="{{ $page->t('meta_description') }}">@endif
<meta property="og:url" content="{{ \App\Support\WebsiteHosts::url($rtl ? '/ar' . $sPath : $sPath) }}">
<meta property="og:image" content="{{ $ogUrl }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="{{ $rtl ? 'ar_AR' : 'de_DE' }}">
<meta property="og:locale:alternate" content="{{ $rtl ? 'de_DE' : 'ar_AR' }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $page->t('title') }} – Dienstly24">
@if($page->t('meta_description'))<meta name="twitter:description" content="{{ $page->t('meta_description') }}">@endif
<meta name="twitter:image" content="{{ $ogUrl }}">
{{-- Strukturierte Daten: Aufbau in App\Services\Seo\StructuredData.
     NICHT als Array hierher zurueckholen - Blade wuerde den Schluessel
     "at-context" als eigene Direktive kompilieren und PHP-Quelltext ins
     HTML schreiben (Audit 15.09.2026). --}}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::service($page)) !!}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::faqPage(
    collect($faq)->map(fn ($f) => [$f['q'] ?? null, $f['a'] ?? null])->all()
)) !!}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::organization()) !!}
{!! \App\Services\Seo\StructuredData::script(\App\Services\Seo\StructuredData::breadcrumbList([
    [__('Startseite'), $rtl ? '/ar' : '/'],
    [__('Leistungen'), $rtl ? '/ar/leistungen' : '/leistungen'],
    [$page->t('title'), $rtl ? '/ar' . $sPath : $sPath],
])) !!}
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
/* Marke: resources/css/brand.css (UX-1). Lokal bleiben nur die dunklen
   Flaechentoene dieser Leistungsseite - sie weicht bewusst ab. */
:root{--paper:var(--graphite-black);--card:#15171b;--card2:#1b1e23;--glass-line:rgba(255,255,255,.10);--line2:rgba(255,255,255,.16);--muted:#9aa1ab;--text:#eef1ee;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',system-ui,Arial,sans-serif;min-height:100vh;min-height:100dvh;color:var(--text);display:flex;flex-direction:column;background:var(--paper);line-height:1.6;}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1100px 720px at 78% -8%, #23272e 0%, #14161a 46%, var(--paper) 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.045) 1px,transparent 1px);background-size:28px 28px;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1080px;width:100%;margin:0 auto;padding:18px 24px 0;}
.topbar img{height:34px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.06);border:1px solid var(--glass-line);color:#dde0e5;text-decoration:none;font-size:13px;padding:8px 14px;border-radius:10px;transition:background .2s;}
.lang-switch a:hover{background:rgba(255,255,255,.12);}
.page{flex:1;max-width:1080px;width:100%;margin:0 auto;padding:26px 24px 48px;}
.back{display:inline-flex;align-items:center;gap:6px;margin-bottom:22px;color:var(--muted);text-decoration:none;font-size:13.5px;}
.back:hover{color:#fff;}
/* Brotkrumen (Abschnitt 22): sichtbar UND als BreadcrumbList ausgezeichnet -
   Google zeigt in der Trefferliste den Pfad statt der nackten URL. */
.krumen{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:20px;font-size:13.5px;color:var(--muted);}
.krumen a{color:var(--muted);text-decoration:none;}
.krumen a:hover{color:#fff;text-decoration:underline;}
.krumen [aria-current]{color:#dfe4e0;}
.krumen .tr{opacity:.45;}
/* Direkter Kontakt: Telefon und WhatsApp als ECHTE Knoepfe. Bisher war das
   Formular der einzige Weg - auf dem Telefon ist ein Anruf aber der
   kuerzeste Weg zu einer Anfrage (Abschnitt 24/25). */
.kontaktbox{margin:0 0 30px;padding:18px 20px;border:1px solid var(--glass-line);border-radius:16px;background:rgba(23,166,91,.07);}
.kontaktbox p{font-size:14px;color:#c7cec9;margin-bottom:12px;}
.kontaktwege{display:flex;flex-wrap:wrap;gap:10px;}
.kontaktwege a{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:12px;font-size:14.5px;font-weight:600;text-decoration:none;min-height:44px;}
.kontaktwege .k-tel{background:var(--emerald);color:#fff;}
.kontaktwege .k-wa{background:rgba(255,255,255,.08);border:1px solid var(--glass-line);color:#eef1ee;}
.kontaktwege .k-form{background:transparent;border:1px solid var(--glass-line);color:#c7cec9;}
.kontaktwege a:hover{filter:brightness(1.08);}
/* Verwandte Leistungen: der thematische Ausgang aus der Seite. */
.weiter{margin-top:34px;}
.weiter h2{font-size:18px;color:#fff;margin-bottom:14px;}
.weiter .liste{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
@media(max-width:820px){.weiter .liste{grid-template-columns:1fr 1fr;}}
@media(max-width:520px){.weiter .liste{grid-template-columns:1fr;}}
.weiter a{display:block;padding:15px 16px;border:1px solid var(--glass-line);border-radius:14px;background:var(--card);color:#eef1ee;text-decoration:none;font-size:14.5px;font-weight:600;transition:border-color .2s,transform .2s;}
.weiter a:hover{border-color:var(--emerald);transform:translateY(-3px);}
.weiter a span{display:block;margin-top:5px;font-size:12.5px;font-weight:400;color:var(--muted);}
/* Hero */
.hero{display:flex;gap:20px;align-items:center;margin-bottom:14px;flex-wrap:wrap;}
/* Im Admin hochgeladenes Seitenbild (weisse Kachel vertraegt auch Bilder mit weissem Hintergrund) */
.hero-bild{width:clamp(110px,22vw,210px);border-radius:18px;border:1px solid rgba(184,161,107,.45);background:#fff;padding:8px;margin-inline-start:auto;box-shadow:0 18px 40px rgba(0,0,0,.35);}
.hero .badge{flex-shrink:0;width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:32px;background:linear-gradient(155deg,rgba(23,166,91,.22),rgba(23,166,91,.06));border:1px solid rgba(23,166,91,.35);}
.hero h1{font-size:clamp(25px,3.6vw,36px);color:#fff;line-height:1.14;letter-spacing:-.01em;margin-bottom:8px;}
.hero .sub{color:var(--emerald-mint);font-size:15.5px;font-weight:600;}
.lead{color:#c7cec9;font-size:16px;line-height:1.75;margin:20px 0 30px;max-width:760px;}
/* Layout */
.cols{display:grid;gap:26px;align-items:start;}
.cols.two{grid-template-columns:1.05fr .95fr;}
.cols.one{grid-template-columns:minmax(0,560px);justify-content:center;}
@media(max-width:840px){.cols.two{grid-template-columns:1fr;}}
.card{background:var(--card);border:1px solid var(--glass-line);border-radius:18px;padding:24px;}
.card + .card{margin-top:18px;}
.card h2{font-size:17px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:9px;}
.hl{list-style:none;display:flex;flex-direction:column;gap:13px;}
.hl li{display:flex;gap:11px;align-items:flex-start;font-size:15px;color:#d7ddd8;}
.hl li svg{width:19px;height:19px;flex-shrink:0;margin-top:1px;color:var(--emerald-mint);}
.faq details{border-bottom:1px solid var(--glass-line);padding:13px 0;}
.faq details:last-child{border-bottom:0;}
.faq summary{cursor:pointer;font-weight:600;color:var(--text);font-size:14.5px;list-style:none;display:flex;justify-content:space-between;gap:10px;}
.faq summary::-webkit-details-marker{display:none;}
.faq summary::after{content:'+';color:var(--emerald-mint);font-weight:700;}
.faq details[open] summary::after{content:'–';}
.faq details p{color:#bcc3bd;font-size:14px;margin-top:9px;line-height:1.65;}
.prose h3{font-size:16px;color:#fff;margin:22px 0 8px;}
.prose h3:first-child{margin-top:0;}
.prose p{color:#c7cec9;font-size:14.5px;line-height:1.75;margin-bottom:12px;}
.prose ul{margin:0 0 14px;padding-inline-start:20px;}
.prose li{color:#c7cec9;font-size:14.5px;line-height:1.7;margin-bottom:6px;}
.providers{margin-top:36px;}
.providers-label{text-align:center;font-size:12px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--muted);margin-bottom:16px;}
.marquee{overflow:hidden;position:relative;-webkit-mask-image:linear-gradient(90deg,transparent,#000 7%,#000 93%,transparent);mask-image:linear-gradient(90deg,transparent,#000 7%,#000 93%,transparent);}
.marquee-track{display:flex;width:max-content;gap:12px;animation:pvscroll 34s linear infinite;}
.marquee:hover .marquee-track{animation-play-state:paused;}
.pv{flex:none;background:var(--card2);border:1px solid var(--glass-line);border-radius:10px;padding:11px 18px;font-size:14px;font-weight:600;color:#dfe4df;white-space:nowrap;}
@keyframes pvscroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
[dir=rtl] .marquee-track{animation-direction:reverse;}
@media(prefers-reduced-motion:reduce){.marquee-track{animation:none;flex-wrap:wrap;justify-content:center;}}
/* Form */
.form-card{position:sticky;top:22px;}
.form-card h2{font-size:19px;}
label{display:block;font-size:13px;margin-bottom:7px;color:#cfd5cf;font-weight:500;}
.field{margin-bottom:15px;}
.field input,.field select,.field textarea{width:100%;background:rgba(0,0,0,.28);border:1px solid var(--line2);border-radius:11px;color:#fff;font-size:14.5px;padding:12px 14px;outline:none;font-family:inherit;transition:border-color .18s, box-shadow .18s;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--emerald);box-shadow:0 0 0 3px rgba(23,166,91,.16);}
.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%239aa1ab' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;}
[dir=rtl] .field select{background-position:left 14px center;padding-right:14px;padding-left:36px;}
.field select option{background:#14161a;color:#fff;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:480px){.grid2{grid-template-columns:1fr;}}
.consent{display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:#b9c0ba;line-height:1.5;margin:4px 0 6px;}
.consent input[type=checkbox]{width:18px;height:18px;flex:0 0 18px;margin-top:1px;accent-color:var(--emerald);cursor:pointer;}
.consent a{color:var(--emerald-mint);}
.btn{width:100%;background:linear-gradient(180deg,var(--emerald-bright),var(--emerald-deep));border:1px solid #1fc06e;color:#fff;font-size:15.5px;font-weight:700;padding:14px;border-radius:12px;cursor:pointer;margin-top:10px;transition:filter .2s, transform .12s, box-shadow .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 12px 28px rgba(23,166,91,.32);}
.hint{font-size:11.5px;color:var(--muted);text-align:center;margin-top:12px;}
.error{background:rgba(226,75,74,.14);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:11px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;}
.ok{background:rgba(23,166,91,.14);border:1px solid rgba(23,166,91,.45);color:#c6ecd8;border-radius:12px;padding:18px 18px;font-size:14.5px;line-height:1.6;}
.hp{position:absolute;left:-6000px;top:-6000px;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:20px 16px;font-size:12.5px;color:var(--muted);border-top:1px solid var(--glass-line);}
.foot a{color:#c2c7cf;text-decoration:none;}.foot a:hover{color:#fff;}
.foot .sep{opacity:.35;}
</style>
@include('partials.favicon')
</head>
<body>
<div class="bg"></div>

<div class="topbar">
    <a href="{{ url('/') }}"><img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24"></a>
    {{-- Sprachwahl = ECHTER Link auf die andere Sprachversion DIESER Seite,
         also genau die Adresse, die auch im hreflang steht. Vorher fuehrte
         der Knopf auf den Sitzungs-Umschalter /sprache/ar: die URL blieb
         dieselbe, der Inhalt wechselte. Fuer Google sind das zwei Inhalte
         unter einer Adresse - die arabische Fassung war ueber die
         Oberflaeche gar nicht als eigene Seite erreichbar. --}}
    <div class="lang-switch"><a href="{{ \App\Support\WebsiteHosts::url($rtl ? $sPath : '/ar' . $sPath) }}" lang="{{ $rtl ? 'de' : 'ar' }}" hreflang="{{ $rtl ? 'de' : 'ar' }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="page">
    <nav class="krumen" aria-label="{{ __('Brotkrumen') }}">
        <a href="{{ $rtl ? '/ar' : '/' }}">{{ __('Startseite') }}</a>
        <span class="tr" aria-hidden="true">›</span>
        <a href="{{ $rtl ? '/ar/leistungen' : '/leistungen' }}">{{ __('Leistungen') }}</a>
        <span class="tr" aria-hidden="true">›</span>
        <span aria-current="page">{{ $page->t('title') }}</span>
    </nav>

    <div class="hero">
        @if($page->image_path)
            {{-- Im Admin hochgeladenes Seitenbild ersetzt die Emoji-Kachel --}}
        @elseif($page->icon)<div class="badge">{{ $page->icon }}</div>@endif
        <div>
            <h1>{{ $page->t('title') }}</h1>
            @if($page->t('subtitle'))<div class="sub">{{ $page->t('subtitle') }}</div>@endif
        </div>
        @if($page->image_path)
            {{-- Relative URL (P0-6): nie APP_URL/IP-abhaengig --}}
            <img class="hero-bild" src="{{ $page->imageUrl() }}" alt="{{ $page->t('title') }}">
        @endif
    </div>
    @if($page->t('intro'))<p class="lead">{{ $page->t('intro') }}</p>@endif

    {{-- Direkte Kontaktwege VOR dem Formular (Abschnitt 24/25): Telefon und
         WhatsApp sind auf dem Telefon ein Fingertipp, ein Formular sind
         acht Felder. Der Hinweis auf die deutschlandweite Beratung steht
         genau hier, weil ein Besucher aus Koeln sonst aus der Hamburger
         Anschrift im Fuss schliesst, er sei nicht gemeint. --}}
    @php
        $telE164 = config('website.phone_e164');
        $telAnzeige = config('website.phone_display');
        $waLeistung = $rtl
            ? 'مرحباً Dienstly24، أريد استشارة بخصوص: ' . $page->t('title')
            : 'Hallo Dienstly24, ich interessiere mich für: ' . $page->title_de;
        $waLink = 'https://wa.me/' . config('website.whatsapp') . '?text=' . rawurlencode($waLeistung);
    @endphp
    <div class="kontaktbox">
        <p>{{ $rtl
            ? 'استشارة مجانية وغير ملزمة – بالعربية والألمانية، في جميع أنحاء ألمانيا عبر الهاتف أو الإنترنت.'
            : 'Kostenlose, unverbindliche Beratung – auf Deutsch und Arabisch, deutschlandweit telefonisch und online.' }}</p>
        <div class="kontaktwege">
            <a class="k-tel" href="tel:{{ $telE164 }}" data-cta="telefon" data-cta-seite="{{ $page->slug }}">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.13.96.36 1.9.7 2.8a2 2 0 0 1-.45 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.27a2 2 0 0 1 2.1-.45c.9.34 1.84.57 2.8.7A2 2 0 0 1 22 16.9z"/></svg>
                {{-- Im arabischen Fliesstext dreht die Zweirichtungs-Regel
                     die Zifferngruppen um: aus "+49 179 9673909" wurde auf
                     der Seite "9673909 179 49+" - eine Nummer, die niemanden
                     erreicht. Sie steht deshalb ausdruecklich linkslaeufig
                     (dieselbe Regel wie bei den Signaturseiten). --}}
                <span dir="ltr">{{ $telAnzeige }}</span>
            </a>
            <a class="k-wa" href="{{ $waLink }}" target="_blank" rel="noopener" data-cta="whatsapp" data-cta-seite="{{ $page->slug }}">WhatsApp</a>
            <a class="k-form" href="#anfrage" data-cta="formular" data-cta-seite="{{ $page->slug }}">{{ __('Beratung anfragen') }}</a>
        </div>
    </div>

    <div class="cols {{ $hasLeft ? 'two' : 'one' }}">
        @if($hasLeft)
        <div>
            @if(count($highlights))
            <div class="card">
                <h2>✅ {{ __('Das Wichtigste in Kürze') }}</h2>
                <ul class="hl">
                    @foreach($highlights as $h)
                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg><span>{{ $h }}</span></li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if($body !== '')
            <div class="card prose">
                {!! $body !!}
            </div>
            @endif

            @if(count($faq))
            <div class="card">
                <h2>❓ {{ __('Häufige Fragen') }}</h2>
                <div class="faq">
                    @foreach($faq as $f)
                        <details><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
        @endif

        <div class="card form-card" id="anfrage">
            <h2>💬 {{ __('Beratung anfragen') }}</h2>

            @if(session('sent'))
                <div class="ok">✓ {{ __('Vielen Dank! Wir melden uns schnellstmöglich bei Ihnen – in der Regel innerhalb von 24 Stunden.') }}</div>
            @else
                @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
                <form method="POST" action="{{ route('services.submit', $page->slug) }}">
                    @csrf
                    <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                    {{-- for/id statt aria-label (Audit 15.09.2026): die
                         Beschriftungen sind ECHT und uebersetzt - sie
                         gehoeren mit dem Feld verbunden, nicht durch einen
                         zweiten, fest verdrahteten Text ersetzt. Vorher
                         hatte das oeffentliche Anfrageformular acht Felder
                         ohne Namen; mit einer Bildschirmlesehilfe war es
                         praktisch nicht ausfuellbar. --}}
                    <div class="field"><label for="anfrage-name">{{ __('Name') }} *</label>
                        <input type="text" id="anfrage-name" name="name" required value="{{ old('name') }}"></div>

                    <div class="grid2">
                        <div class="field"><label for="anfrage-email">{{ __('E-Mail') }}</label>
                            <input type="email" id="anfrage-email" name="email" value="{{ old('email') }}"></div>
                        <div class="field"><label for="anfrage-telefon">{{ __('Telefon') }}</label>
                            <input type="tel" id="anfrage-telefon" name="phone" value="{{ old('phone') }}"></div>
                    </div>

                    @foreach($customFields as $i => $f)
                        <div class="field">
                            <label for="anfrage-custom-{{ $i }}">{{ $f['label'] }}@if($f['required']) *@endif</label>
                            @if($f['type'] === 'textarea')
                                <textarea id="anfrage-custom-{{ $i }}" name="custom[{{ $i }}]" rows="3" @if($f['required']) required @endif>{{ old("custom.$i") }}</textarea>
                            @elseif($f['type'] === 'select')
                                <select id="anfrage-custom-{{ $i }}" name="custom[{{ $i }}]" @if($f['required']) required @endif>
                                    <option value="">{{ __('— Bitte wählen —') }}</option>
                                    @foreach($f['options'] as $opt)
                                        <option value="{{ $opt }}" @selected(old("custom.$i") === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $f['type'] }}" id="anfrage-custom-{{ $i }}" name="custom[{{ $i }}]" value="{{ old("custom.$i") }}" @if($f['required']) required @endif>
                            @endif
                        </div>
                    @endforeach

                    <div class="field"><label for="anfrage-nachricht">{{ __('Ihre Nachricht') }}</label>
                        <textarea id="anfrage-nachricht" name="message" rows="4">{{ old('message') }}</textarea></div>

                    <div class="consent">
                        <input type="checkbox" name="consent" value="1" id="consent" required>
                        <label for="consent" style="margin:0;font-weight:400;">{{ __('Ich stimme zu, dass meine Angaben zur Bearbeitung meiner Anfrage gespeichert und verarbeitet werden.') }}
                            <a href="{{ url('/datenschutz') }}">{{ __('Datenschutzerklärung') }}</a> *</label>
                    </div>

                    <button type="submit" class="btn">{{ __('Anfrage senden') }}</button>
                    <div class="hint">🔒 {{ __('Ihre Daten werden vertraulich behandelt') }}</div>
                </form>
            @endif
        </div>
    </div>

    @if($related->isNotEmpty())
    <section class="weiter" aria-labelledby="weiter-titel">
        <h2 id="weiter-titel">{{ $rtl ? 'خدمات ذات صلة' : 'Passende weitere Leistungen' }}</h2>
        <div class="liste">
            @foreach($related as $r)
                <a href="{{ ($rtl ? '/ar' : '') . '/leistungen/' . $r->slug }}">
                    {{ $r->t('title') }}
                    @if($r->t('subtitle'))<span>{{ $r->t('subtitle') }}</span>@endif
                </a>
            @endforeach
        </div>
    </section>
    @endif

    @php $providers = $page->providerList(); @endphp
    @if(count($providers))
    <div class="providers" aria-label="{{ __('Anbieter im Überblick') }}">
        <p class="providers-label">{{ __('Anbieter im Überblick') }}</p>
        <div class="marquee">
            <div class="marquee-track">
                @foreach($providers as $pv)<span class="pv">{{ $pv }}</span>@endforeach
                @foreach($providers as $pv)<span class="pv" aria-hidden="true">{{ $pv }}</span>@endforeach
            </div>
        </div>
    </div>
    @endif
</div>

{{-- Pflichtangaben vollstaendig (Abschnitt 12): Versicherungsvermittlung
     ist ein Bereich, in dem Google und Nutzer Vertrauensbelege erwarten.
     Impressum und Datenschutz allein reichen dafuer nicht - Erstinformation
     (Registernummer, Aufsichtsbehoerde), AGB und Widerruf gehoeren auf jede
     oeffentliche Seite, nicht nur auf die Startseite. --}}
<div class="foot">
    <span>© {{ date('Y') }} Dienstly24</span><span class="sep">·</span>
    <a href="{{ url('/impressum') }}">{{ __('Impressum') }}</a><span class="sep">·</span>
    <a href="{{ url('/datenschutz') }}">{{ __('Datenschutz') }}</a><span class="sep">·</span>
    <a href="{{ url('/erstinformation') }}">{{ __('Erstinformation') }}</a><span class="sep">·</span>
    <a href="{{ url('/agb') }}">AGB</a><span class="sep">·</span>
    <a href="{{ url('/widerruf') }}">{{ __('Widerruf') }}</a><span class="sep">·</span>
    <a href="{{ url('/leistungen') }}">{{ __('Alle Leistungen') }}</a><span class="sep">·</span>
    <a href="{{ route('login') }}">{{ __('Kundenportal') }}</a>
</div>
<p class="foot" style="border-top:0;padding-top:0;max-width:780px;margin:0 auto 22px;text-align:center;line-height:1.6;">{{ $rtl
    ? 'تتم وساطة التأمين عبر وسيط تأمين مرخّص وفق المادة 34d الفقرة 1 من قانون مزاولة الحرف الألماني (GewO). تجدون بيانات الوسيط ورقم السجل وجهة الرقابة في صفحة بيانات الناشر (Impressum) وصفحة المعلومات الأولى (Erstinformation).'
    : 'Die Versicherungsvermittlung erfolgt über einen zugelassenen Versicherungsmakler gem. § 34d Abs. 1 GewO. Angaben zum Vermittler, Registernummer und Aufsichtsbehörde finden Sie im Impressum und in der Erstinformation.' }}</p>
{{-- WhatsApp-Float mit leistungsspezifischem Text (P0-3) --}}
@include('website.partials.whatsapp', ['waText' => $rtl
    ? 'مرحباً Dienstly24، أريد استشارة بخصوص: ' . $page->t('title')
    : 'Hallo Dienstly24, ich interessiere mich für: ' . $page->title_de])
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
