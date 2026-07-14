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
<title>Dienstly24 — {{ $page->t('title') }}</title>
@if($page->t('meta_description'))<meta name="description" content="{{ $page->t('meta_description') }}">@endif
<meta name="robots" content="index, follow">
<link rel="canonical" href="{{ url('/leistungen/' . $page->slug) }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $page->t('title') }} – Dienstly24">
@if($page->t('meta_description'))<meta property="og:description" content="{{ $page->t('meta_description') }}">@endif
<meta property="og:url" content="{{ url('/leistungen/' . $page->slug) }}">
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => $page->t('title'),
    'serviceType' => $page->t('title'),
    'description' => $page->t('meta_description') ?: $page->t('subtitle'),
    'areaServed' => ['@type' => 'Country', 'name' => 'Deutschland'],
    'provider' => [
        '@type' => 'InsuranceAgency',
        'name' => 'Dienstly24',
        'url' => url('/'),
        'telephone' => '+49-179-9673909',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Furtweg 51a', 'postalCode' => '22523', 'addressLocality' => 'Hamburg', 'addressCountry' => 'DE'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@if(count($faq))
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => collect($faq)->map(fn ($f) => [
        '@type' => 'Question',
        'name' => $f['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ])->all(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--green2:#19b463;--green3:#128a4b;--mint:#3ddc8e;--paper:#0e0f12;--card:#15171b;--card2:#1b1e23;--line:rgba(255,255,255,.10);--line2:rgba(255,255,255,.16);--muted:#9aa1ab;--text:#eef1ee;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',system-ui,Arial,sans-serif;min-height:100vh;color:var(--text);display:flex;flex-direction:column;background:var(--paper);line-height:1.6;}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1100px 720px at 78% -8%, #23272e 0%, #14161a 46%, var(--paper) 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.045) 1px,transparent 1px);background-size:28px 28px;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1080px;width:100%;margin:0 auto;padding:18px 24px 0;}
.topbar img{height:34px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.06);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13px;padding:8px 14px;border-radius:10px;transition:background .2s;}
.lang-switch a:hover{background:rgba(255,255,255,.12);}
.page{flex:1;max-width:1080px;width:100%;margin:0 auto;padding:26px 24px 48px;}
.back{display:inline-flex;align-items:center;gap:6px;margin-bottom:22px;color:var(--muted);text-decoration:none;font-size:13.5px;}
.back:hover{color:#fff;}
/* Hero */
.hero{display:flex;gap:20px;align-items:flex-start;margin-bottom:14px;}
.hero .badge{flex-shrink:0;width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:32px;background:linear-gradient(155deg,rgba(23,166,91,.22),rgba(23,166,91,.06));border:1px solid rgba(23,166,91,.35);}
.hero h1{font-size:clamp(25px,3.6vw,36px);color:#fff;line-height:1.14;letter-spacing:-.01em;margin-bottom:8px;}
.hero .sub{color:var(--mint);font-size:15.5px;font-weight:600;}
.lead{color:#c7cec9;font-size:16px;line-height:1.75;margin:20px 0 30px;max-width:760px;}
/* Layout */
.cols{display:grid;gap:26px;align-items:start;}
.cols.two{grid-template-columns:1.05fr .95fr;}
.cols.one{grid-template-columns:minmax(0,560px);justify-content:center;}
@media(max-width:840px){.cols.two{grid-template-columns:1fr;}}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:24px;}
.card + .card{margin-top:18px;}
.card h2{font-size:17px;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:9px;}
.hl{list-style:none;display:flex;flex-direction:column;gap:13px;}
.hl li{display:flex;gap:11px;align-items:flex-start;font-size:15px;color:#d7ddd8;}
.hl li svg{width:19px;height:19px;flex-shrink:0;margin-top:1px;color:var(--mint);}
.faq details{border-bottom:1px solid var(--line);padding:13px 0;}
.faq details:last-child{border-bottom:0;}
.faq summary{cursor:pointer;font-weight:600;color:var(--text);font-size:14.5px;list-style:none;display:flex;justify-content:space-between;gap:10px;}
.faq summary::-webkit-details-marker{display:none;}
.faq summary::after{content:'+';color:var(--mint);font-weight:700;}
.faq details[open] summary::after{content:'–';}
.faq details p{color:#bcc3bd;font-size:14px;margin-top:9px;line-height:1.65;}
.prose h3{font-size:16px;color:#fff;margin:22px 0 8px;}
.prose h3:first-child{margin-top:0;}
.prose p{color:#c7cec9;font-size:14.5px;line-height:1.75;margin-bottom:12px;}
.prose ul{margin:0 0 14px;padding-inline-start:20px;}
.prose li{color:#c7cec9;font-size:14.5px;line-height:1.7;margin-bottom:6px;}
/* Form */
.form-card{position:sticky;top:22px;}
.form-card h2{font-size:19px;}
label{display:block;font-size:13px;margin-bottom:7px;color:#cfd5cf;font-weight:500;}
.field{margin-bottom:15px;}
.field input,.field select,.field textarea{width:100%;background:rgba(0,0,0,.28);border:1px solid var(--line2);border-radius:11px;color:#fff;font-size:14.5px;padding:12px 14px;outline:none;font-family:inherit;transition:border-color .18s, box-shadow .18s;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(23,166,91,.16);}
.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%239aa1ab' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px;}
[dir=rtl] .field select{background-position:left 14px center;padding-right:14px;padding-left:36px;}
.field select option{background:#14161a;color:#fff;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:480px){.grid2{grid-template-columns:1fr;}}
.consent{display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:#b9c0ba;line-height:1.5;margin:4px 0 6px;}
.consent input[type=checkbox]{width:18px;height:18px;flex:0 0 18px;margin-top:1px;accent-color:var(--green);cursor:pointer;}
.consent a{color:var(--mint);}
.btn{width:100%;background:linear-gradient(180deg,var(--green2),var(--green3));border:1px solid #1fc06e;color:#fff;font-size:15.5px;font-weight:700;padding:14px;border-radius:12px;cursor:pointer;margin-top:10px;transition:filter .2s, transform .12s, box-shadow .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 12px 28px rgba(23,166,91,.32);}
.hint{font-size:11.5px;color:var(--muted);text-align:center;margin-top:12px;}
.error{background:rgba(226,75,74,.14);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:11px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;}
.ok{background:rgba(23,166,91,.14);border:1px solid rgba(23,166,91,.45);color:#c6ecd8;border-radius:12px;padding:18px 18px;font-size:14.5px;line-height:1.6;}
.hp{position:absolute;left:-6000px;top:-6000px;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:20px 16px;font-size:12.5px;color:var(--muted);border-top:1px solid var(--line);}
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

<div class="page">
    <a href="{{ route('services.index') }}" class="back">← {{ __('Alle Leistungen') }}</a>

    <div class="hero">
        @if($page->icon)<div class="badge">{{ $page->icon }}</div>@endif
        <div>
            <h1>{{ $page->t('title') }}</h1>
            @if($page->t('subtitle'))<div class="sub">{{ $page->t('subtitle') }}</div>@endif
        </div>
    </div>
    @if($page->t('intro'))<p class="lead">{{ $page->t('intro') }}</p>@endif

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

                    <div class="field"><label>{{ __('Name') }} *</label>
                        <input type="text" name="name" required value="{{ old('name') }}"></div>

                    <div class="grid2">
                        <div class="field"><label>{{ __('E-Mail') }}</label>
                            <input type="email" name="email" value="{{ old('email') }}"></div>
                        <div class="field"><label>{{ __('Telefon') }}</label>
                            <input type="tel" name="phone" value="{{ old('phone') }}"></div>
                    </div>

                    @foreach($customFields as $i => $f)
                        <div class="field">
                            <label>{{ $f['label'] }}@if($f['required']) *@endif</label>
                            @if($f['type'] === 'textarea')
                                <textarea name="custom[{{ $i }}]" rows="3" @if($f['required']) required @endif>{{ old("custom.$i") }}</textarea>
                            @elseif($f['type'] === 'select')
                                <select name="custom[{{ $i }}]" @if($f['required']) required @endif>
                                    <option value="">{{ __('— Bitte wählen —') }}</option>
                                    @foreach($f['options'] as $opt)
                                        <option value="{{ $opt }}" @selected(old("custom.$i") === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $f['type'] }}" name="custom[{{ $i }}]" value="{{ old("custom.$i") }}" @if($f['required']) required @endif>
                            @endif
                        </div>
                    @endforeach

                    <div class="field"><label>{{ __('Ihre Nachricht') }}</label>
                        <textarea name="message" rows="4">{{ old('message') }}</textarea></div>

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
</div>

<div class="foot">
    <a href="{{ url('/impressum') }}">{{ __('Impressum') }}</a><span class="sep">·</span>
    <a href="{{ url('/datenschutz') }}">{{ __('Datenschutz') }}</a><span class="sep">·</span>
    <a href="{{ route('login') }}">{{ __('Kundenportal') }}</a>
</div>
</body>
</html>
