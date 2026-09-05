<!DOCTYPE html>
@php
    $rtl = app()->getLocale() === 'ar';
    $isForced = ($mode ?? 'invitation') === 'forced';
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Passwort festlegen') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
<style>
.lead{font-size:14px;color:#c8ccd3;line-height:1.6;margin-bottom:16px;}
.who{background:rgba(0,0,0,.25);border:1px solid var(--glass-line);border-radius:10px;
    padding:9px 12px;font-size:13px;color:#dde0e5;margin-bottom:16px;}
.who span{color:#9aa1ab;}
.rules{list-style:none;margin:-6px 0 16px;padding:0;font-size:12.5px;color:#9aa1ab;line-height:1.6;}
.rules li{display:flex;gap:7px;align-items:flex-start;}
.eye{position:absolute;{{ $rtl ? 'left' : 'right' }}:11px;top:50%;transform:translateY(-50%);
    background:none;border:none;color:#b7bcc4;font-size:15px;cursor:pointer;}
.warn{background:rgba(184,161,107,.12);border:1px solid var(--gold-line);color:#e3dcc6;
    border-radius:9px;padding:10px 12px;font-size:12.5px;line-height:1.6;margin-bottom:14px;}
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
        <h2>🔐 {{ $isForced ? __('Bitte legen Sie ein eigenes Passwort fest') : __('Passwort festlegen') }}</h2>

        @if($isForced)
            {{--
                Warum das erzwungen wird, in einem Satz: Kunden empfinden
                einen Zwang ohne Begruendung als Schikane. Mit Begruendung
                ist es ein Service.
            --}}
            <div class="warn">
                {{ __('Ihr bisheriges Passwort wurde vom System vergeben (z. B. Ihr Geburtsdatum). Das kennen auch andere - deshalb brauchen wir jetzt ein Passwort, das nur Sie kennen.') }}
            </div>
        @else
            <p class="lead">{{ __('Willkommen! Legen Sie hier Ihr persönliches Passwort fest. Danach können Sie sich jederzeit damit anmelden.') }}</p>
        @endif

        <div class="who">
            <span>{{ __('Konto') }}:</span> {{ $account->email }}
        </div>

        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form method="POST" action="{{ $action }}">
            @csrf

            <label for="password">{{ __('Neues Passwort') }}</label>
            <div class="field">
                <input id="password" type="password" name="password" required autofocus
                       autocomplete="new-password" minlength="{{ $minLength }}"
                       placeholder="{{ __('Neues Passwort eingeben') }}">
                <button type="button" class="eye"
                        data-h-click="afc4a7846d">👁</button>
            </div>

            <ul class="rules">
                <li><span>•</span><span>{{ __('Mindestens :min Zeichen.', ['min' => $minLength]) }}</span></li>
                <li><span>•</span><span>{{ __('Am besten mehrere Wörter hintereinander - das ist sicherer und leichter zu merken als ein kurzes, kompliziertes Passwort.') }}</span></li>
                <li><span>•</span><span>{{ __('Bitte kein Passwort verwenden, das Sie bereits woanders nutzen.') }}</span></li>
            </ul>

            <label for="password_confirmation">{{ __('Passwort wiederholen') }}</label>
            <div class="field">
                <input id="password_confirmation" type="password" name="password_confirmation" required
                       autocomplete="new-password" minlength="{{ $minLength }}"
                       placeholder="{{ __('Passwort erneut eingeben') }}">
            </div>

            <button type="submit" class="btn">{{ __('Passwort speichern') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
        </form>

        @if($isForced)
            {{-- Sackgasse vermeiden: abmelden muss immer moeglich bleiben. --}}
            <div class="back-line">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" style="background:none;border:none;color:#b7bcc4;font-size:13px;cursor:pointer;text-decoration:underline;">
                        {{ __('Abmelden') }}
                    </button>
                </form>
            </div>
        @else
            <p class="back-line"><a href="{{ route('login') }}">{{ $rtl ? '→' : '←' }} {{ __('Zurueck zur Anmeldung') }}</a></p>
        @endif
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

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["afc4a7846d"] = function (event) { const p=document.getElementById('password');p.type=p.type==='password'?'text':'password'; };
</script>
@endPushOnce
