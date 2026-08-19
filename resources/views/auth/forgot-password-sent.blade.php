<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; $hours = intdiv($validMinutes, 60); @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('E-Mail ist unterwegs') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
<style>
.big{font-size:40px;text-align:center;margin-bottom:6px;}
.lead{font-size:14.5px;color:#dde0e5;line-height:1.6;margin-bottom:18px;}
.checks{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:9px;}
.checks li{display:flex;gap:9px;align-items:flex-start;font-size:13.5px;color:#c8ccd3;line-height:1.55;}
.checks .ic{flex:none;font-size:14px;}
.helpbox{margin-top:4px;padding:12px 14px;border:1px solid var(--gold-line);border-radius:11px;
    background:rgba(184,161,107,.08);font-size:12.5px;color:#c8ccd3;line-height:1.6;}
.helpbox a{color:var(--gold-hell);font-weight:700;text-decoration:none;}
.btn-ghost{display:block;text-align:center;margin-top:12px;padding:11px;border-radius:11px;
    border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:14px;}
.btn-ghost:hover{background:rgba(255,255,255,.06);}
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
        <div class="big">📬</div>
        <h2 style="text-align:center;">{{ __('E-Mail ist unterwegs') }}</h2>

        {{--
            Bewusst OHNE Bestaetigung, ob es das Konto gibt: sonst koennte
            jeder Fremde durchprobieren, welche Adressen bei uns Kunde sind
            (DSGVO Art. 32). Dafuer steht hier ausfuehrlich, was zu tun ist -
            das hilft dem echten Kunden mehr als eine Fehlermeldung.
        --}}
        <p class="lead">
            {{ __('Wenn zu Ihrer Angabe ein Konto besteht, haben wir soeben eine E-Mail mit dem Link zum Zuruecksetzen verschickt.') }}
        </p>

        <ul class="checks">
            <li><span class="ic">📥</span><span>{{ __('Schauen Sie in Ihr Postfach - die E-Mail kommt meist innerhalb einer Minute.') }}</span></li>
            <li><span class="ic">🗂️</span><span>{{ __('Nichts da? Bitte prüfen Sie den Spam- bzw. Werbung-Ordner.') }}</span></li>
            <li><span class="ic">⏱️</span><span>
                @if($hours >= 1)
                    {{ trans_choice('Der Link ist :count Stunde gültig.|Der Link ist :count Stunden gültig.', $hours, ['count' => $hours]) }}
                @else
                    {{ __('Der Link ist :minutes Minuten gültig.', ['minutes' => $validMinutes]) }}
                @endif
                {{ __('Danach fordern Sie einfach einen neuen an.') }}
            </span></li>
            <li><span class="ic">🔒</span><span>{{ __('Ihr bisheriges Passwort gilt so lange weiter, bis Sie im Link ein neues setzen.') }}</span></li>
        </ul>

        <div class="helpbox">
            <strong>{{ __('Es kommt keine E-Mail an?') }}</strong><br>
            {{ __('Dann ist bei uns vielleicht eine andere Adresse hinterlegt.') }}
            <a href="{{ route('support.form') }}">{{ __('Schreiben Sie uns - wir helfen persönlich.') }}</a>
        </div>

        <a class="btn-ghost" href="{{ route('password.request') }}">{{ __('Erneut versuchen') }}</a>

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
