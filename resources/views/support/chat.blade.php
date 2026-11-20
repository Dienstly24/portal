<!DOCTYPE html>
@php
    $rtl = app()->getLocale() === 'ar';
    $wa = \App\Support\Kundenportal::whatsappLink($rtl ? 'مرحباً Dienstly24، لدي استفسار.' : 'Hallo Dienstly24, ich habe ein Anliegen.');
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Dienstly24 — {{ __('Chat mit Dienstly24') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
/* Markenfarben: resources/css/brand.css (UX-1). Aufbau wie support/form. */
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;min-height:100dvh;color:#fff;display:flex;flex-direction:column;background:var(--graphite-black);overflow-x:hidden;}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #1A2C24 0%, var(--graphite-deep) 48%, var(--graphite-black) 100%);}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1200px;width:100%;margin:0 auto;padding:14px 16px 0;}
.topbar img{height:34px;width:auto;display:block;}
.lang-switch a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid var(--glass-line);color:#dde0e5;text-decoration:none;font-size:13px;padding:7px 13px;border-radius:9px;}
.main{flex:1;display:flex;justify-content:center;padding:16px;}
.card{background:rgba(255,255,255,.06);border:1px solid var(--glass-line);border-radius:18px;padding:20px;max-width:560px;width:100%;display:flex;flex-direction:column;gap:12px;}
.card h1{font-size:21px;color:var(--emerald-mint);line-height:1.25;}
.verlauf{display:flex;flex-direction:column;gap:8px;max-height:46vh;overflow-y:auto;padding:4px 2px;}
.blase{max-width:86%;padding:10px 13px;border-radius:14px;font-size:14.5px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;}
.blase small{display:block;font-size:11.5px;opacity:.7;margin-top:4px;}
.blase.team{background:rgba(255,255,255,.1);align-self:flex-start;border-end-start-radius:4px;}
.blase.sie{background:color-mix(in srgb, var(--emerald) 55%, transparent);align-self:flex-end;border-end-end-radius:4px;}
.blase.hinweis{background:rgba(209,193,143,.12);border:1px solid rgba(209,193,143,.35);color:#efe6c8;align-self:stretch;max-width:100%;}
label{display:block;font-size:13.5px;margin-bottom:6px;color:#dde0e5;}
.field input,.field textarea{width:100%;background:rgba(0,0,0,.25);border:1px solid var(--glass-line);border-radius:10px;color:#fff;font-size:15px;padding:11px 13px;outline:none;font-family:inherit;}
.field input:focus,.field textarea:focus{border-color:var(--emerald);}
.zustimmung{display:flex;gap:9px;align-items:flex-start;font-size:13px;color:#c2c7cf;line-height:1.45;}
.zustimmung input{margin-top:3px;width:18px;height:18px;flex:none;}
.zustimmung a{color:var(--emerald-mint);}
.btn{width:100%;background:linear-gradient(180deg,var(--emerald-bright),var(--emerald-deep));border:1px solid #1fc06e;color:#fff;font-size:16px;font-weight:700;padding:13px;border-radius:11px;cursor:pointer;}
.btn[disabled]{opacity:.6;cursor:wait;}
.wa{display:block;text-align:center;color:#7ee2a8;font-weight:600;text-decoration:none;font-size:14px;}
.fehler{background:rgba(226,75,74,.15);border:1px solid rgba(226,75,74,.4);color:#ffb9b8;border-radius:9px;padding:10px 13px;font-size:13.5px;}
.klein{font-size:12.5px;color:#9aa1ab;line-height:1.45;}
.hp{position:absolute;left:-6000px;top:-6000px;}
[hidden]{display:none!important;}
.foot{display:flex;flex-wrap:wrap;justify-content:center;gap:6px 18px;padding:12px 16px;font-size:12.5px;color:#9aa1ab;}
.foot a{color:#c2c7cf;text-decoration:none;}
</style>
    @include('partials.favicon')
</head>
<body>
<div class="bg"></div>
<div class="topbar">
    <a href="/"><img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24"></a>
    <div class="lang-switch"><a href="{{ route('locale.switch', $rtl ? 'de' : 'ar') }}">🌐 {{ $rtl ? 'Deutsch' : 'العربية' }}</a></div>
</div>

<div class="main">
<div class="card">
    <h1>💬 {{ __('Chat mit Dienstly24') }}</h1>

    <div class="verlauf" id="verlauf" aria-live="polite">
        <div class="blase hinweis">{{ __('Willkommen bei Dienstly24! Schreiben Sie uns Ihr Anliegen – wir melden uns schnellstmöglich. Hinweis: Wir sind kein Versicherungsvermittler und geben keine Tarifempfehlungen; auf Wunsch stellen wir den Kontakt zu einem zugelassenen Partner her.') }}</div>
    </div>

    <div class="fehler" id="fehler" role="alert" hidden></div>

    <form id="chatForm" method="POST" action="{{ route('chat.send') }}" novalidate>
        @csrf
        <input type="text" name="website" value="" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div id="gastfelder" @if($laeuft) hidden @endif>
            <div class="field" style="margin-bottom:10px;">
                <label for="name">{{ __('Ihr Name') }}</label>
                <input id="name" type="text" name="name" maxlength="120" autocomplete="name">
            </div>
            <div class="field" style="margin-bottom:10px;">
                <label for="kontakt">{{ __('Telefon oder E-Mail') }}</label>
                <input id="kontakt" type="text" name="kontakt" maxlength="190" autocomplete="email" dir="ltr">
            </div>
        </div>
        <div class="field" style="margin-bottom:10px;">
            <label for="nachricht">{{ __('Ihre Nachricht') }}</label>
            <textarea id="nachricht" name="nachricht" rows="3" maxlength="2000" required></textarea>
        </div>
        <div id="zustimmungfeld" @if($laeuft) hidden @endif>
            <label class="zustimmung">
                <input type="checkbox" name="einwilligung" value="1">
                <span>{{ __('Ich bin einverstanden, dass meine Angaben zur Bearbeitung meiner Anfrage gespeichert werden.') }}
                    <a href="{{ route('legal', 'datenschutz') }}" target="_blank" rel="noopener">{{ __('Datenschutzerklärung') }}</a></span>
            </label>
        </div>
        <button type="submit" class="btn" id="senden" style="margin-top:12px;">{{ __('Senden') }}</button>
    </form>

    <p class="klein">{{ __('Wir antworten hier im Chat, solange diese Seite geöffnet ist – oder melden uns über Ihre Telefonnummer bzw. E-Mail-Adresse.') }}</p>
    @if($wa)
        <a class="wa" href="{{ $wa }}" target="_blank" rel="noopener">🟢 {{ __('Per WhatsApp schreiben') }}</a>
    @endif
</div>
</div>

<div class="foot">
    <a href="{{ route('legal', 'impressum') }}">{{ __('Impressum') }}</a>
    <a href="{{ route('legal', 'datenschutz') }}">{{ __('Datenschutzerklärung') }}</a>
    <span>© {{ date('Y') }} Dienstly24</span>
</div>

<script @cspNonce>
(function () {
    var form = document.getElementById('chatForm');
    var verlauf = document.getElementById('verlauf');
    var fehler = document.getElementById('fehler');
    var knopf = document.getElementById('senden');
    var gesehen = {};
    var texte = {
        sie: @json(__('Sie')),
        team: @json(__('Dienstly24-Team')),
        fehlgeschlagen: @json(__('Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es erneut.'))
    };

    function zeigeFehler(text) {
        fehler.textContent = text || '';
        fehler.hidden = !text;
    }

    function fuegeHinzu(n) {
        if (gesehen[n.id]) { return; }
        gesehen[n.id] = true;
        var blase = document.createElement('div');
        blase.className = 'blase ' + (n.von === 'team' ? 'team' : 'sie');
        blase.textContent = n.text;
        var zeit = document.createElement('small');
        zeit.textContent = (n.von === 'team' ? texte.team : texte.sie) + ' · ' + (n.zeit || '');
        blase.appendChild(zeit);
        verlauf.appendChild(blase);
        verlauf.scrollTop = verlauf.scrollHeight;
    }

    function laden() {
        fetch(@json(route('chat.verlauf')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.nachrichten) { d.nachrichten.forEach(fuegeHinzu); } })
            .catch(function () {});
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        zeigeFehler('');
        knopf.disabled = true;
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (d) { return { status: r.status, daten: d }; });
        }).then(function (a) {
            if (a.status === 200 && a.daten.ok) {
                document.getElementById('nachricht').value = '';
                document.getElementById('gastfelder').hidden = true;
                document.getElementById('zustimmungfeld').hidden = true;
                laden();
            } else if (a.daten && a.daten.errors) {
                var erste = Object.values(a.daten.errors)[0];
                zeigeFehler(Array.isArray(erste) ? erste[0] : String(erste));
            } else {
                zeigeFehler((a.daten && a.daten.fehler) || texte.fehlgeschlagen);
            }
        }).catch(function () {
            zeigeFehler(texte.fehlgeschlagen);
        }).finally(function () {
            knopf.disabled = false;
        });
    });

    laden();
    setInterval(function () { if (!document.hidden) { laden(); } }, 6000);
})();
</script>
@stack('cspScripts')
</body>
</html>
