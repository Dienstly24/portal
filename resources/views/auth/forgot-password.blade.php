<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Passwort vergessen?') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
<style>
/* Schritt-fuer-Schritt-Erklaerung: Kunden sollen SEHEN, was passiert,
   bevor sie etwas eintippen (Betreiber-Meldung 18.08.2026). */
.steps{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:10px;}
.steps li{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;color:#c8ccd3;line-height:1.5;}
.steps .n{flex:none;width:22px;height:22px;border-radius:50%;background:rgba(23,166,91,.18);
    border:1px solid rgba(23,166,91,.5);color:#5fe3a1;font-size:12px;font-weight:700;
    display:flex;align-items:center;justify-content:center;}
.hint{font-size:12.5px;color:#9aa1ab;line-height:1.55;margin:-8px 0 16px;}
.helpbox{margin-top:18px;padding:12px 14px;border:1px solid var(--gold-line);border-radius:11px;
    background:rgba(184,161,107,.08);font-size:12.5px;color:#c8ccd3;line-height:1.6;}
.helpbox a{color:var(--gold-hell);font-weight:700;text-decoration:none;}
</style>
@include('partials.favicon')
</head>
<body>
<div class="bg"></div>

<div class="topbar">
    <img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24">
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
    <div class="card">
        <h2>🔑 {{ __('Passwort vergessen?') }}</h2>

        <ol class="steps">
            <li><span class="n">1</span><span>{{ __('Geben Sie unten Ihre E-Mail-Adresse ODER Ihre Kundennummer ein.') }}</span></li>
            <li><span class="n">2</span><span>{{ __('Wir senden Ihnen eine E-Mail mit einem Link.') }}</span></li>
            <li><span class="n">3</span><span>{{ __('Link anklicken, neues Passwort eingeben - fertig.') }}</span></li>
        </ol>

        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label for="identifier">{{ __('E-Mail-Adresse oder Kundennummer') }}</label>
            <div class="field">
                <input id="identifier" type="text" name="identifier" value="{{ old('identifier') }}"
                       required autofocus autocomplete="username"
                       placeholder="{{ __('z. B. name@beispiel.de oder 2600123') }}">
            </div>
            <p class="hint">
                {{ __('Ihre Kundennummer steht auf jedem Schreiben, das Sie von uns bekommen haben.') }}
            </p>

            <button type="submit" class="btn">{{ __('Link zum Zuruecksetzen senden') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
        </form>

        <div class="helpbox">
            {{ __('Sie kennen weder E-Mail-Adresse noch Kundennummer?') }}
            <a href="{{ route('support.form') }}">{{ __('Schreiben Sie uns - wir helfen persönlich.') }}</a>
        </div>

        <p class="back-line"><a href="{{ route('login') }}">{{ $rtl ? '→' : '←' }} {{ __('Zurueck zur Anmeldung') }}</a></p>
    </div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
