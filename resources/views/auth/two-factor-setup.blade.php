<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Zwei-Faktor-Anmeldung einrichten') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
<style>
.card{max-width:520px;}
.steps{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:14px;}
.steps li{display:flex;gap:11px;align-items:flex-start;font-size:13.5px;color:#c8ccd3;line-height:1.55;}
.steps .n{flex:none;width:23px;height:23px;border-radius:50%;background:rgba(23,166,91,.18);
    border:1px solid rgba(23,166,91,.5);color:#5fe3a1;font-size:12px;font-weight:700;
    display:flex;align-items:center;justify-content:center;}
.qrbox{background:#fff;border-radius:12px;padding:12px;display:inline-block;line-height:0;margin:6px 0 12px;}
.qrwrap{text-align:center;}
.keybox{background:rgba(0,0,0,.3);border:1px solid var(--gold-line);border-radius:10px;
    padding:11px 13px;margin:4px 0 14px;text-align:center;}
.keybox .k{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;letter-spacing:.09em;
    color:var(--gold-soft);word-break:break-all;direction:ltr;display:block;}
.keybox .lbl{font-size:11.5px;color:#9aa1ab;display:block;margin-bottom:5px;}
.apps{font-size:12.5px;color:#9aa1ab;line-height:1.6;margin:-6px 0 16px;}
.codeinput input{text-align:center;font-size:24px;letter-spacing:.35em;font-family:ui-monospace,Menlo,monospace;direction:ltr;}
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
        <h2>🛡️ {{ __('Zwei-Faktor-Anmeldung einrichten') }}</h2>

        <div class="warn">
            {{ __('In der Beraterwelt liegen Kundendaten, Gesundheitsangaben, Bankverbindungen und Ausweiskopien. Ein Passwort allein reicht dafür nicht. Ab jetzt kommt bei jeder Anmeldung ein sechsstelliger Code aus einer App auf Ihrem Telefon dazu.') }}
        </div>

        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <ol class="steps">
            <li><span class="n">1</span><span>
                {{ __('Installieren Sie eine Authenticator-App auf Ihrem Telefon.') }}
                <span class="apps">{{ __('Zum Beispiel Google Authenticator, Microsoft Authenticator oder Aegis - alle kostenlos.') }}</span>
            </span></li>
            <li><span class="n">2</span><span>{{ __('Öffnen Sie die App und scannen Sie diesen QR-Code:') }}</span></li>
        </ol>

        <div class="qrwrap">
            <div class="qrbox">{!! $qrSvg !!}</div>
        </div>

        <div class="keybox">
            <span class="lbl">{{ __('Kann Ihr Telefon nicht scannen? Geben Sie diesen Schlüssel in der App von Hand ein:') }}</span>
            <span class="k">{{ $secretFormatted }}</span>
        </div>

        <ol class="steps" start="3">
            <li><span class="n">3</span><span>{{ __('Geben Sie den sechsstelligen Code ein, den die App jetzt anzeigt:') }}</span></li>
        </ol>

        <form method="POST" action="{{ route('two_factor.setup.store') }}">
            @csrf
            <div class="field codeinput">
                <input id="code" type="text" name="code" required autofocus inputmode="numeric"
                       autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" placeholder="000000">
            </div>
            <button type="submit" class="btn">{{ __('Einrichtung abschließen') }} <span>{{ $rtl ? '←' : '→' }}</span></button>
        </form>

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
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
