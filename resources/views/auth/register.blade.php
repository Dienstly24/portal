<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Konto erstellen') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#fff;display:flex;flex-direction:column;background:#0e0f12;overflow-x:hidden;}
html,body{height:100%;}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #23262b 0%, #101216 48%, #0e0f12 100%);}
.orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.5;will-change:transform;}
.orb-a{width:520px;height:520px;background:radial-gradient(circle,#17A65B44,transparent 70%);top:-140px;{{ $rtl ? 'right' : 'left' }}:-120px;animation:drift-a 26s ease-in-out infinite alternate;}
.orb-b{width:640px;height:640px;background:radial-gradient(circle,#3a3f4644,transparent 70%);bottom:-220px;{{ $rtl ? 'left' : 'right' }}:-160px;animation:drift-b 32s ease-in-out infinite alternate;}
@keyframes drift-a{from{transform:translate(0,0);}to{transform:translate(70px,50px);}}
@keyframes drift-b{from{transform:translate(0,0);}to{transform:translate(-80px,-60px);}}
.bg::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.05) 1px,transparent 1px);background-size:26px 26px;}
.rise{opacity:0;transform:translateY(16px);animation:rise .6s ease forwards;}
.d1{animation-delay:.05s}.d2{animation-delay:.15s}
@keyframes rise{to{opacity:1;transform:translateY(0);}}
@media (prefers-reduced-motion: reduce){.orb,.rise{animation:none;}.rise{opacity:1;transform:none;}}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1200px;width:100%;margin:0 auto;padding:clamp(10px,1.8vh,20px) 28px 0;}
.topbar img{height:clamp(28px,4.5vh,40px);width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:13.5px;padding:8px 15px;border-radius:9px;transition:background .2s;}
.lang-switch a:hover{background:rgba(255,255,255,.16);}
.main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:clamp(10px,2vh,24px) 16px;}
.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:18px;padding:clamp(18px,2.6vh,30px);max-width:520px;width:100%;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 24px 60px rgba(0,0,0,.35);}
.card h2{font-size:24px;color:var(--mint);margin-bottom:6px;}
.card .lead{color:#b7bcc4;font-size:13.5px;line-height:1.5;margin-bottom:clamp(10px,2vh,18px);}
label{display:block;font-size:13.5px;margin-bottom:7px;color:#dde0e5;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.field{margin-bottom:clamp(9px,1.7vh,15px);}
.field input{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:14.5px;padding:clamp(9px,1.7vh,12px) 13px;outline:none;transition:border-color .2s;}
.field input:focus{border-color:var(--green);}
.hint{font-size:11.5px;color:#9aa1ab;margin-top:3px;}
.btn{width:100%;background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:16px;font-weight:700;padding:14px;border-radius:11px;cursor:pointer;margin-top:6px;transition:transform .15s, box-shadow .2s, filter .2s;}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 10px 26px rgba(23,166,91,.35);}
.login-line{text-align:center;font-size:13px;color:#b7bcc4;margin-top:clamp(6px,1.4vh,14px);}
.login-line a{color:var(--mint);font-weight:700;text-decoration:none;}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;}
.consent{display:flex;gap:9px;align-items:flex-start;font-size:12.5px;color:#9aa1ab;margin:2px 0 clamp(6px,1.4vh,10px);}
.consent input{margin-top:2px;width:15px;height:15px;accent-color:var(--green);}
.consent a{color:var(--mint);}
.hp{position:absolute;left:-6000px;top:-6000px;}
.foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 18px;padding:clamp(8px,1.6vh,14px) 16px;font-size:12.5px;color:#9aa1ab;}
.foot .sep{opacity:.35;}
.foot a{color:#c2c7cf;text-decoration:none;}
.foot a:hover{color:#fff;text-decoration:underline;}
@media(max-width:560px){.grid2{grid-template-columns:1fr;}.topbar{padding:18px 18px 0;}.topbar img{height:32px;}.card{padding:26px 22px;}}
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
<div class="card rise d2">
    <h2>{{ __('Konto erstellen') }}</h2>
    <p class="lead">{{ __('Registrieren Sie sich kostenlos und nutzen Sie Ihr persönliches Kundenportal.') }}</p>

    @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('register') }}">
        @csrf
        {{-- Honeypot gegen Bots: für Menschen unsichtbar, muss leer bleiben --}}
        <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">

        <div class="grid2">
            <div class="field">
                <label for="first_name">{{ __('Vorname') }}</label>
                <input id="first_name" type="text" name="first_name" value="{{ old('first_name') }}" required autofocus>
            </div>
            <div class="field">
                <label for="last_name">{{ __('Nachname') }}</label>
                <input id="last_name" type="text" name="last_name" value="{{ old('last_name') }}" required>
            </div>
        </div>

        <div class="field">
            <label for="email">{{ __('E-Mail-Adresse') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        </div>

        <div class="field">
            <label for="birth_date">{{ __('Geburtsdatum') }}</label>
            <input id="birth_date" type="date" name="birth_date" value="{{ old('birth_date') }}" max="{{ now()->toDateString() }}">
            <div class="hint">{{ __('Hilft uns, Sie eindeutig zuzuordnen (optional).') }}</div>
        </div>

        <div class="grid2">
            <div class="field">
                <label for="password">{{ __('Passwort') }}</label>
                <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
            </div>
            <div class="field">
                <label for="password_confirmation">{{ __('Passwort bestätigen') }}</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" minlength="8">
            </div>
        </div>

        <label class="consent">
            <input type="checkbox" name="agb" value="1" required>
            <span>{!! __('Ich akzeptiere die :agb und habe die :privacy zur Kenntnis genommen.', [
                'agb' => '<a href="' . route('legal', 'agb') . '" target="_blank">' . __('Nutzungsbedingungen') . '</a>',
                'privacy' => '<a href="' . route('legal', 'datenschutz') . '" target="_blank">' . __('Datenschutzerklärung') . '</a>',
            ]) !!}</span>
        </label>

        {{-- Getrennte, NICHT vorausgewaehlte, freiwillige Einwilligung (Art. 7
             DSGVO, kein Kopplungsverbot-Verstoss): E-Mail-Archivierung zur
             besseren Betreuung. Jederzeit im Portal widerrufbar. --}}
        <label class="consent">
            <input type="checkbox" name="email_consent" value="1">
            <span>{{ __('Optional: Ich willige ein, dass vertragsbezogene E-Mails automatisch in meiner Kundenakte archiviert werden, damit Dienstly24 mich besser betreuen kann. Freiwillig und jederzeit widerrufbar.') }}</span>
        </label>

        <button type="submit" class="btn">{{ __('Konto erstellen') }}</button>
    </form>

    <p class="login-line">{{ __('Bereits registriert?') }} <a href="{{ route('login') }}">{{ __('Zum Login') }}</a></p>
</div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'agb') }}">AGB</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <a href="{{ route('legal', 'cookie-richtlinie') }}">Cookie-Richtlinie</a>
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
