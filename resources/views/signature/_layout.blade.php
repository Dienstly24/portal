@php
    // Die Sprache kommt vom UNTERZEICHNER (der Controller hat sie gesetzt),
    // nicht vom Browser und nicht von der Sitzung eines Mitarbeiters.
    $sprache = app()->getLocale();
    $rtl = in_array($sprache, \App\Models\SignatureSigner::RTL, true);
@endphp
<!DOCTYPE html>
<html lang="{{ $sprache }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Dienstly24 — {{ __('signing.title') }}</title>
@if($rtl)
    {{-- Lokal gehostet (DSGVO: kein Google-Server, siehe Website-Regel). --}}
    <link rel="stylesheet" href="{{ asset('fonts/fonts-ar.css') }}">
@endif
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
/* Arabisch: eigene Schrift mit passenden Metriken. Ohne sie setzt der
   Browser eine Systemschrift, in der die Verbindungen der Buchstaben je
   nach Geraet unterschiedlich brechen - auf einem Vertrag ist das die
   falsche Stelle zum Sparen. */
html[dir="rtl"] body{font-family:'IBM Plex Sans Arabic','IBM Plex Sans Arabic Fallback','Noto Sans Arabic',Arial,sans-serif;}
/* Spiegelbare Ausrichtung: alles, was links stand, steht rechts. */
html[dir="rtl"] .fortschritt{text-align:right;}
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
.zeichenflaeche{width:100%;height:clamp(200px,38vh,320px);border:2px dashed var(--line);border-radius:12px;background:#fff;
                touch-action:none;display:block;}
/* --- Betrachter: Miniaturen + Dokument ------------------------------
   MOBILE FIRST: die Miniaturen liegen als QUER-Streifen ueber dem
   Dokument. Eine echte Seitenleiste kostet auf einem 390px-Telefon die
   halbe Breite - und erzwingt genau das seitliche Scrollen, das hier
   ausdruecklich nicht passieren soll. Erst ab 900px steht sie links. */
#betrachter{display:flex;flex-direction:column;gap:12px;margin:14px 0;}
#miniaturen{display:flex;gap:8px;overflow-x:auto;padding:4px 2px 8px;
            -webkit-overflow-scrolling:touch;scrollbar-width:thin;}
.miniatur{position:relative;flex:none;width:74px;padding:0;border:2px solid var(--line);
          border-radius:6px;background:#fff;cursor:pointer;overflow:hidden;line-height:0;}
.miniatur img{width:100%;height:auto;display:block;}
.miniatur.aktiv{border-color:var(--emerald);box-shadow:0 0 0 2px rgba(23,166,91,.25);}
.miniatur .nummer{position:absolute;left:3px;bottom:3px;background:rgba(0,0,0,.62);color:#fff;
                  font-size:10px;line-height:1;padding:2px 4px;border-radius:3px;}
.miniatur .stelle-marke{position:absolute;right:3px;top:3px;background:var(--emerald);color:#fff;
                        font-size:10px;line-height:1;padding:2px 4px;border-radius:3px;font-weight:700;}
#werkzeuge{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
           padding:8px 10px;background:var(--surface);border:1px solid var(--line);border-radius:8px;}
.seitenstand{font-size:13px;font-weight:600;white-space:nowrap;}
.zoomknoepfe{display:flex;gap:6px;}
.zoomknopf{min-width:36px;height:32px;border:1px solid var(--line);background:#fff;border-radius:6px;
           font-size:15px;cursor:pointer;padding:0 8px;}
.zoomknopf.breit{font-size:12px;}
/* Der Rahmen scrollt, NICHT die Seite: beim Hineinzoomen darf das
   Dokument breiter werden, ohne die ganze Oberflaeche zu verschieben.
   Der Ausgangswert von --zoom steht als echte Definition da und nicht
   als Fallback im var() - sonst waere die einzige Quelle ein Notnagel. */
#dokumentrahmen{overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;}
#dokument{--zoom:1;width:calc(100% * var(--zoom));}
.pruefschritt{border-color:var(--emerald);}
.pruefliste{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin-top:12px;font-size:14px;}
.pruefliste dt{color:var(--ink-soft);}
.pruefliste dd{margin:0;font-weight:600;}
.fortschrittskarte .fortschritt-kopf{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.balken{height:7px;background:var(--line);border-radius:4px;margin:10px 0 12px;overflow:hidden;}
.balken-fuellung{height:100%;background:var(--emerald);transition:width .25s;}
.zustand{font-size:12px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.zustand[data-zustand="offen"]{background:#FDF2E3;color:#9A6212;}
.zustand[data-zustand="teilweise"]{background:#E7F1FD;color:#1F4E96;}
.zustand[data-zustand="fertig"]{background:#E4F5EC;color:#0E6B3A;}
.knopf.naechste{width:auto;}
@media (min-width:900px){
  #betrachter{flex-direction:row;align-items:flex-start;}
  #miniaturen{flex-direction:column;overflow-x:visible;overflow-y:auto;max-height:78vh;
              flex:none;width:96px;padding-right:6px;}
  .miniatur{width:100%;}
  #dokumentspalte{flex:1;min-width:0;}
}
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
    <div class="titel">@yield('kopftitel', __('signing.brand_subtitle'))</div>
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
