<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Kundenportal') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--petrol:#1a3c34;--petrol-dark:#122b25;--green:#2d9c6e;--ink:#152826;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;background:radial-gradient(1200px 800px at 75% 20%, #1f4a40 0%, #142e27 45%, #0d211c 100%);color:#fff;display:flex;flex-direction:column;}
.lang-switch{position:absolute;top:18px;{{ $rtl ? 'left' : 'right' }}:22px;z-index:10;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dfe9e4;text-decoration:none;font-size:13.5px;padding:8px 15px;border-radius:9px;}
.lang-switch a:hover{background:rgba(255,255,255,.15);}
.wrap{flex:1;display:grid;grid-template-columns:1fr 1fr;max-width:1200px;width:100%;margin:0 auto;padding:48px 32px 24px;gap:40px;align-items:center;}
.brand-logo{background:#fff;display:inline-block;border-radius:14px;padding:12px 18px;margin-bottom:26px;}
.brand-logo img{height:64px;width:auto;display:block;}
.left h1{font-size:38px;margin-bottom:12px;}
.left .sub{color:#b8cec5;font-size:16px;line-height:1.6;margin-bottom:30px;max-width:420px;}
.feature{display:flex;gap:14px;align-items:flex-start;margin-bottom:20px;max-width:420px;}
.feature .ic{flex:none;width:44px;height:44px;border-radius:50%;border:1px solid var(--line);background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-size:19px;}
.feature strong{display:block;font-size:15px;margin-bottom:3px;}
.feature span{font-size:13.5px;color:#a7bfb5;}
.copyright{color:#7d968c;font-size:12.5px;margin-top:26px;}
.card{background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:18px;padding:36px 34px;backdrop-filter:blur(6px);max-width:460px;width:100%;justify-self:center;}
.card h2{font-size:26px;color:#8fd6b4;margin-bottom:8px;}
.card .lead{color:#b8cec5;font-size:14.5px;line-height:1.55;margin-bottom:26px;}
label{display:block;font-size:14px;margin-bottom:8px;color:#dfe9e4;}
.row-between{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.row-between a{color:#8fd6b4;font-size:13px;text-decoration:none;}
.field{position:relative;margin-bottom:20px;}
.field .fic{position:absolute;{{ $rtl ? 'right' : 'left' }}:14px;top:50%;transform:translateY(-50%);font-size:16px;opacity:.7;}
.field input{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:15px;padding:14px;{{ $rtl ? 'padding-right:44px;' : 'padding-left:44px;' }}outline:none;}
.field input:focus{border-color:var(--green);}
.eye{position:absolute;{{ $rtl ? 'left' : 'right' }}:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#b8cec5;font-size:16px;cursor:pointer;}
.btn{width:100%;background:linear-gradient(180deg,#2f7f68,#256a56);border:1px solid #3a9077;color:#fff;font-size:16.5px;font-weight:700;padding:15px;border-radius:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;}
.btn:hover{filter:brightness(1.08);}
.remember{display:flex;align-items:center;gap:9px;font-size:13.5px;color:#b8cec5;margin-bottom:20px;}
.remember input{width:16px;height:16px;accent-color:var(--green);}
.register-line{text-align:center;font-size:13.5px;color:#b8cec5;margin-top:22px;}
.register-line a{color:#8fd6b4;font-weight:700;text-decoration:none;}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:18px;}
.status{background:rgba(45,156,110,.15);border:1px solid rgba(45,156,110,.45);color:#9fe0c2;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:18px;}
.trust{display:flex;align-items:center;justify-content:center;gap:9px;color:#a7bfb5;font-size:13.5px;padding:14px 20px 4px;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 22px;padding:12px 20px 8px;font-size:13px;}
.foot a{color:#c4d6ce;text-decoration:none;}
.foot a:hover{color:#fff;text-decoration:underline;}
.foot-copy{text-align:center;color:#7d968c;font-size:12px;padding-bottom:20px;}
@media(max-width:900px){.wrap{grid-template-columns:1fr;padding-top:64px;gap:26px;}.left{text-align:center;}.left .sub,.feature{margin-left:auto;margin-right:auto;}.left h1{font-size:30px;}}
</style>
</head>
<body>
<div class="lang-switch">
    <a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a>
</div>

<div class="wrap">
    <div class="left">
        <div class="brand-logo"><img src="/images/logo.png" alt="Dienstly24"></div>
        <h1>{{ __('Willkommen') }}</h1>
        <p class="sub">{{ __('Ihr persönliches Portal für alle Ihre Verträge, Informationen und Dokumente.') }}</p>
        <div class="feature">
            <div class="ic">🛡️</div>
            <div><strong>{{ __('Sicherer Zugang') }}</strong><span>{{ __('Ihre Daten sind verschlüsselt und geschützt.') }}</span></div>
        </div>
        <div class="feature">
            <div class="ic">✨</div>
            <div><strong>{{ __('Ihr persönliches Portal') }}</strong><span>{{ __('Verträge, Dokumente und mehr an einem Ort.') }}</span></div>
        </div>
        <div class="copyright">© {{ date('Y') }} Dienstly24</div>
    </div>

    <div class="card">
        <h2>{{ __('Willkommen zurück') }}</h2>
        <p class="lead">{{ __('Bitte geben Sie Ihre Anmeldedaten ein, um auf Ihr Kundenkonto zuzugreifen.') }}</p>

        @if(session('status'))<div class="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label for="email">{{ __('E-Mail-Adresse') }}</label>
            <div class="field">
                <span class="fic">✉️</span>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="{{ __('Ihre E-Mail-Adresse eingeben') }}">
            </div>

            <div class="row-between">
                <label for="password" style="margin:0;">{{ __('Passwort') }}</label>
                <a href="{{ route('password.request') }}">{{ __('Passwort vergessen?') }}</a>
            </div>
            <div class="field">
                <span class="fic">🔒</span>
                <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="{{ __('Ihr Passwort eingeben') }}">
                <button type="button" class="eye" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';">👁</button>
            </div>

            <label class="remember"><input type="checkbox" name="remember"> {{ __('Angemeldet bleiben') }}</label>

            <button type="submit" class="btn">{{ __('Anmelden') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
        </form>

        <p class="register-line">{{ __('Noch kein Konto?') }} <a href="{{ route('register') }}">{{ __('Konto erstellen') }}</a></p>
    </div>
</div>

<div class="trust">🛡️ {{ __('Sicher & verschlüsselt. Ihre Daten sind bei uns sicher.') }}</div>
<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'agb') }}">AGB</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <a href="{{ route('legal', 'cookie-richtlinie') }}">Cookie-Richtlinie</a>
    <a href="{{ route('legal', 'kontakt') }}">{{ __('Kontakt') }}</a>
</div>
<div class="foot-copy">Copyright © Dienstly24 {{ date('Y') }}</div>
</body>
</html>
