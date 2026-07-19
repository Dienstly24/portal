<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Neues Passwort setzen') }}</title>
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
        <h2>{{ __('Neues Passwort setzen') }}</h2>

        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ route('password.store') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <label for="email">{{ __('E-Mail-Adresse') }}</label>
            <div class="field">
                <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username">
            </div>

            <label for="password">{{ __('Neues Passwort') }}</label>
            <div class="field">
                <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="{{ __('Mindestens 8 Zeichen') }}">
            </div>

            <label for="password_confirmation">{{ __('Passwort bestaetigen') }}</label>
            <div class="field">
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn">{{ __('Passwort zuruecksetzen') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
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
