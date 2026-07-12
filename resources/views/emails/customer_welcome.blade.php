<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
{{-- E-Mail-HTML: tabellenbasiert, Inline-Styles, absolute URLs, KEIN SVG
     (Gmail/Outlook entfernen SVG) – bewusst Emoji/Unicode als Icons.
     Markenfarben: Petrol #1a3c34 (Header), Grün #2d9c6e (Buttons),
     Blau #185FA5 (Links), Hellgrau #f8f9fb (Cards). --}}
<body style="margin:0;padding:0;background:#f0f2f1;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f1;padding:24px 8px;"><tr><td align="center">
<table cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">

{{-- 1. Logo --}}
<tr><td align="center" style="padding:22px 30px 14px;background:#ffffff;">
    <img src="{{ url('/images/logo.png') }}" alt="Dienstly24" width="170" style="display:block;max-width:170px;height:auto;">
</td></tr>

{{-- 2. Hero-Band --}}
<tr><td align="center" style="background:#1a3c34;padding:26px 30px;">
    <h1 style="color:#ffffff;margin:0 0 6px;font-size:23px;">Willkommen bei Dienstly24 👋</h1>
    <p style="color:#c8ddd3;margin:0;font-size:15px;">Ihr Kundenportal ist jetzt bereit.</p>
</td></tr>

<tr><td style="padding:28px 30px 10px;">
{{-- 9. Persönliche Anrede --}}
@php
    $hello = 'Hallo';
    if ($customer->gender === 'male') { $hello = 'Hallo Herr ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
    elseif ($customer->gender === 'female') { $hello = 'Hallo Frau ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
    elseif (trim($customerName) !== '') { $hello = 'Hallo ' . $customerName; }
@endphp
<p style="font-size:16px;color:#152826;margin:0 0 10px;"><strong>{{ $hello }} 👋</strong></p>
<p style="font-size:15px;color:#333;line-height:1.55;margin:0;">schön, dass Sie da sind! Ihr persönliches Kundenportal ist eingerichtet – Ihr direkter Draht zu Ihren Verträgen, Dokumenten und zu uns.</p>
</td></tr>

{{-- 20. Magic Login – großer grüner Button --}}
@if($magicLoginUrl)
<tr><td align="center" style="padding:22px 30px 6px;">
    <a href="{{ $magicLoginUrl }}" style="display:inline-block;background:#2d9c6e;color:#ffffff;font-size:17px;font-weight:bold;text-decoration:none;padding:16px 40px;border-radius:10px;">➜ &nbsp;Jetzt automatisch anmelden</a>
    <p style="font-size:12.5px;color:#666;margin:10px 0 0;">Ein Klick genügt – keine Passworteingabe nötig. Der Link ist 90 Tage gültig<br>und funktioniert nur mit dieser E-Mail-Adresse. Danach bitte eigenes Passwort festlegen.</p>
</td></tr>
@endif

{{-- 3./16. Zugangsdaten als Card mit Label/Wert-Zeilen --}}
<tr><td style="padding:20px 30px 4px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;">
<tr><td style="padding:18px 20px;">
    <p style="font-size:13px;color:#1a3c34;margin:0 0 12px;letter-spacing:.06em;"><strong>IHRE ZUGANGSDATEN</strong></p>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="34" style="font-size:17px;padding:6px 0;">🌍</td>
            <td style="padding:6px 0;"><span style="font-size:12px;color:#666;display:block;">Kundenportal</span>
                <a href="https://portal.dienstly24.de" style="color:#185FA5;font-size:15px;font-weight:bold;text-decoration:none;">portal.dienstly24.de</a></td>
        </tr>
        <tr>
            <td width="34" style="font-size:17px;padding:6px 0;">👤</td>
            <td style="padding:6px 0;"><span style="font-size:12px;color:#666;display:block;">Benutzername</span>
                <span style="font-size:15px;color:#152826;font-weight:bold;">{{ $loginEmail }}</span></td>
        </tr>
    </table>
</td></tr>
</table>
</td></tr>

{{-- 5. Passwort-Box separat und groß --}}
<tr><td style="padding:12px 30px 4px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef7f2;border:1px solid #bfe3d2;border-radius:10px;">
<tr><td align="center" style="padding:18px 20px;">
    @if($mode === 'birthdate')
    <p style="font-size:13px;color:#1a3c34;margin:0 0 6px;letter-spacing:.06em;"><strong>🔑 IHR ERSTES PASSWORT</strong></p>
    <p style="font-size:22px;color:#1a3c34;margin:0 0 6px;font-weight:bold;letter-spacing:.04em;">Ihr Geburtsdatum – TT.MM.JJJJ</p>
    <p style="font-size:13px;color:#4A5C59;margin:0;">Ihr erstes Passwort ist Ihr Geburtsdatum im Format TT.MM.JJJJ.<br>Beispiel für das Format: <strong>01.01.1990</strong> – bitte Ihr eigenes Geburtsdatum mit Punkten eingeben.</p>
    @elseif($mode === 'setlink')
    <p style="font-size:13px;color:#1a3c34;margin:0 0 10px;letter-spacing:.06em;"><strong>🔑 PASSWORT FESTLEGEN</strong></p>
    <a href="{{ $setPasswordUrl }}" style="display:inline-block;background:#1a3c34;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:8px;">Passwort jetzt festlegen</a>
    @else
    <p style="font-size:13px;color:#1a3c34;margin:0 0 6px;letter-spacing:.06em;"><strong>🔑 IHR STARTPASSWORT</strong></p>
    <p style="font-size:22px;color:#1a3c34;margin:0;font-weight:bold;letter-spacing:.05em;">{{ $plainPassword }}</p>
    @endif
</td></tr>
</table>
</td></tr>

{{-- 6. Progress-Steps --}}
<tr><td style="padding:20px 30px 4px;">
    <p style="font-size:13px;color:#1a3c34;margin:0 0 12px;letter-spacing:.06em;"><strong>SO STARTEN SIE</strong></p>
    <table cellpadding="0" cellspacing="0">
        <tr><td width="30" style="font-size:15px;color:#2d9c6e;font-weight:bold;padding:3px 0;">①</td><td style="font-size:14px;color:#333;padding:3px 0;">Anmelden – am schnellsten über den grünen Button oben</td></tr>
        <tr><td style="color:#c2cdc8;padding:0 0 0 4px;font-size:12px;">│</td><td></td></tr>
        <tr><td width="30" style="font-size:15px;color:#2d9c6e;font-weight:bold;padding:3px 0;">②</td><td style="font-size:14px;color:#333;padding:3px 0;">Eigenes Passwort festlegen (unter „Meine Daten")</td></tr>
        <tr><td style="color:#c2cdc8;padding:0 0 0 4px;font-size:12px;">│</td><td></td></tr>
        <tr><td width="30" style="font-size:15px;color:#2d9c6e;font-weight:bold;padding:3px 0;">③</td><td style="font-size:14px;color:#333;padding:3px 0;">Persönliche Daten und Familienmitglieder ergänzen – fertig! ✅</td></tr>
    </table>
</td></tr>

{{-- 7. Was können Sie im Portal tun? --}}
<tr><td style="padding:20px 30px 4px;">
    <p style="font-size:13px;color:#1a3c34;margin:0 0 12px;letter-spacing:.06em;"><strong>WAS KÖNNEN SIE IM PORTAL TUN?</strong></p>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="50%" style="padding:5px 6px 5px 0;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;"><tr><td style="padding:11px 13px;font-size:13.5px;color:#333;">📄 Verträge ansehen</td></tr></table></td>
            <td width="50%" style="padding:5px 0 5px 6px;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;"><tr><td style="padding:11px 13px;font-size:13.5px;color:#333;">📁 Dokumente herunterladen</td></tr></table></td>
        </tr>
        <tr>
            <td style="padding:5px 6px 5px 0;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;"><tr><td style="padding:11px 13px;font-size:13.5px;color:#333;">💬 Support kontaktieren</td></tr></table></td>
            <td style="padding:5px 0 5px 6px;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;"><tr><td style="padding:11px 13px;font-size:13.5px;color:#333;">✉️ Anfragen senden</td></tr></table></td>
        </tr>
        <tr>
            <td style="padding:5px 6px 5px 0;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;"><tr><td style="padding:11px 13px;font-size:13.5px;color:#333;">👨‍👩‍👧 Familie verwalten</td></tr></table></td>
            <td style="padding:5px 0 5px 6px;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;"><tr><td style="padding:11px 13px;font-size:13.5px;color:#333;">👤 Daten selbst pflegen</td></tr></table></td>
        </tr>
    </table>
</td></tr>

{{-- 13. QR-Code --}}
<tr><td align="center" style="padding:20px 30px 4px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;">
<tr>
    <td align="center" width="120" style="padding:16px 8px 16px 20px;">
        <img src="{{ url('/images/portal-qr.png') }}" alt="QR-Code zum Kundenportal" width="96" style="display:block;border-radius:6px;">
    </td>
    <td style="padding:16px 20px 16px 8px;">
        <p style="font-size:14px;color:#152826;margin:0 0 4px;"><strong>📱 Lieber am Smartphone?</strong></p>
        <p style="font-size:13px;color:#666;margin:0;">Scannen Sie den QR-Code mit der Handykamera und melden Sie sich direkt mobil an.</p>
    </td>
</tr>
</table>
</td></tr>

{{-- 14. Sicherheitshinweis --}}
<tr><td style="padding:14px 30px 4px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff8e6;border:1px solid #f0e0b0;border-radius:10px;">
<tr><td style="padding:13px 18px;">
    <p style="font-size:13px;color:#7a5c12;margin:0;"><strong>🔒 Sicherheit:</strong> Wir werden Sie niemals per E-Mail oder Telefon nach Ihrem Passwort fragen. <strong>Passwort vergessen?</strong> Nutzen Sie einfach „Passwort vergessen" auf der Login-Seite.</p>
</td></tr>
</table>
</td></tr>

{{-- Datenschutz-Transparenz (DSGVO Art. 13) --}}
<tr><td style="padding:14px 30px 4px;">
    <p style="font-size:12px;color:#8a938f;line-height:1.55;margin:0;"><strong>Hinweis zum Datenschutz:</strong> Damit wir Ihre Verträge zuverlässig betreuen, empfangen und verarbeiten wir auch Korrespondenz, die Ihre Verträge betrifft – z.&nbsp;B. Nachrichten und Dokumente von Versicherungs- oder Energieunternehmen. Solche Vorgänge ordnen wir Ihrem Kundenkonto zu und stellen sie Ihnen, soweit relevant, in Ihrem Portal bereit. Die Verarbeitung erfolgt ausschließlich zur Vertragsbetreuung; Ihre Daten geben wir nicht unbefugt an Dritte weiter.</p>
</td></tr>

{{-- 11. Support-Box --}}
<tr><td style="padding:16px 30px 22px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#1a3c34;border-radius:10px;">
<tr><td align="center" style="padding:18px 20px;">
    <p style="font-size:14px;color:#ffffff;margin:0 0 4px;"><strong>Brauchen Sie Hilfe?</strong></p>
    <p style="font-size:13px;color:#c8ddd3;margin:0;">Unser Team hilft Ihnen gerne: <a href="mailto:info@dienstly24.de" style="color:#7fd4ab;text-decoration:none;font-weight:bold;">info@dienstly24.de</a></p>
</td></tr>
</table>
</td></tr>

{{-- 10. Footer --}}
<tr><td align="center" style="background:#f8f9fb;border-top:1px solid #e2e8f0;padding:18px 30px;">
    <p style="font-size:13px;color:#152826;margin:0 0 4px;"><strong>Dienstly24</strong> – Ihr Partner für Finanzdienstleistungen</p>
    <p style="font-size:12px;margin:0 0 8px;">
        <a href="https://dienstly24.de" style="color:#185FA5;text-decoration:none;">Website</a> &nbsp;·&nbsp;
        <a href="https://dienstly24.de/impressum" style="color:#185FA5;text-decoration:none;">Impressum</a> &nbsp;·&nbsp;
        <a href="https://dienstly24.de/datenschutz" style="color:#185FA5;text-decoration:none;">Datenschutz</a>
    </p>
    <p style="font-size:11px;color:#9aa39f;margin:0;">Diese E-Mail wurde automatisch versendet. Bitte antworten Sie nicht direkt darauf.</p>
</td></tr>

</table>
</td></tr></table>
</body>
</html>
