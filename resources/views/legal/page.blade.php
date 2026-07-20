<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $title }} — Dienstly24</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',Arial,sans-serif;background:#F4F5F7;color:#152826;min-height:100vh;display:flex;flex-direction:column;}
.top{background:#17191d;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.top .logo{display:inline-block;}
.top .logo img{height:38px;width:auto;display:block;}
.top a.back{color:#c6cbd3;text-decoration:none;font-size:13.5px;}
.top a.back:hover{color:#fff;}
.wrap{flex:1;max-width:820px;width:100%;margin:0 auto;padding:36px 22px;}
h1{font-size:26px;margin-bottom:20px;}
h2{font-size:17px;margin:22px 0 8px;color:#17191d;}
p,li{font-size:14.5px;line-height:1.7;color:#333;}
ul{padding-left:22px;margin:6px 0;}
.card{background:#fff;border:1px solid #E4E6EA;border-radius:12px;padding:26px 28px;}
.muted{color:#6B7280;font-size:13px;margin-top:14px;}
.custom{white-space:pre-line;}
a{color:#185FA5;}
.foot{background:#fff;border-top:1px solid #E4E6EA;padding:16px 22px;}
.foot-links{display:flex;flex-wrap:wrap;gap:8px 20px;justify-content:center;font-size:13px;}
.foot-links a{color:#4A5C59;text-decoration:none;}
.foot-links a.active{color:#17191d;font-weight:700;}
.foot-copy{text-align:center;color:#9aa39f;font-size:12px;margin-top:8px;}
</style>
    @include('partials.favicon')
</head>
<body>
<div class="top">
    <a class="logo" href="https://dienstly24.de"><img src="/images/logo-white.png" alt="Dienstly24"></a>
    <a class="back" href="{{ route('login') }}">→ {{ __('Zum Kundenportal-Login') }}</a>
</div>

<div class="wrap">
<div class="card">
<h1>{{ $title }}</h1>

@if($page === 'impressum')
    <h2>{{ __('Angaben gemäß § 5 TMG') }}</h2>
    <p><strong>{{ $company['name'] }}</strong><br>
    {!! $company['address'] ? nl2br(e($company['address'])) : '<em>' . __('Anschrift wird ergänzt') . '</em>' !!}</p>
    <h2>{{ __('Kontakt') }}</h2>
    <p>@if($company['phone']){{ __('Telefon:') }} {{ $company['phone'] }}<br>@endif
    {{ __('E-Mail:') }} <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>
    @if($custom)<div class="custom" style="margin-top:14px;">{{ $custom }}</div>@endif
    <p class="muted">{{ __('Verantwortlich für den Inhalt:') }} {{ $company['name'] }}. {{ __('Weitere Pflichtangaben (z. B. Inhaber, USt-IdNr., Aufsichtsbehörde) pflegen Sie unter Einstellungen → Rechtliches.') }}</p>

@elseif($page === 'agb')
    @if($custom)
    <div class="custom">{{ $custom }}</div>
    @else
    <p>{{ __('Für die Nutzung des Kundenportals von :name gelten die zwischen Ihnen und uns vereinbarten Bedingungen aus Ihrem Betreuungs-/Maklervertrag.', ['name' => $company['name']]) }}</p>
    <h2>{{ __('Portal-Nutzung in Kürze') }}</h2>
    <ul>
        <li>{{ __('Das Kundenportal dient der Einsicht in Ihre Verträge und Dokumente sowie der Kommunikation mit uns.') }}</li>
        <li>{{ __('Ihre Zugangsdaten sind persönlich – bitte geben Sie sie nicht weiter.') }}</li>
        <li>{{ __('Änderungen an Ihren Daten werden erst nach Prüfung durch unser Team wirksam.') }}</li>
        <li>{{ __('Die Nutzung des Portals ist für Kunden kostenlos.') }}</li>
    </ul>
    <p class="muted">{{ __('Den vollständigen AGB-Text hinterlegen Sie unter Einstellungen → Rechtliches.') }}</p>
    @endif

@elseif($page === 'datenschutz')
    <h2>{{ __('Verantwortlicher') }}</h2>
    <p>{{ $company['name'] }}@if($company['address'])<br>{!! nl2br(e($company['address'])) !!}@endif<br>
    {{ __('E-Mail:') }} <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>

    <h2>{{ __('Welche Daten wir verarbeiten') }}</h2>
    <ul>
        <li><strong>{{ __('Stammdaten') }}</strong> {{ __('(Name, Kontaktdaten, Geburtsdatum, Adresse) zur Vertragsbetreuung.') }}</li>
        <li><strong>{{ __('Vertrags- und Dokumentdaten') }}</strong> {{ __('zu Ihren Versicherungs-, Energie- und weiteren Verträgen.') }}</li>
        <li><strong>{{ __('Vertragsbezogene Korrespondenz:') }}</strong> {{ __('Nachrichten und Dokumente, die uns von Versicherungs- oder Energieunternehmen zu Ihren Verträgen erreichen, ordnen wir Ihrem Kundenkonto zu und stellen sie Ihnen im Portal bereit. Wir greifen dabei') }} <strong>{{ __('nicht') }}</strong> {{ __('auf Ihr persönliches E-Mail-Postfach zu.') }}</li>
        <li><strong>{{ __('Portal-Nutzungsdaten') }}</strong> {{ __('(Anmeldezeitpunkte) zur Sicherheit Ihres Kontos.') }}</li>
    </ul>

    <h2>{{ __('Zweck und Rechtsgrundlage') }}</h2>
    <p>{{ __('Die Verarbeitung erfolgt zur Erfüllung des Betreuungs-/Maklervertrags (Art. 6 Abs. 1 lit. b DSGVO) sowie zur Wahrung rechtlicher Pflichten. Ihre Daten geben wir nicht unbefugt an Dritte weiter.') }}</p>

    <h2>{{ __('Sicherheit') }}</h2>
    <p>{{ __('Sensible Daten (z. B. Bankverbindung, Versicherungsnummern) speichern wir verschlüsselt. Die Übertragung erfolgt ausschließlich über verschlüsselte Verbindungen (TLS).') }}</p>

    <h2>{{ __('Ihre Rechte') }}</h2>
    <p>{{ __('Sie haben jederzeit das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie das Recht auf Beschwerde bei einer Aufsichtsbehörde. Wenden Sie sich dazu an') }} <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>.</p>

    @if($custom)<div class="custom" style="margin-top:14px;">{{ $custom }}</div>@endif

@elseif($page === 'cookie-richtlinie')
    @if($custom)
    <div class="custom">{{ $custom }}</div>
    @else
    <p>{{ __('Das Kundenportal verwendet ausschließlich') }} <strong>{{ __('technisch notwendige Cookies') }}</strong>:</p>
    <ul>
        <li><strong>{{ __('Sitzungs-Cookie') }}</strong> – {{ __('hält Sie nach dem Login angemeldet (wird beim Abmelden bzw. Sitzungsende gelöscht).') }}</li>
        <li><strong>{{ __('Sicherheits-Cookie (CSRF)') }}</strong> – {{ __('schützt Formulare vor Missbrauch.') }}</li>
        <li><strong>{{ __('Sprach-Einstellung') }}</strong> – {{ __('merkt sich Ihre gewählte Sprache (Deutsch/Arabisch).') }}</li>
    </ul>
    <p>{{ __('Es werden') }} <strong>{{ __('keine Tracking-, Analyse- oder Werbe-Cookies') }}</strong> {{ __('gesetzt und keine Daten zu Werbezwecken an Dritte übermittelt. Eine Einwilligung ist für technisch notwendige Cookies nicht erforderlich (§ 25 Abs. 2 TTDSG).') }}</p>
    @endif

@elseif($page === 'kontakt')
    <p>{{ __('Wir sind gerne für Sie da:') }}</p>
    <h2>{{ $company['name'] }}</h2>
    <p>
    @if($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif
    @if($company['phone'])☎ {{ __('Telefon:') }} <a href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}">{{ $company['phone'] }}</a><br>@endif
    ✉ {{ __('E-Mail:') }} <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>
    <h2>{{ __('Als Kunde angemeldet?') }}</h2>
    <p>{{ __('Am schnellsten erreichen Sie uns über den Bereich') }} <a href="{{ route('login') }}">{{ __('„Nachrichten" in Ihrem Kundenportal') }}</a> – {{ __('dort landet Ihre Anfrage direkt bei Ihrem Ansprechpartner.') }}</p>
@endif
</div>
</div>

<div class="foot">
    <div class="foot-links">
        @foreach($pages as $slug => $label)
        <a href="{{ route('legal', $slug) }}" class="{{ $slug === $page ? 'active' : '' }}">{{ $slug === 'agb' ? 'AGB' : $label }}</a>
        @endforeach
    </div>
    <div class="foot-copy">{{ __('Copyright') }} © {{ $company['name'] }} {{ date('Y') }}</div>
</div>
@include('partials.cookie_consent')
</body>
</html>
