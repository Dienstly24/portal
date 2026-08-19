<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Ersatzcodes') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.auth_glass_styles')
<style>
.card{max-width:520px;}
.codes{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin:6px 0 16px;}
.codes span{background:rgba(0,0,0,.3);border:1px solid var(--gold-line);border-radius:9px;
    padding:10px;text-align:center;font-family:ui-monospace,Menlo,Consolas,monospace;
    font-size:14.5px;letter-spacing:.06em;color:var(--gold-hell);direction:ltr;}
.warn{background:rgba(184,161,107,.12);border:1px solid var(--gold-line);color:#e3dcc6;
    border-radius:9px;padding:11px 13px;font-size:12.8px;line-height:1.6;margin-bottom:14px;}
.done{background:rgba(23,166,91,.15);border:1px solid rgba(23,166,91,.45);color:#5fe3a1;
    border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:14px;}
.info{font-size:13px;color:#c8ccd3;line-height:1.6;margin-bottom:14px;}
.btn-ghost{display:block;text-align:center;margin-top:10px;padding:11px;border-radius:11px;
    border:1px solid var(--line);color:#dde0e5;text-decoration:none;font-size:14px;}
.btn-ghost:hover{background:rgba(255,255,255,.06);}
.codeinput input{text-align:center;font-size:20px;letter-spacing:.3em;font-family:ui-monospace,Menlo,monospace;direction:ltr;}
@media print{.noprint{display:none!important;} body{background:#fff;color:#000;} .codes span{color:#000;border-color:#000;background:#fff;}}
</style>
@include('partials.favicon')
</head>
<body>
<div class="bg noprint"></div>

<div class="topbar noprint">
    <img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24">
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
    <div class="card">
        <h2>🗝️ {{ __('Ihre Ersatzcodes') }}</h2>

        @if(count($codes) > 0)
            <div class="done">{{ __('Die Zwei-Faktor-Anmeldung ist aktiv.') }}</div>

            <div class="warn">
                <strong>{{ __('Bitte jetzt sichern - diese Codes werden nie wieder angezeigt.') }}</strong><br>
                {{ __('Drucken Sie sie aus oder legen Sie sie an einen sicheren Ort. Mit einem Ersatzcode kommen Sie auch dann in Ihr Konto, wenn Ihr Telefon verloren, defekt oder gerade nicht zur Hand ist. Jeder Code funktioniert genau einmal.') }}
            </div>

            <div class="codes">
                @foreach($codes as $code)
                    <span>{{ $code }}</span>
                @endforeach
            </div>

            <button type="button" class="btn noprint" onclick="window.print();">🖨️ {{ __('Codes drucken') }}</button>
            <a class="btn-ghost noprint" href="{{ route('admin.dashboard') }}">{{ __('Weiter zur Beraterwelt') }}</a>
        @else
            @if($active)
                <p class="info">
                    {{ __('Die Zwei-Faktor-Anmeldung ist aktiv.') }}
                    {{ trans_choice('Sie haben noch :count unbenutzten Ersatzcode.|Sie haben noch :count unbenutzte Ersatzcodes.', $remaining, ['count' => $remaining]) }}
                </p>
                <p class="info">
                    {{ __('Aus Sicherheitsgründen können bereits erzeugte Codes nicht erneut angezeigt werden. Sie können sich jederzeit einen neuen Satz erstellen - die alten werden dabei ungültig.') }}
                </p>

                @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

                <form method="POST" action="{{ route('two_factor.recovery_codes.renew') }}">
                    @csrf
                    <label for="code">{{ __('Zur Bestätigung: Code aus Ihrer App') }}</label>
                    <div class="field codeinput">
                        <input id="code" type="text" name="code" required inputmode="numeric"
                               autocomplete="one-time-code" placeholder="000000">
                    </div>
                    <button type="submit" class="btn">{{ __('Neue Ersatzcodes erstellen') }}</button>
                </form>

                <a class="btn-ghost" href="{{ route('admin.dashboard') }}">{{ __('Weiter zur Beraterwelt') }}</a>
            @else
                <p class="info">{{ __('Die Zwei-Faktor-Anmeldung ist für dieses Konto noch nicht eingerichtet.') }}</p>
                <a class="btn-ghost" href="{{ route('two_factor.setup') }}">{{ __('Jetzt einrichten') }}</a>
            @endif
        @endif
    </div>
</div>

<div class="foot noprint">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>
</body>
</html>
