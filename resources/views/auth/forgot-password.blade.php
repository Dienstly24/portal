<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Passwort vergessen?') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
@include('partials.favicon')
</head>
<body>
<div class="bg"></div>

<div class="topbar">
    <img src="/images/logo-white.png" alt="Dienstly24">
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
    <div class="card">
        <h2>{{ __('Passwort vergessen?') }}</h2>
        <p class="sub" style="margin-bottom:16px;text-align:{{ $rtl ? 'right' : 'left' }};max-width:none;">
            {{ __('Kein Problem. Geben Sie Ihre E-Mail-Adresse ein und wir senden Ihnen einen Link zum Zuruecksetzen Ihres Passworts.') }}
        </p>

        @if(session('status'))<div class="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label for="email">{{ __('E-Mail-Adresse') }}</label>
            <div class="field">
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="{{ __('Ihre E-Mail-Adresse eingeben') }}">
            </div>
            <button type="submit" class="btn">{{ __('Link zum Zuruecksetzen senden') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
        </form>

        <p class="back-line"><a href="{{ route('login') }}">{{ $rtl ? '→' : '←' }} {{ __('Zurueck zur Anmeldung') }}</a></p>
    </div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>
</body>
</html>
