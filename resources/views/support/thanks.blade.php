<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('Anfrage erhalten') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
:root{--green:#17A65B;--mint:#3ddc8e;--line:rgba(255,255,255,.14);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;background:radial-gradient(1200px 800px at 70% 15%, #23262b 0%, #101216 48%, #0e0f12 100%);padding:20px;}
.card{background:rgba(255,255,255,.06);border:1px solid var(--line);border-radius:18px;padding:36px 32px;max-width:460px;width:100%;backdrop-filter:blur(14px);text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.35);}
.card .big{font-size:44px;margin-bottom:10px;}
.card h2{font-size:22px;color:var(--mint);margin-bottom:10px;}
.card p{color:#b7bcc4;font-size:14px;line-height:1.6;margin-bottom:8px;}
.ref{display:inline-block;background:rgba(23,166,91,.15);border:1px solid rgba(23,166,91,.45);color:#5fe3a1;border-radius:9px;padding:8px 16px;font-size:14px;font-weight:700;margin:8px 0 14px;letter-spacing:.06em;}
.btn{display:inline-block;background:linear-gradient(180deg,#19b463,#128a4b);border:1px solid #1fc06e;color:#fff;font-size:15px;font-weight:700;padding:12px 28px;border-radius:11px;text-decoration:none;}
</style>
    @include('partials.favicon')
</head>
<body>
<div class="card">
    <div class="big">✅</div>
    <h2>{{ __('Vielen Dank – Ihre Anfrage ist bei uns!') }}</h2>
    <p>{{ __('Unser Team kümmert sich schnellstmöglich um Ihr Anliegen.') }}</p>
    <div class="ref">{{ __('Vorgangsnummer') }}: {{ $ticketRef }}</div>
    <p>@if($customer){{ __('Sie können den Status jederzeit im Kundenportal unter „Anfragen" verfolgen.') }}@else{{ __('Wir melden uns per E-Mail bei Ihnen.') }}@endif</p>
    <a class="btn" href="{{ route('login') }}">{{ __('Zum Kundenportal') }}</a>
</div>
</body>
</html>
