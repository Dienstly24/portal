{{-- Bestaetigung der E-Mail-Adresse nach der Selbst-Registrierung (SEC-1).
     Tabellenbasiert, Inline-Styles, KEIN SVG (Gmail/Outlook entfernen es). --}}
<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#131A17;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Nur noch ein Schritt &#9989;</h1>
</td>
</tr>
<tr>
<td style="padding:30px;">
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $name }}</strong>,</p>
<p style="font-size:15px;color:#333;">
    vielen Dank fuer Ihre Anmeldung im Dienstly24-Kundenportal. Bitte
    bestaetigen Sie Ihre E-Mail-Adresse - erst danach wird Ihr Konto
    angelegt und Sie koennen sich anmelden.
</p>

<p style="text-align:center;margin:28px 0;">
    <a href="{{ $verifyUrl }}" style="background:#17A65B;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:bold;display:inline-block;">E-Mail-Adresse bestaetigen</a>
</p>

<p style="font-size:13px;color:#666;">
    Der Link ist {{ $validHours }} Stunden gueltig und funktioniert nur
    einmal. Falls der Button nicht funktioniert, kopieren Sie bitte diese
    Adresse in Ihren Browser:<br>
    <a href="{{ $verifyUrl }}" style="color:#17A65B;word-break:break-all;">{{ $verifyUrl }}</a>
</p>

<p style="font-size:13px;color:#8A6D1F;background:#F6F0DC;border:1px solid #E0DCD0;border-radius:8px;padding:12px 14px;line-height:1.6;">
    <strong>Sie haben sich nicht angemeldet?</strong> Dann ignorieren Sie
    diese E-Mail einfach. Ohne Ihre Bestaetigung entsteht kein Konto, und
    Ihre Angaben werden nach Ablauf des Links automatisch geloescht.
</p>

<p style="font-size:14px;color:#333;margin-top:25px;">Mit freundlichen Gruessen<br><strong>Ihr Dienstly24-Team</strong></p>
</td>
</tr>
<tr>
<td style="background:#F6F3EA;padding:18px 30px;font-size:12px;color:#666;border-top:1px solid #E0DCD0;">
    Dienstly24 &middot; Versicherungs- und Energiemakler<br>
    Diese E-Mail wurde automatisch erzeugt.
</td>
</tr>
</table>
</td></tr>
</table>
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
