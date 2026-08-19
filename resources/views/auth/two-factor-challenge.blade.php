<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Bestätigung') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
<style>
.codeinput input{text-align:center;font-size:26px;letter-spacing:.4em;font-family:ui-monospace,Menlo,monospace;direction:ltr;}
.hint{font-size:12.5px;color:#9aa1ab;line-height:1.6;margin:-6px 0 16px;text-align:center;}
.helpbox{margin-top:16px;padding:11px 13px;border:1px solid var(--gold-line);border-radius:11px;
    background:rgba(184,161,107,.08);font-size:12.5px;color:#c8ccd3;line-height:1.6;}
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
        <h2>🔐 {{ __('Noch ein Schritt') }}</h2>
        <p class="hint">{{ __('Bitte geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein.') }}</p>

        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ route('two_factor.challenge.store') }}">
            @csrf
            <div class="field codeinput">
                <input id="code" type="text" name="code" required autofocus inputmode="numeric"
                       autocomplete="one-time-code" placeholder="000000">
            </div>
            <button type="submit" class="btn">{{ __('Bestätigen') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
        </form>

        <div class="helpbox">
            <strong>{{ __('Kein Zugriff auf Ihr Telefon?') }}</strong><br>
            {{ __('Geben Sie oben stattdessen einen Ihrer Ersatzcodes ein. Jeder Ersatzcode funktioniert genau einmal.') }}
            @if($remaining > 0)
                <br><span style="color:#9aa1ab;">{{ trans_choice('Noch :count Ersatzcode übrig.|Noch :count Ersatzcodes übrig.', $remaining, ['count' => $remaining]) }}</span>
            @endif
        </div>

        <div class="back-line">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" style="background:none;border:none;color:#b7bcc4;font-size:13px;cursor:pointer;text-decoration:underline;">
                    {{ __('Abmelden') }}
                </button>
            </form>
        </div>
    </div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>
</body>
</html>
