<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ $page->t('title') }}</title>
@if($page->t('meta_description'))<meta name="description" content="{{ $page->t('meta_description') }}">@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--paper:#0e0f12;--paper2:#15171b;--line:rgba(255,255,255,.14);--muted:#9aa1ab;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#eef1ee;display:flex;flex-direction:column;background:var(--paper);}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 12%, #23262b 0%, #101216 48%, var(--paper) 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1000px;width:100%;margin:0 auto;padding:16px 24px 0;}
.topbar img{height:36px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;}
.wrap{flex:1;max-width:1000px;width:100%;margin:0 auto;padding:28px 24px 40px;}
.hero{display:flex;gap:18px;align-items:flex-start;margin-bottom:8px;}
.hero .ic{font-size:40px;line-height:1;}
.hero h1{font-size:clamp(26px,4vw,38px);color:#fff;line-height:1.15;margin-bottom:6px;}
.hero .sub{color:var(--mint);font-size:16px;font-weight:600;}
.lead{color:#c9d1cc;font-size:16px;line-height:1.7;margin:18px 0 26px;max-width:720px;}
.grid{display:grid;grid-template-columns:1.15fr .85fr;gap:26px;align-items:start;}
@media(max-width:820px){.grid{grid-template-columns:1fr;}}
.card{background:var(--paper2);border:1px solid var(--line);border-radius:16px;padding:22px 22px;}
.card h2{font-size:18px;color:#fff;margin-bottom:14px;}
.hl{list-style:none;display:flex;flex-direction:column;gap:10px;}
.hl li{display:flex;gap:10px;align-items:flex-start;font-size:15px;color:#d7ddd8;}
.hl li::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--mint);margin-top:7px;flex-shrink:0;}
.faq{margin-top:24px;}
.faq details{background:var(--paper2);border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin-bottom:10px;}
.faq summary{cursor:pointer;font-weight:600;color:#eef1ee;font-size:15px;}
.faq p{color:#c1c8c3;font-size:14px;margin-top:8px;line-height:1.6;}
label{display:block;font-size:13.5px;margin-bottom:7px;color:#dde0e5;}
.field{margin-bottom:14px;}
.field input,.field textarea{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:14.5px;padding:11px 13px;outline:none;font-family:inherit;transition:border-color .2s;}
.field input:focus,.field textarea:focus{border-color:var(--green);}
.chk{display:flex;gap:9px;align-items:flex-start;font-size:13px;color:#c1c8c3;}
.chk input{margin-top:3px;}
.btn{width:100%;background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:16px;font-weight:700;padding:14px;border-radius:11px;cursor:pointer;margin-top:6px;transition:filter .2s, transform .15s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:14px;}
.ok{background:rgba(23,166,91,.14);border:1px solid rgba(23,166,91,.45);color:#bfe9d3;border-radius:11px;padding:16px 18px;font-size:15px;margin-bottom:18px;}
.hp{position:absolute;left:-6000px;top:-6000px;}
.back{display:inline-block;margin-bottom:18px;color:var(--muted);text-decoration:none;font-size:14px;}
.back:hover{color:#fff;}
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
    <a href="{{ route('services.index') }}" class="back">← {{ __('Alle Leistungen') }}</a>

    <div class="hero">
        @if($page->icon)<div class="ic">{{ $page->icon }}</div>@endif
        <div>
            <h1>{{ $page->t('title') }}</h1>
            @if($page->t('subtitle'))<div class="sub">{{ $page->t('subtitle') }}</div>@endif
        </div>
    </div>

    @if($page->t('intro'))<p class="lead">{{ $page->t('intro') }}</p>@endif

    <div class="grid">
        <div>
            @if(count($page->highlightList()))
            <div class="card">
                <h2>{{ __('Das Wichtigste in Kürze') }}</h2>
                <ul class="hl">
                    @foreach($page->highlightList() as $h)<li>{{ $h }}</li>@endforeach
                </ul>
            </div>
            @endif

            @if(count($page->faqList()))
            <div class="faq">
                <h2 style="font-size:18px;color:#fff;margin:24px 0 14px;">{{ __('Häufige Fragen') }}</h2>
                @foreach($page->faqList() as $f)
                    <details><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
                @endforeach
            </div>
            @endif
        </div>

        <div class="card" id="anfrage">
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
                    <div class="field"><label>{{ __('E-Mail') }}</label>
                        <input type="email" name="email" value="{{ old('email') }}"></div>
                    <div class="field"><label>{{ __('Telefon') }}</label>
                        <input type="tel" name="phone" value="{{ old('phone') }}"></div>
                    <div class="field"><label>{{ __('Ihre Nachricht') }}</label>
                        <textarea name="message" rows="4">{{ old('message') }}</textarea></div>
                    <div class="field chk">
                        <input type="checkbox" name="consent" value="1" id="consent" required>
                        <label for="consent" style="margin:0;">{{ __('Ich stimme zu, dass meine Angaben zur Bearbeitung meiner Anfrage gespeichert und verarbeitet werden.') }}
                            <a href="{{ url('/datenschutz') }}" style="color:var(--mint);">{{ __('Datenschutzerklärung') }}</a> *</label>
                    </div>
                    <button type="submit" class="btn">{{ __('Anfrage senden') }}</button>
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
