<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#131A17;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Willkommen bei Dienstly24 &#128075;</h1>
</td>
</tr>
<tr>
<td style="padding:30px;">
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $employeeName }}</strong>,</p>
<p style="font-size:15px;color:#333;">Ihr Mitarbeiter-Konto wurde angelegt. Bitte legen Sie ueber den folgenden Button Ihr persoenliches Passwort fest.</p>

<p style="text-align:center;margin:28px 0;">
    <a href="{{ $setPasswordUrl }}" style="background:#17A65B;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:bold;display:inline-block;">Passwort festlegen</a>
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#F6F3EA;border:1px solid #E0DCD0;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;line-height:1.7;">
<strong>Anmeldeseite:</strong> <a href="https://admin.dienstly24.de/admin" style="color:#17A65B;">admin.dienstly24.de/admin</a><br>
<strong>Ihre Anmelde-Adresse:</strong> {{ $employeeEmail }}
</td></tr>
</table>

<p style="font-size:13px;color:#666;">Der Link ist {{ $validDays }} Tage gueltig und funktioniert nur einmal fuer dieses Konto. Falls der Button nicht funktioniert, kopieren Sie bitte diese Adresse in Ihren Browser:<br>
<a href="{{ $setPasswordUrl }}" style="color:#17A65B;word-break:break-all;">{{ $setPasswordUrl }}</a></p>

<p style="font-size:13px;color:#8A6D1F;background:#F6F0DC;border:1px solid #E0DCD0;border-radius:8px;padding:12px 14px;line-height:1.6;">
<strong>Bitte beachten:</strong> Wir versenden grundsaetzlich keine Passwoerter per E-Mail. Ihr Passwort kennen nur Sie. Geben Sie es niemals weiter - auch nicht an Kolleginnen oder Kollegen.
</p>

@if(count($permissions) > 0)
<p style="font-size:15px;color:#333;margin-top:20px;"><strong>Ihre Berechtigungen:</strong></p>
<ul style="font-size:14px;color:#333;padding-left:20px;">
@foreach($permissions as $perm)
<li style="margin-bottom:5px;">{{ $perm }}</li>
@endforeach
</ul>
@endif

<p style="font-size:15px;color:#333;margin-top:25px;">Bei Fragen wenden Sie sich bitte an die Geschaeftsleitung.</p>
<p style="font-size:15px;color:#333;">Ihr Dienstly24 Team</p>
</td>
</tr>
<tr>
<td style="background:#F6F3EA;padding:15px 30px;border-top:1px solid #E0DCD0;">
<p style="font-size:12px;color:#6E7A71;margin:0;">Dienstly24 &middot; Diese E-Mail wurde automatisch erzeugt.</p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
