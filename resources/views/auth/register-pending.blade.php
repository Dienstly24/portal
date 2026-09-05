{{-- Zwischenseite nach der Registrierung (Audit SEC-1): erklaert den
     naechsten Schritt. Bewusst eine EIGENE Seite und kein gruener
     Streifen - dieselbe Lehre wie bei "Passwort vergessen": ein Hinweis
     im Randbereich wird uebersehen, und dann wartet jemand auf ein
     Konto, das ohne seinen Klick nie entsteht. --}}
<!DOCTYPE html>
@php $rtl = app()->getLocale() === 'ar'; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dienstly24 — {{ __('E-Mail bestätigen') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
/* Markenfarben: resources/css/brand.css (UX-1). */
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;min-height:100vh;color:#fff;display:flex;flex-direction:column;background:var(--graphite-black);}
.bg{position:fixed;inset:0;z-index:-1;background:radial-gradient(1200px 800px at 70% 15%, #1A2C24 0%, var(--graphite-deep) 48%, var(--graphite-black) 100%);}
.topbar{display:flex;align-items:center;justify-content:space-between;max-width:1200px;width:100%;margin:0 auto;padding:20px 28px 0;}
.topbar img{height:36px;width:auto;display:block;}
.main{flex:1;display:flex;align-items:center;justify-content:center;padding:24px 16px;}
.card{background:rgba(255,255,255,.06);border:1px solid var(--gold-line);border-radius:18px;padding:32px;max-width:560px;width:100%;backdrop-filter:blur(14px);box-shadow:0 24px 60px rgba(0,0,0,.35);}
.card h2{font-size:24px;color:var(--emerald-mint);margin-bottom:10px;}
.card p{color:#b7bcc4;font-size:14.5px;line-height:1.65;margin-bottom:14px;}
.card strong{color:#fff;}
.steps{list-style:none;margin:18px 0;padding:0;}
.steps li{color:#dde0e5;font-size:14px;line-height:1.6;padding:9px 0 9px 30px;position:relative;border-bottom:1px solid var(--glass-line);}
.steps li:last-child{border-bottom:none;}
.steps li::before{content:'✓';position:absolute;{{ $rtl ? 'right' : 'left' }}:0;color:var(--emerald);font-weight:700;}
.note{background:rgba(184,161,107,.12);border:1px solid var(--gold-line);border-radius:10px;padding:13px 16px;font-size:13px;color:#d8d2be;line-height:1.6;margin:16px 0;}
.status{background:rgba(23,166,91,.15);border:1px solid rgba(23,166,91,.4);color:#9ce8bf;border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;}
.btn{width:100%;background:rgba(255,255,255,.08);border:1px solid var(--gold-line);color:#dde0e5;font-size:14.5px;font-weight:600;padding:12px;border-radius:10px;cursor:pointer;transition:background .2s;}
.btn:hover{background:rgba(255,255,255,.16);}
.login-line{text-align:center;font-size:13px;color:#b7bcc4;margin-top:16px;}
.login-line a{color:var(--emerald-mint);font-weight:700;text-decoration:none;}
</style>
@include('partials.favicon')
</head>
<body>
<div class="bg"></div>

<div class="topbar">
    <img src="{{ \App\Support\BrandAssets::logoLight() }}" alt="Dienstly24">
</div>

<div class="main">
<div class="card">
    <h2>📬 {{ __('Bitte bestätigen Sie Ihre E-Mail-Adresse') }}</h2>

    @if(session('status'))<div class="status">{{ session('status') }}</div>@endif

    <p>
        @if($email !== '')
            {!! __('Wir haben eine E-Mail an :email geschickt.', ['email' => '<strong>' . e($email) . '</strong>']) !!}
        @else
            {{ __('Wir haben Ihnen eine E-Mail geschickt.') }}
        @endif
    </p>

    <ul class="steps">
        <li>{{ __('Öffnen Sie die E-Mail von Dienstly24 in Ihrem Postfach.') }}</li>
        <li>{{ __('Klicken Sie auf "E-Mail-Adresse bestätigen".') }}</li>
        <li>{{ __('Ihr Kundenkonto wird angelegt und Sie sind sofort angemeldet.') }}</li>
    </ul>

    <div class="note">
        <strong>{{ __('Keine E-Mail erhalten?') }}</strong><br>
        {{ __('Bitte sehen Sie auch im Spam-Ordner nach. Der Bestätigungslink ist :hours Stunden gültig.', ['hours' => \App\Models\PendingRegistration::LIFETIME_HOURS]) }}
    </div>

    @if($email !== '')
    <form method="POST" action="{{ route('register.resend') }}">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        <button type="submit" class="btn">{{ __('Bestätigungsmail erneut senden') }}</button>
    </form>
    @endif

    <p class="login-line">{{ __('Bereits bestätigt?') }} <a href="{{ route('login') }}">{{ __('Zum Login') }}</a></p>
</div>
</div>
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
