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
    <a class="back" href="{{ route('login') }}">→ Zum Kundenportal-Login</a>
</div>

<div class="wrap">
<div class="card">
<h1>{{ $title }}</h1>

@if($page === 'impressum')
    <h2>Angaben gemäß § 5 TMG</h2>
    <p><strong>{{ $company['name'] }}</strong><br>
    {!! $company['address'] ? nl2br(e($company['address'])) : '<em>Anschrift wird ergänzt</em>' !!}</p>
    <h2>Kontakt</h2>
    <p>@if($company['phone'])Telefon: {{ $company['phone'] }}<br>@endif
    E-Mail: <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>
    @if($custom)<div class="custom" style="margin-top:14px;">{{ $custom }}</div>@endif
    <p class="muted">Verantwortlich für den Inhalt: {{ $company['name'] }}. Weitere Pflichtangaben (z. B. Inhaber, USt-IdNr., Aufsichtsbehörde) pflegen Sie unter Einstellungen → Rechtliches.</p>

@elseif($page === 'agb')
    @if($custom)
    <div class="custom">{{ $custom }}</div>
    @else
    <p>Für die Nutzung des Kundenportals von {{ $company['name'] }} gelten die zwischen Ihnen und uns vereinbarten Bedingungen aus Ihrem Betreuungs-/Maklervertrag.</p>
    <h2>Portal-Nutzung in Kürze</h2>
    <ul>
        <li>Das Kundenportal dient der Einsicht in Ihre Verträge und Dokumente sowie der Kommunikation mit uns.</li>
        <li>Ihre Zugangsdaten sind persönlich – bitte geben Sie sie nicht weiter.</li>
        <li>Änderungen an Ihren Daten werden erst nach Prüfung durch unser Team wirksam.</li>
        <li>Die Nutzung des Portals ist für Kunden kostenlos.</li>
    </ul>
    <p class="muted">Den vollständigen AGB-Text hinterlegen Sie unter Einstellungen → Rechtliches.</p>
    @endif

@elseif($page === 'datenschutz')
    <h2>Verantwortlicher</h2>
    <p>{{ $company['name'] }}@if($company['address'])<br>{!! nl2br(e($company['address'])) !!}@endif<br>
    E-Mail: <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>

    <h2>Welche Daten wir verarbeiten</h2>
    <ul>
        <li><strong>Stammdaten</strong> (Name, Kontaktdaten, Geburtsdatum, Adresse) zur Vertragsbetreuung.</li>
        <li><strong>Vertrags- und Dokumentdaten</strong> zu Ihren Versicherungs-, Energie- und weiteren Verträgen.</li>
        <li><strong>Vertragsbezogene Korrespondenz:</strong> Nachrichten und Dokumente, die uns von Versicherungs- oder Energieunternehmen zu Ihren Verträgen erreichen, ordnen wir Ihrem Kundenkonto zu und stellen sie Ihnen im Portal bereit. Wir greifen dabei <strong>nicht</strong> auf Ihr persönliches E-Mail-Postfach zu.</li>
        <li><strong>Portal-Nutzungsdaten</strong> (Anmeldezeitpunkte) zur Sicherheit Ihres Kontos.</li>
    </ul>

    <h2>Zweck und Rechtsgrundlage</h2>
    <p>Die Verarbeitung erfolgt zur Erfüllung des Betreuungs-/Maklervertrags (Art. 6 Abs. 1 lit. b DSGVO) sowie zur Wahrung rechtlicher Pflichten. Ihre Daten geben wir nicht unbefugt an Dritte weiter.</p>

    <h2>Sicherheit</h2>
    <p>Sensible Daten (z. B. Bankverbindung, Versicherungsnummern) speichern wir verschlüsselt. Die Übertragung erfolgt ausschließlich über verschlüsselte Verbindungen (TLS).</p>

    <h2>Ihre Rechte</h2>
    <p>Sie haben jederzeit das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie das Recht auf Beschwerde bei einer Aufsichtsbehörde. Wenden Sie sich dazu an <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>.</p>

    @if($custom)<div class="custom" style="margin-top:14px;">{{ $custom }}</div>@endif

@elseif($page === 'cookie-richtlinie')
    @if($custom)
    <div class="custom">{{ $custom }}</div>
    @else
    <p>Das Kundenportal verwendet ausschließlich <strong>technisch notwendige Cookies</strong>:</p>
    <ul>
        <li><strong>Sitzungs-Cookie</strong> – hält Sie nach dem Login angemeldet (wird beim Abmelden bzw. Sitzungsende gelöscht).</li>
        <li><strong>Sicherheits-Cookie (CSRF)</strong> – schützt Formulare vor Missbrauch.</li>
        <li><strong>Sprach-Einstellung</strong> – merkt sich Ihre gewählte Sprache (Deutsch/Arabisch).</li>
    </ul>
    <p>Es werden <strong>keine Tracking-, Analyse- oder Werbe-Cookies</strong> gesetzt und keine Daten zu Werbezwecken an Dritte übermittelt. Eine Einwilligung ist für technisch notwendige Cookies nicht erforderlich (§ 25 Abs. 2 TTDSG).</p>
    @endif

@elseif($page === 'kontakt')
    <p>Wir sind gerne für Sie da:</p>
    <h2>{{ $company['name'] }}</h2>
    <p>
    @if($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif
    @if($company['phone'])☎ Telefon: <a href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}">{{ $company['phone'] }}</a><br>@endif
    ✉ E-Mail: <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></p>
    <h2>Als Kunde angemeldet?</h2>
    <p>Am schnellsten erreichen Sie uns über den Bereich <a href="{{ route('login') }}">„Nachrichten" in Ihrem Kundenportal</a> – dort landet Ihre Anfrage direkt bei Ihrem Ansprechpartner.</p>
@endif
</div>
</div>

<div class="foot">
    <div class="foot-links">
        @foreach($pages as $slug => $label)
        <a href="{{ route('legal', $slug) }}" class="{{ $slug === $page ? 'active' : '' }}">{{ $slug === 'agb' ? 'AGB' : $label }}</a>
        @endforeach
    </div>
    <div class="foot-copy">Copyright © {{ $company['name'] }} {{ date('Y') }}</div>
</div>
</body>
</html>
