<!DOCTYPE html>
{{-- Wartungsseite des gesperrten Kundenportals (KundenportalSperre,
     HTTP 503). Bewusst OHNE Vite-Bundle und ohne Skript - wie die
     Fehlerseiten 404/500 muss sie auch dann erscheinen, wenn das
     Build-Manifest fehlt. Kein externer Host, keine Kundendaten.
     Inhalte: Kontaktwege statt Portal (Betreiber-Vorgabe 20.11.2026). --}}
@php
    $wa = \App\Support\Kundenportal::whatsappLink('Hallo Dienstly24, ich habe ein Anliegen.');
    $tel = \App\Support\Kundenportal::telefonAnzeige();
    $mail = \App\Support\Kundenportal::email();
@endphp
<html lang="de" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Kundenportal vorübergehend nicht verfügbar – Dienstly24</title>
@include('partials.favicon')
<link rel="stylesheet" href="/fonts/fonts-de.css">
<style>
/* Tokens bewusst doppelt - siehe errors/404.blade.php (kein Vite-Bundle). */
:root{--emerald:#17A65B;--emerald-deep:#128A4B;--gold:#B8A16B;--canvas:#F8F6F0;--card:#FFFFFF;--line:#E0DCD0;--ink:#16211C;--muted:#5F6B62;--wa:#1F9E57;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--canvas);color:var(--ink);font-family:'Plus Jakarta Sans',system-ui,sans-serif;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:16px;line-height:1.6;}
.card{max-width:540px;width:100%;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:38px 28px;text-align:center;box-shadow:0 20px 50px rgba(22,33,28,.08);}
.marke{font-weight:800;font-size:1.15rem;letter-spacing:.02em;color:var(--ink);}
.marke span{color:var(--emerald);}
.kicker{display:inline-block;margin-top:18px;font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);}
h1{font-size:1.32rem;margin:8px 0 10px;line-height:1.35;}
p{color:var(--muted);font-size:.96rem;}
.aktionen{display:grid;gap:12px;margin-top:24px;}
.btn{display:flex;align-items:center;justify-content:center;gap:10px;padding:14px 20px;border-radius:14px;font-weight:700;font-size:1rem;text-decoration:none;min-height:48px;}
.btn-chat{background:linear-gradient(135deg,#19b463,var(--emerald-deep));color:#fff;box-shadow:0 8px 22px rgba(18,138,75,.28);}
.btn-wa{background:#fff;color:var(--wa);border:1.5px solid var(--wa);}
.kontakt{margin-top:22px;padding-top:18px;border-top:1px solid var(--line);display:grid;gap:6px;font-size:.95rem;}
.kontakt a{color:var(--ink);font-weight:600;text-decoration:none;}
.kontakt .label{color:var(--muted);font-weight:400;}
.rechtliches{margin-top:20px;font-size:.84rem;}
.rechtliches a{color:var(--emerald-deep);font-weight:600;text-decoration:none;margin:0 8px;}
.ar{margin-top:20px;padding-top:16px;border-top:1px dashed var(--line);font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;font-size:.92rem;color:var(--muted);}
</style>
</head>
<body>
<main class="card">
  <div class="marke">Dienstly<span>24</span></div>
  <span class="kicker">Hinweis</span>
  <h1>Unser Kundenportal ist vorübergehend nicht verfügbar.</h1>
  <p>Sie erreichen uns weiterhin direkt:</p>

  <div class="aktionen">
    <a class="btn btn-chat" href="/chat">💬 Im Chat schreiben</a>
    @if($wa)
      <a class="btn btn-wa" href="{{ $wa }}" target="_blank" rel="noopener">🟢 Per WhatsApp schreiben</a>
    @endif
  </div>

  <div class="kontakt">
    @if($tel !== '')
      <div><span class="label">Telefon:</span> <a href="{{ \App\Support\Kundenportal::telefonLink() }}" dir="ltr">{{ $tel }}</a></div>
    @endif
    @if($mail !== '')
      <div><span class="label">E-Mail:</span> <a href="mailto:{{ $mail }}">{{ $mail }}</a></div>
    @endif
  </div>

  <p class="ar" dir="rtl" lang="ar">بوابة العملاء غير متاحة مؤقتاً. يمكنكم التواصل معنا مباشرة عبر المحادثة أو واتساب أو الهاتف أو البريد الإلكتروني.</p>

  <nav class="rechtliches" aria-label="Rechtliches">
    <a href="{{ route('legal', 'impressum') }}">Impressum</a>
    <a href="{{ route('legal', 'datenschutz') }}">Datenschutz</a>
  </nav>
</main>
</body>
</html>
