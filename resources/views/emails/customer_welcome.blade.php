<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
{{-- E-Mail-HTML: tabellenbasiert, Inline-Styles, absolute URLs, KEIN SVG
     (Gmail/Outlook entfernen SVG) – bewusst Emoji/Unicode als Icons.
     Bewusst OHNE eingebettete Bilder (Logo/QR): Outlook blockiert CID-
     Anhaenge oft ("Attachment blocked") -> kaputte Platzhalter. Textmarke
     im Hero reicht. KOMPAKT: alles Wesentliche ohne Scrollen sichtbar.
     Alle Kundenlinks zeigen auf {{ $portalBase }} (nie admin.*).
     Markenfarben: Petrol #1a3c34, Grün #2d9c6e, Blau #185FA5. --}}
<body style="margin:0;padding:0;background:#f0f2f1;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f1;padding:8px;"><tr><td align="center">
<table cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">

{{-- Hero-Band mit Textmarke (kein Bild) --}}
<tr><td align="center" style="background:#1a3c34;padding:20px 30px;">
    <p style="color:#7fd4ab;margin:0 0 4px;font-size:15px;font-weight:bold;letter-spacing:.04em;">DIENSTLY24</p>
    <h1 style="color:#ffffff;margin:0 0 3px;font-size:20px;">Willkommen bei Dienstly24 👋</h1>
    <p style="color:#c8ddd3;margin:0;font-size:13.5px;">Ihr Kundenportal ist jetzt bereit.</p>
</td></tr>

{{-- Anrede – kurz --}}
<tr><td style="padding:14px 30px 0;">
@php
    $hello = 'Hallo';
    if ($customer->gender === 'male') { $hello = 'Hallo Herr ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
    elseif ($customer->gender === 'female') { $hello = 'Hallo Frau ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
    elseif (trim($customerName) !== '') { $hello = 'Hallo ' . $customerName; }
@endphp
<p style="font-size:14.5px;color:#152826;margin:0;"><strong>{{ $hello }} 👋</strong> – Ihr direkter Draht zu Verträgen, Dokumenten und zu uns.</p>
</td></tr>

{{-- Magic Login – großer grüner Button --}}
@if($magicLoginUrl)
<tr><td align="center" style="padding:14px 30px 4px;">
    <a href="{{ $magicLoginUrl }}" style="display:inline-block;background:#2d9c6e;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;padding:13px 36px;border-radius:10px;">➜ &nbsp;Jetzt automatisch anmelden</a>
    <p style="font-size:11.5px;color:#666;margin:6px 0 0;">Ein Klick genügt – 90 Tage gültig, nur mit dieser E-Mail-Adresse. Danach eigenes Passwort festlegen.</p>
</td></tr>
@endif

{{-- Zugangsdaten + Passwort in EINER kompakten Card --}}
<tr><td style="padding:12px 30px 2px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;">
<tr><td style="padding:13px 18px;">
    <p style="font-size:12px;color:#1a3c34;margin:0 0 8px;letter-spacing:.06em;"><strong>IHRE ZUGANGSDATEN</strong></p>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="28" style="font-size:15px;padding:3px 0;">🌍</td>
            <td style="padding:3px 0;font-size:13.5px;"><a href="{{ $portalBase }}" style="color:#185FA5;font-weight:bold;text-decoration:none;">portal.dienstly24.de</a></td>
        </tr>
        <tr>
            <td width="28" style="font-size:15px;padding:3px 0;">👤</td>
            <td style="padding:3px 0;font-size:13.5px;color:#152826;"><strong>{{ $loginEmail }}</strong></td>
        </tr>
        <tr>
            <td width="28" style="font-size:15px;padding:3px 0;vertical-align:top;">🔑</td>
            <td style="padding:3px 0;font-size:13px;color:#152826;">
                @if($mode === 'birthdate')
                    <strong>Ihr erstes Passwort ist Ihr Geburtsdatum im Format TT.MM.JJJJ.</strong><br>
                    <span style="color:#666;font-size:12px;">Formatbeispiel: 01.01.1990 – bitte Ihr eigenes Geburtsdatum mit Punkten eingeben.</span>
                @elseif($mode === 'setlink')
                    <a href="{{ $setPasswordUrl }}" style="display:inline-block;background:#1a3c34;color:#ffffff;font-size:13px;font-weight:bold;text-decoration:none;padding:9px 20px;border-radius:8px;">Passwort jetzt festlegen</a>
                @else
                    Startpasswort: <strong style="font-size:15px;letter-spacing:.04em;">{{ $plainPassword }}</strong>
                @endif
            </td>
        </tr>
    </table>
</td></tr>
</table>
</td></tr>

{{-- Portal-Funktionen: 2x2 grosse rechteckige Karten mit Icons --}}
<tr><td style="padding:14px 30px 2px;">
    <p style="font-size:12px;color:#1a3c34;margin:0 0 10px;letter-spacing:.06em;text-align:center;"><strong>✨ WAS KÖNNEN SIE IM PORTAL TUN?</strong></p>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="50%" style="padding:0 5px 10px 0;">
                <a href="{{ $portalBase }}/contracts" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #d9e6df;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">📄</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#1a3c34;font-weight:bold;">Verträge</span><br><span style="font-size:12px;color:#777;">alle auf einen Blick</span></td>
                </tr></table></a>
            </td>
            <td width="50%" style="padding:0 0 10px 5px;">
                <a href="{{ $portalBase }}/documents" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #d9e6df;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">📁</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#1a3c34;font-weight:bold;">Dokumente</span><br><span style="font-size:12px;color:#777;">sicher abrufen</span></td>
                </tr></table></a>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding:0 5px 0 0;">
                <a href="{{ $portalBase }}/tickets/create" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #d9e6df;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">💬</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#1a3c34;font-weight:bold;">Support</span><br><span style="font-size:12px;color:#777;">Anfrage stellen</span></td>
                </tr></table></a>
            </td>
            <td width="50%" style="padding:0 0 0 5px;">
                <a href="{{ $portalBase }}/profile" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #d9e6df;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">👤</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#1a3c34;font-weight:bold;">Meine Daten</span><br><span style="font-size:12px;color:#777;">aktuell halten</span></td>
                </tr></table></a>
            </td>
        </tr>
    </table>
</td></tr>

{{-- Hilfe-Box (volle Breite) --}}
<tr><td style="padding:12px 30px 2px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#1a3c34;border-radius:10px;">
<tr>
    <td style="padding:14px 20px;">
        <p style="font-size:14px;color:#ffffff;margin:0 0 2px;"><strong>Brauchen Sie Hilfe?</strong></p>
        <p style="font-size:12px;color:#c8ddd3;margin:0;">Unser Team hilft Ihnen gerne: <a href="mailto:info@dienstly24.de" style="color:#7fd4ab;text-decoration:none;font-weight:bold;">info@dienstly24.de</a></p>
    </td>
    <td align="right" style="padding:14px 20px 14px 0;white-space:nowrap;">
        <a href="{{ $supportUrl }}" style="display:inline-block;background:#2d9c6e;color:#ffffff;font-size:13px;font-weight:bold;text-decoration:none;padding:11px 20px;border-radius:8px;">💬 Anfrage senden</a>
    </td>
</tr>
</table>
</td></tr>

{{-- Sicherheit + Datenschutz – kompakt --}}
<tr><td style="padding:12px 30px 2px;">
    <p style="font-size:11.5px;color:#7a5c12;background:#fff8e6;border:1px solid #f0e0b0;border-radius:8px;padding:9px 12px;margin:0;"><strong>🔒 Sicherheit:</strong> Wir fragen niemals per E-Mail oder Telefon nach Ihrem Passwort. Passwort vergessen? Einfach „Passwort vergessen" auf der Login-Seite nutzen.</p>
</td></tr>
<tr><td style="padding:8px 30px 12px;">
    <p style="font-size:10.5px;color:#8a938f;line-height:1.5;margin:0;"><strong>Hinweis zum Datenschutz:</strong> Zur Betreuung Ihrer Verträge verarbeiten wir auch vertragsbezogene Korrespondenz (z.&nbsp;B. von Versicherungs- oder Energieunternehmen) und stellen sie Ihnen im Portal bereit – ausschließlich zur Vertragsbetreuung, ohne unbefugte Weitergabe. Details: <a href="{{ $portalBase }}/datenschutz" style="color:#185FA5;text-decoration:none;">Datenschutzerklärung</a>.</p>
</td></tr>

{{-- Footer --}}
<tr><td align="center" style="background:#f8f9fb;border-top:1px solid #e2e8f0;padding:14px 30px;">
    <p style="font-size:12px;color:#152826;margin:0 0 4px;"><strong>Dienstly24</strong> – Ihr Partner für Finanzdienstleistungen</p>
    <p style="font-size:11.5px;margin:0 0 6px;">
        <a href="https://dienstly24.de" style="color:#185FA5;text-decoration:none;">Website</a> &nbsp;·&nbsp;
        <a href="{{ $portalBase }}/impressum" style="color:#185FA5;text-decoration:none;">Impressum</a> &nbsp;·&nbsp;
        <a href="{{ $portalBase }}/datenschutz" style="color:#185FA5;text-decoration:none;">Datenschutz</a>
    </p>
    <p style="font-size:10.5px;color:#9aa39f;margin:0;">Diese E-Mail wurde automatisch versendet. Bitte antworten Sie nicht direkt darauf.</p>
</td></tr>

</table>
</td></tr></table>
</body>
</html>
