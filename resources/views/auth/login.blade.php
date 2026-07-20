<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Kundenportal') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
/* Single-Screen: alles passt in den ersten Viewport, kein Scrollen auf Desktop */
body{font-family:'Inter',Arial,sans-serif;height:100vh;color:#fff;display:flex;flex-direction:column;background:#0e0f12;overflow:hidden;}

/* --- Dezenter animierter Energie-Hintergrund (nur CSS) --- */
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #23262b 0%, #101216 48%, #0e0f12 100%);}
.orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.5;will-change:transform;}
.orb-a{width:520px;height:520px;background:radial-gradient(circle,#17A65B44,transparent 70%);top:-140px;{{ $rtl ? 'right' : 'left' }}:-120px;animation:drift-a 26s ease-in-out infinite alternate;}
.orb-b{width:640px;height:640px;background:radial-gradient(circle,#3a3f4644,transparent 70%);bottom:-220px;{{ $rtl ? 'left' : 'right' }}:-160px;animation:drift-b 32s ease-in-out infinite alternate;}
@keyframes drift-a{from{transform:translate(0,0);}to{transform:translate(70px,50px);}}
@keyframes drift-b{from{transform:translate(0,0);}to{transform:translate(-80px,-60px);}}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}

/* --- Eingangs-Animation --- */
.rise{opacity:0;transform:translateY(14px);animation:rise .55s ease forwards;}
.d1{animation-delay:.05s}.d2{animation-delay:.13s}.d3{animation-delay:.21s}.d4{animation-delay:.29s}
@keyframes rise{to{opacity:1;transform:translateY(0);}}
@media (prefers-reduced-motion: reduce){.orb,.rise{animation:none;}.rise{opacity:1;transform:none;}}

/* --- Kopfzeile --- */
.topbar{flex:none;display:flex;align-items:center;justify-content:space-between;max-width:1200px;width:100%;margin:0 auto;padding:clamp(10px,1.8vh,20px) 28px 0;}
.topbar img{height:clamp(28px,4.5vh,40px);width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;transition:background .2s;}
.lang-switch a:hover{background:rgba(255,255,255,.16);}

/* --- Hero + Karte: füllen die Resthöhe, ohne Scroll --- */
.main{flex:1;min-height:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:0 16px;text-align:center;gap:clamp(6px,1.4vh,14px);}
h1{font-size:clamp(21px,3.4vh,34px);letter-spacing:-.4px;line-height:1.2;}
.sub{color:#b7bcc4;font-size:clamp(12.5px,1.9vh,15px);line-height:1.5;max-width:640px;}
.chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;}
.chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:999px;padding:clamp(4px,.9vh,7px) 14px;font-size:12.5px;color:#dde0e5;}

.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:18px;padding:clamp(16px,3vh,28px) clamp(20px,3vh,30px);max-width:430px;width:100%;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 24px 60px rgba(0,0,0,.35);text-align:{{ $rtl ? 'right' : 'left' }};margin-top:clamp(2px,1vh,10px);}
.card h2{font-size:clamp(17px,2.4vh,21px);color:var(--mint);margin-bottom:clamp(8px,1.6vh,16px);}
label{display:block;font-size:13px;margin-bottom:6px;color:#dde0e5;}
.row-between{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.row-between a{color:var(--mint);font-size:12.5px;text-decoration:none;}
.field{position:relative;margin-bottom:clamp(10px,1.8vh,16px);}
.field .fic{position:absolute;{{ $rtl ? 'right' : 'left' }}:13px;top:50%;transform:translateY(-50%);font-size:15px;opacity:.7;}
.field input{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:14.5px;padding:clamp(9px,1.8vh,13px) 13px;{{ $rtl ? 'padding-right:42px;' : 'padding-left:42px;' }}outline:none;transition:border-color .2s;}
.field input:focus{border-color:var(--green);}
.eye{position:absolute;{{ $rtl ? 'left' : 'right' }}:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:#b7bcc4;font-size:15px;cursor:pointer;}
.btn{width:100%;background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:15.5px;font-weight:700;padding:clamp(10px,1.9vh,14px);border-radius:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:transform .15s, box-shadow .2s, filter .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 10px 26px rgba(23,166,91,.35);}
.remember{display:flex;align-items:center;gap:8px;font-size:13px;color:#b7bcc4;margin-bottom:clamp(10px,1.8vh,16px);}
.remember input{width:15px;height:15px;accent-color:var(--green);}
.policy-note{font-size:11.5px;line-height:1.5;color:#9aa1ab;text-align:center;margin-top:clamp(8px,1.6vh,14px);}
.policy-note a{color:var(--mint);text-decoration:none;}
.register-line{text-align:center;font-size:13px;color:#b7bcc4;margin-top:clamp(6px,1.2vh,12px);}
.register-line a{color:var(--mint);font-weight:700;text-decoration:none;}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:9px 12px;font-size:13px;margin-bottom:12px;}
.status{background:rgba(23,166,91,.15);border:1px solid rgba(23,166,91,.45);color:#5fe3a1;border-radius:9px;padding:9px 12px;font-size:13px;margin-bottom:12px;}

/* --- Fußzeile: eine kompakte Zeile (Links + Vertrauen + Copyright) --- */
.foot{flex:none;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:clamp(8px,1.6vh,14px) 16px;font-size:12.5px;color:#9aa1ab;}
.foot a{color:#c2c7cf;text-decoration:none;}
.foot a:hover{color:#fff;text-decoration:underline;}
.foot .sep{opacity:.35;}

/* Auf kleinen/mobilen Screens darf wieder gescrollt werden */
@media(max-width:700px){
  body{height:auto;min-height:100vh;overflow:auto;}
  .topbar{padding:14px 16px 0;}
  .main{padding:16px;}
  h1{font-size:24px;}
}
</style>
    @include('partials.favicon')
</head>
<body>
<div class="bg"><div class="orb orb-a"></div><div class="orb orb-b"></div></div>

<div class="topbar rise d1">
    <img src="/images/logo-white.png" alt="Dienstly24">
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
    <h1 class="rise d2">{{ __('Herzlich willkommen bei Dienstly24') }}</h1>
    <p class="sub rise d2">{{ __('Ihr zuverlässiger Partner für professionelle Beratung, erstklassigen Service und persönliche Unterstützung in Deutschland.') }}</p>
    <div class="chips rise d3">
        <span class="chip">⚡ {{ __('Verträge') }}</span>
        <span class="chip">📄 {{ __('Dokumente') }}</span>
        <span class="chip">💬 {{ __('Support') }}</span>
        <span class="chip">👤 {{ __('Meine Daten') }}</span>
    </div>

    <div class="card rise d4">
        <h2>{{ __('Anmelden') }}</h2>

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

        <p class="policy-note">{!! __('Mit der Anmeldung stimmen Sie den :agb zu und bestätigen die :privacy sowie die :cookie.', [
            'agb' => '<a href="' . route('legal', 'agb') . '" target="_blank">' . __('Nutzungsbedingungen') . '</a>',
            'privacy' => '<a href="' . route('legal', 'datenschutz') . '" target="_blank">' . __('Datenschutzerklärung') . '</a>',
            'cookie' => '<a href="' . route('legal', 'cookie-richtlinie') . '" target="_blank">' . __('Cookie-Richtlinie') . '</a>',
        ]) !!}</p>

        <p class="register-line">{{ __('Noch kein Konto?') }} <a href="{{ route('register') }}">{{ __('Konto erstellen') }}</a></p>
    </div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'agb') }}">{{ __('AGB') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <a href="{{ route('legal', 'cookie-richtlinie') }}">{{ __('Cookie-Richtlinie') }}</a>
    <a href="{{ route('legal', 'kontakt') }}">{{ __('Kontakt') }}</a>
    <span class="sep">|</span>
    <span>🔒 {{ __('SSL-verschlüsselt') }}</span>
    <span>✓ {{ __('DSGVO-konform') }}</span>
    <span class="sep">|</span>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>
@include('partials.cookie_consent')
</body>
</html>
