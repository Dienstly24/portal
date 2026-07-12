<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Konto erstellen') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#2d9c6e;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;background:radial-gradient(1200px 800px at 75% 20%, #1f4a40 0%, #142e27 45%, #0d211c 100%);color:#fff;display:flex;flex-direction:column;align-items:center;padding:36px 16px 20px;}
.lang-switch{position:absolute;top:18px;{{ $rtl ? 'left' : 'right' }}:22px;}
.lang-switch a{display:inline-flex;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#dfe9e4;text-decoration:none;font-size:13.5px;padding:8px 15px;border-radius:9px;}
.brand-logo{background:#fff;border-radius:14px;padding:10px 16px;margin-bottom:22px;}
.brand-logo img{height:52px;width:auto;display:block;}
.card{background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:18px;padding:32px 32px;max-width:520px;width:100%;backdrop-filter:blur(6px);}
.card h2{font-size:24px;color:#8fd6b4;margin-bottom:6px;}
.card .lead{color:#b8cec5;font-size:14px;line-height:1.5;margin-bottom:22px;}
label{display:block;font-size:13.5px;margin-bottom:7px;color:#dfe9e4;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.field{margin-bottom:16px;}
.field input{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:10px;color:#fff;font-size:15px;padding:13px 14px;outline:none;}
.field input:focus{border-color:var(--green);}
.hint{font-size:12px;color:#a7bfb5;margin-top:5px;}
.btn{width:100%;background:linear-gradient(180deg,#2f7f68,#256a56);border:1px solid #3a9077;color:#fff;font-size:16px;font-weight:700;padding:14px;border-radius:11px;cursor:pointer;margin-top:6px;}
.btn:hover{filter:brightness(1.08);}
.login-line{text-align:center;font-size:13.5px;color:#b8cec5;margin-top:18px;}
.login-line a{color:#8fd6b4;font-weight:700;text-decoration:none;}
.error{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;}
.consent{display:flex;gap:9px;align-items:flex-start;font-size:12.5px;color:#a7bfb5;margin:4px 0 12px;}
.consent input{margin-top:2px;width:15px;height:15px;accent-color:var(--green);}
.consent a{color:#8fd6b4;}
.hp{position:absolute;left:-6000px;top:-6000px;}
.foot{display:flex;gap:8px 22px;justify-content:center;flex-wrap:wrap;padding:16px 0 4px;font-size:13px;}
.foot a{color:#c4d6ce;text-decoration:none;}
@media(max-width:560px){.grid2{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>

<div class="brand-logo"><img src="/images/logo.png" alt="Dienstly24"></div>

<div class="card">
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
                'agb' => '<a href="https://dienstly24.de/impressum" target="_blank" rel="noopener">' . __('Nutzungsbedingungen') . '</a>',
                'privacy' => '<a href="https://dienstly24.de/datenschutz" target="_blank" rel="noopener">' . __('Datenschutzerklärung') . '</a>',
            ]) !!}</span>
        </label>

        <button type="submit" class="btn">{{ __('Konto erstellen') }}</button>
    </form>

    <p class="login-line">{{ __('Bereits registriert?') }} <a href="{{ route('login') }}">{{ __('Zum Login') }}</a></p>
</div>

<div class="foot">
    <a href="https://dienstly24.de/impressum" target="_blank" rel="noopener">{{ __('Impressum') }}</a>
    <a href="https://dienstly24.de/datenschutz" target="_blank" rel="noopener">{{ __('Datenschutzerklärung') }}</a>
    <a href="mailto:info@dienstly24.de">{{ __('Kontakt') }}</a>
</div>
</body>
</html>
