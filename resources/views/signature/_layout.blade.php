<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Dienstly24 — Dokument unterschreiben</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- HELLE Seite, bewusst anders als Anmeldung und Hilfe-Formular: hier wird
     ein Vertrag GELESEN. Dunkles Glas sieht gut aus und ist zum Lesen von
     Fliesstext auf einem Telefon die schlechtere Wahl.
     Markenfarben stehen in resources/css/brand.css (UX-1); die wenigen
     festen Werte hier sind Masse, keine Farben. --}}
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;background:var(--canvas);color:var(--ink);
     -webkit-text-size-adjust:100%;}
.kopf{background:var(--graphite);color:#fff;padding:14px 18px;display:flex;align-items:center;
      justify-content:space-between;gap:14px;position:sticky;top:0;z-index:20;}
.kopf img{height:26px;width:auto;display:block;}
.kopf .titel{font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.huelle{max-width:820px;margin:0 auto;padding:18px 14px 120px;}
.karte{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px;}
.karte h1{font-size:19px;margin-bottom:6px;}
.karte h2{font-size:16px;margin-bottom:6px;}
.lead{color:var(--ink-soft);font-size:14px;line-height:1.55;}
.hinweis{border-radius:10px;padding:11px 14px;font-size:13.5px;margin-bottom:14px;}
.hinweis-ok{background:var(--emerald-soft);color:var(--emerald-ink);}
.hinweis-fehler{background:#FBE9E9;color:#B3261E;}
.hinweis-warn{background:#FFF6E5;color:#8A5D00;}
.knopf{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;cursor:pointer;
       font-family:inherit;font-size:16px;font-weight:700;padding:15px 22px;border-radius:12px;
       background:linear-gradient(180deg,var(--emerald-bright),var(--emerald-deep));color:#fff;width:100%;
       /* Fingerfreundlich: 48px Mindesthoehe, sonst trifft man auf dem Telefon daneben. */
       min-height:52px;}
.knopf[disabled]{opacity:.55;cursor:not-allowed;}
.knopf-still{background:none;border:1px solid var(--line);color:var(--ink-soft);font-weight:600;font-size:14px;
             padding:12px 18px;min-height:46px;}
.feld{margin-bottom:14px;}
.feld label{display:block;font-size:13.5px;font-weight:600;margin-bottom:6px;}
.feld input[type=text],.feld input[type=date],.feld input[type=email],.feld textarea{
    width:100%;padding:13px 13px;border:1px solid var(--line);border-radius:10px;font-size:16px;
    font-family:inherit;background:#fff;color:var(--ink);}
.seite{position:relative;background:#fff;border:1px solid var(--line);border-radius:8px;overflow:hidden;
       margin-bottom:14px;box-shadow:0 3px 14px rgba(0,0,0,.07);}
.seite img{width:100%;height:auto;display:block;}
.feldmarke{position:absolute;border:2px dashed var(--emerald);background:rgba(23,166,91,.14);border-radius:5px;
           display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--emerald-ink);
           font-weight:700;cursor:pointer;text-align:center;overflow:hidden;padding:1px;}
.feldmarke.fertig{border-style:solid;background:rgba(23,166,91,.22);}
.zeichenflaeche{width:100%;height:190px;border:2px dashed var(--line);border-radius:12px;background:#fff;
                touch-action:none;display:block;}
.fussleiste{position:fixed;left:0;right:0;bottom:0;background:var(--surface);border-top:1px solid var(--line);
            padding:12px 14px calc(12px + env(safe-area-inset-bottom));z-index:30;}
.fussleiste .innen{max-width:820px;margin:0 auto;display:flex;gap:10px;align-items:center;}
.fortschritt{font-size:13px;color:var(--ink-soft);white-space:nowrap;}
@media (max-width:560px){ .huelle{padding:14px 10px 130px;} .karte{padding:15px;} }
</style>
</head>
<body>
<div class="kopf">
    <img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24">
    <div class="titel">@yield('kopftitel', 'Elektronische Unterschrift')</div>
</div>
<div class="huelle">
    @if(session('success'))<div class="hinweis hinweis-ok">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="hinweis hinweis-fehler">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="hinweis hinweis-fehler">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    @yield('inhalt')
</div>
@yield('fussleiste')
{{-- Ereignis-Verdrahtung (SEC-4). --}}
@stack('cspScripts')
</body>
</html>
