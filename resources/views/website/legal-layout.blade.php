<!DOCTYPE html>
{{-- Rechtsseiten der Website: Inhalte 1:1 aus der bisherigen statischen
     Website uebernommen (dort bereits fachlich gepflegt: § 34d GewO,
     Vermittlerregister). Deutschsprachig, dunkles Markendesign, lokale
     Schriften (P0-4). Bewusst noindex (wie zuvor). --}}
<html lang="de" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, follow">
<title>@yield('title') – Dienstly24</title>
<link rel="canonical" href="{{ \App\Support\WebsiteHosts::url('/' . ($pageSlug ?? '')) }}">
@include('partials.favicon')
<link rel="stylesheet" href="/fonts/fonts-de.css">
<style>
  :root{color-scheme:dark; --emerald:#17A65B; --emerald-bright:#1EC975; --gold-soft:#D1C18F; --paper:#0F1512; --paper-2:#131A17; --line:#27342C; --text:#EDF1EE; --muted:#9BA6A0;}
  *{margin:0; padding:0; box-sizing:border-box;}
  body{background-color:var(--paper); background-image:radial-gradient(rgba(255,255,255,.035) 1px, transparent 1px); background-size:26px 26px; color:var(--text); font-family:'Plus Jakarta Sans',sans-serif; line-height:1.7;}
  a{color:var(--emerald-bright);}
  header{position:sticky; top:0; background:rgba(15,21,18,.9); backdrop-filter:blur(14px); border-bottom:1px solid var(--line); z-index:10;}
  .nav{max-width:900px; margin:0 auto; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;}
  .nav img{height:38px;}
  .back{font-size:.88rem; font-weight:700; color:var(--text); text-decoration:none; border:1.5px solid var(--line); border-radius:99px; padding:9px 18px; transition:all .2s;}
  .back:hover{border-color:var(--emerald); color:var(--emerald-bright);}
  main{max-width:900px; margin:0 auto; padding:60px 24px 80px;}
  h1{font-family:'Playfair Display',Georgia,serif; font-size:2rem; margin-bottom:34px; line-height:1.25;}
  h2{font-size:1.15rem; font-weight:700; margin:34px 0 12px; color:var(--emerald-bright);}
  p, li{font-size:.94rem; color:#C9D1CC; margin-bottom:12px;}
  ul{padding-inline-start:22px; margin-bottom:14px;}
  .card{background:var(--paper-2); border:1px solid var(--line); border-radius:14px; padding:18px 22px; margin:16px 0;}
  footer{border-top:1px solid var(--line); padding:26px 24px; text-align:center; font-size:.78rem; color:var(--muted);}
  footer a{color:var(--muted); margin:0 9px; text-decoration:none;}
  footer a:hover{color:var(--emerald-bright);}
</style>
</head>
<body>
<header><div class="nav">
  <a href="/"><img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24" width="152" height="38"></a>
  <a class="back" href="/">← Zur Startseite</a>
</div></header>
<main>
@yield('content')
</main>
<footer>
  © {{ date('Y') }} Dienstly24 ·
  <a href="/impressum">Impressum</a><a href="/datenschutz">Datenschutz</a><a href="/agb">AGB</a><a href="/widerruf">Widerruf</a><a href="/erstinformation">Erstinformation</a><a href="/cookie-richtlinie">Cookie-Richtlinie</a><a href="/bildnachweise">Bildnachweise</a>
</footer>
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
