<!DOCTYPE html>
@php($isAr = app()->getLocale() === 'ar')
<html lang="{{ $isAr ? 'ar' : 'de' }}" dir="{{ $isAr ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>{{ $isAr ? 'الصفحة غير موجودة' : 'Seite nicht gefunden' }} – Dienstly24</title>
@include('partials.favicon')
<link rel="stylesheet" href="/fonts/fonts-{{ $isAr ? 'ar' : 'de' }}.css">
<style>
:root{--emerald:#17A65B;--emerald-deep:#128A4B;--gold-soft:#D1C18F;--canvas:#F8F6F0;--card:#FFFFFF;--line:#E0DCD0;--ink:#16211C;--muted:#5F6B62;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--canvas);color:var(--ink);font-family:'Plus Jakarta Sans',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;line-height:1.65;}
html[dir="rtl"] body{font-family:'IBM Plex Sans Arabic',system-ui,sans-serif;}
.card{max-width:520px;width:100%;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:46px 34px;text-align:center;box-shadow:0 20px 50px rgba(22,33,28,.08);}
.code{font-size:3.4rem;font-weight:800;color:var(--emerald-deep);line-height:1;}
.code small{display:block;font-size:.9rem;color:#B8A16B;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-top:8px;}
h1{font-size:1.4rem;margin:18px 0 10px;}
p{color:var(--muted);font-size:.95rem;margin-bottom:8px;}
.actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:24px;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:99px;font-weight:700;font-size:.92rem;text-decoration:none;}
.btn-primary{background:linear-gradient(135deg,#1EC975,var(--emerald-deep));color:#fff;box-shadow:0 8px 22px rgba(18,138,75,.32);}
.btn-ghost{border:1.5px solid var(--line);color:var(--ink);}
.links{margin-top:18px;font-size:.86rem;}
.links a{color:var(--emerald-deep);font-weight:600;text-decoration:none;margin:0 8px;}
</style>
</head>
<body>
<div class="card">
  <div class="code">404<small>{{ $isAr ? 'غير موجودة' : 'Nicht gefunden' }}</small></div>
  <h1>{{ $isAr ? 'هذه الصفحة غير موجودة.' : 'Diese Seite gibt es nicht.' }}</h1>
  <p>{{ $isAr
      ? 'ربما تغير الرابط أو كُتب بشكل غير صحيح. ستجدون كل خدماتنا عبر الصفحة الرئيسية.'
      : 'Vielleicht wurde der Link geändert oder falsch geschrieben. Über die Startseite finden Sie alle unsere Leistungen.' }}</p>
  <div class="actions">
    <a href="{{ $isAr ? '/ar' : '/' }}" class="btn btn-primary">{{ $isAr ? 'إلى الصفحة الرئيسية' : 'Zur Startseite' }}</a>
    <a href="{{ $isAr ? '/ar/leistungen' : '/leistungen' }}" class="btn btn-ghost">{{ $isAr ? 'خدماتنا' : 'Unsere Leistungen' }}</a>
  </div>
  <div class="links">
    <a href="tel:{{ config('website.phone_e164') }}">{{ config('website.phone_display') }}</a>
    <a href="mailto:{{ config('website.email') }}">{{ config('website.email') }}</a>
  </div>
</div>
@include('website.partials.whatsapp')
</body>
</html>
