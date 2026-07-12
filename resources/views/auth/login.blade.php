<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Kundenportal') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#2d9c6e;--mint:#8fd6b4;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#fff;display:flex;flex-direction:column;background:#0d211c;overflow-x:hidden;}

/* --- Dezenter animierter Energie-Hintergrund (nur CSS) --- */
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #1f4a40 0%, #142e27 48%, #0d211c 100%);}
.orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.5;will-change:transform;}
.orb-a{width:520px;height:520px;background:radial-gradient(circle,#2d9c6e55,transparent 70%);top:-140px;{{ $rtl ? 'right' : 'left' }}:-120px;animation:drift-a 26s ease-in-out infinite alternate;}
.orb-b{width:640px;height:640px;background:radial-gradient(circle,#1f6f8b44,transparent 70%);bottom:-220px;{{ $rtl ? 'left' : 'right' }}:-160px;animation:drift-b 32s ease-in-out infinite alternate;}
@keyframes drift-a{from{transform:translate(0,0);}to{transform:translate(70px,50px);}}
@keyframes drift-b{from{transform:translate(0,0);}to{transform:translate(-80px,-60px);}}
/* feines Punktraster gibt Tiefe, ohne abzulenken */
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}

/* --- Eingangs-Animation --- */
.rise{opacity:0;transform:translateY(16px);animation:rise .6s ease forwards;}
.d1{animation-delay:.05s}.d2{animation-delay:.15s}.d3{animation-delay:.25s}.d4{animation-delay:.35s}
@keyframes rise{to{opacity:1;transform:translateY(0);}}
@media (prefers-reduced-motion: reduce){.orb,.rise{animation:none;}.rise{opacity:1;transform:none;}}

/* --- Kopfzeile: kleines Logo + Sprache --- */
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1100px;width:100%;margin:0 auto;padding:22px 28px 0;}
.topbar img{height:40px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dfe9e4;text-decoration:none;font-size:13.5px;padding:8px 15px;border-radius:9px;transition:background .2s;}
.lang-switch a:hover{background:rgba(255,255,255,.16);}

/* --- Hero + Karte, zentriert --- */
.main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:34px 16px 20px;text-align:center;}
h1{font-size:40px;letter-spacing:-.5px;margin-bottom:10px;}
.sub{color:#b8cec5;font-size:16px;line-height:1.6;max-width:520px;margin-bottom:18px;}
.chips{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:30px;}
.chip{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:999px;padding:8px 16px;font-size:13.5px;color:#dfe9e4;}

.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:20px;padding:34px;max-width:440px;width:100%;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 24px 60px rgba(0,0,0,.35);text-align:{{ $rtl ? 'right' : 'left' }};}
.card h2{font-size:23px;color:var(--mint);margin-bottom:6px;}
.card .lead{color:#b8cec5;font-size:14px;line-height:1.55;margin-bottom:24px;}
label{display:block;font-size:14px;margin-bottom:8px;color:#dfe9e4;}
.row-between{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.row-between a{color:var(--mint);font-size:13px;text-decoration:none;}
.field{position:relative;margin-bottom:20px;}
.field .fic{position:absolute;{{ $rtl ? 'right' : 'left' }}:14px;top:50%;transform:translateY(-50%);font-size:16px;opacity:.7;}
.field input{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:15px;padding:14px;{{ $rtl ? 'padding-right:44px;' : 'padding-left:44px;' }}outline:none;transition:border-color .2s;}
.field input:focus{border-color:var(--green);}
.eye{position:absolute;{{ $rtl ? 'left' : 'right' }}:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#b8cec5;font-size:16px;cursor:pointer;}
.btn{width:100%;background:linear-gradient(180deg,#2f8f70,#256a56);border:1px solid #3a9077;color:#fff;font-size:16.5px;font-weight:700;padding:15px;border-radius:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:transform .15s, box-shadow .2s, filter .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 10px 26px rgba(45,156,110,.35);}
.remember{display:flex;align-items:center;gap:9px;font-size:13.5px;color:#b8cec5;margin-bottom:20px;}
.remember input{width:16px;height:16px;accent-color:var(--green);}
.register-line{text-align:center;font-size:13.5px;color:#b8cec5;margin-top:22px;}
.register-line a{color:var(--mint);font-weight:700;text-decoration:none;}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:18px;}
.status{background:rgba(45,156,110,.15);border:1px solid rgba(45,156,110,.45);color:#9fe0c2;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:18px;}

/* --- Vertrauen + Fußzeile --- */
.trust{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 22px;color:#a7bfb5;font-size:13.5px;padding:20px 20px 4px;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 22px;padding:12px 20px 8px;font-size:13px;}
.foot a{color:#c4d6ce;text-decoration:none;}
.foot a:hover{color:#fff;text-decoration:underline;}
.foot-copy{text-align:center;color:#7d968c;font-size:12px;padding-bottom:20px;}
@media(max-width:560px){h1{font-size:30px;}.topbar{padding:18px 18px 0;}.topbar img{height:32px;}.card{padding:26px 22px;}}
</style>
</head>
<body>
<div class="bg"><div class="orb orb-a"></div><div class="orb orb-b"></div></div>

<div class="topbar rise d1">
    <img src="/images/logo-white.png" alt="Dienstly24">
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
    <h1 class="rise d2">{{ __('Willkommen zurück') }}</h1>
    <p class="sub rise d2">{{ __('Ihr digitales Kundenportal – Verträge, Dokumente und Support an einem Ort.') }}</p>
    <div class="chips rise d3">
        <span class="chip">⚡ {{ __('Verträge') }}</span>
        <span class="chip">📄 {{ __('Dokumente') }}</span>
        <span class="chip">💬 {{ __('Support') }}</span>
        <span class="chip">👤 {{ __('Meine Daten') }}</span>
    </div>

    <div class="card rise d4">
        <h2>{{ __('Anmelden') }}</h2>
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

<div class="trust">
    <span>🔒 {{ __('SSL-verschlüsselt') }}</span>
    <span>✓ {{ __('DSGVO-konform') }}</span>
</div>
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
