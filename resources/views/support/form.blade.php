<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Hilfe & Kontakt') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#fff;display:flex;flex-direction:column;background:#0e0f12;overflow-x:hidden;}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #23262b 0%, #101216 48%, #0e0f12 100%);}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1200px;width:100%;margin:0 auto;padding:clamp(10px,1.8vh,20px) 28px 0;}
.topbar img{height:clamp(28px,4.5vh,40px);width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;}
.main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:clamp(10px,2vh,24px) 16px;}
.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:18px;padding:clamp(18px,2.6vh,30px);max-width:520px;width:100%;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 24px 60px rgba(0,0,0,.35);}
.card h2{font-size:22px;color:var(--mint);margin-bottom:6px;}
.card .lead{color:#b7bcc4;font-size:13.5px;line-height:1.5;margin-bottom:clamp(10px,2vh,18px);}
label{display:block;font-size:13.5px;margin-bottom:7px;color:#dde0e5;}
.field{margin-bottom:clamp(10px,1.8vh,15px);}
.field input,.field select,.field textarea{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:14.5px;padding:11px 13px;outline:none;transition:border-color .2s;font-family:inherit;}
.field select option{background:#101216;color:#fff;}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--green);}
.field input[readonly]{opacity:.75;cursor:default;}
.kd{display:flex;gap:10px;align-items:center;background:rgba(23,166,91,.12);border:1px solid rgba(23,166,91,.4);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13.5px;color:#c9e6d8;}
.btn{width:100%;background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:16px;font-weight:700;padding:14px;border-radius:11px;cursor:pointer;margin-top:6px;transition:transform .15s, box-shadow .2s, filter .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 10px 26px rgba(23,166,91,.35);}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;}
.hp{position:absolute;left:-6000px;top:-6000px;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:clamp(8px,1.6vh,14px) 16px;font-size:12.5px;color:#9aa1ab;}
.foot a{color:#c2c7cf;text-decoration:none;}
.foot a:hover{color:#fff;text-decoration:underline;}
.foot .sep{opacity:.35;}
</style>
    @include('partials.favicon')
</head>
<body>
<div class="bg"></div>

<div class="topbar">
    <a href="{{ route('login') }}"><img src="/images/logo-white.png" alt="Dienstly24"></a>
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
<div class="card">
    <h2>💬 {{ __('Hilfe & Kontakt') }}</h2>
    <p class="lead">{{ __('Beschreiben Sie kurz Ihr Anliegen – unser Team meldet sich schnellstmöglich bei Ihnen.') }}</p>

    @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('support.submit') }}">
        @csrf
        <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

        @if($customer)
            {{-- Kunde erkannt: Daten sind vorbefüllt, Anfrage wird der Akte zugeordnet --}}
            <input type="hidden" name="t" value="{{ $token }}">
            <div class="kd">✅ <span>{{ $customer->user?->name ?: __('Kunde') }} · {{ __('Kundennummer') }} <strong>{{ $customer->customer_number }}</strong> — {{ __('Ihre Anfrage wird direkt Ihrem Konto zugeordnet.') }}</span></div>
        @else
            <div class="field">
                <label for="name">{{ __('Ihr Name') }}</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required>
            </div>
            <div class="field">
                <label for="email">{{ __('E-Mail-Adresse') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required>
            </div>
        @endif

        <div class="field">
            <label for="leistung">{{ __('Gewünschte Leistung') }}</label>
            <select id="leistung" name="leistung" required>
                @foreach($leistungen as $key => $l)
                    <option value="{{ $key }}" @selected(old('leistung') === $key)>{{ __($l['label']) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="message">{{ __('Ihr Anliegen') }}</label>
            <textarea id="message" name="message" rows="5" required maxlength="5000" placeholder="{{ __('z. B. Ich habe eine Frage zum Login …') }}">{{ old('message') }}</textarea>
        </div>

        <button type="submit" class="btn">{{ __('Anfrage senden') }}</button>
    </form>
</div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <a href="{{ route('legal', 'kontakt') }}">{{ __('Kontakt') }}</a>
    <span class="sep">|</span>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>
</body>
</html>
