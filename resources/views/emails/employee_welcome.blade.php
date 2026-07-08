<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#1e3a8a;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Willkommen bei Dienstly24 👋</h1>
</td>
</tr>
<tr>
<td style="padding:30px;">
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $employeeName }}</strong>,</p>
<p style="font-size:15px;color:#333;">Ihr Mitarbeiter-Konto wurde erstellt. Hier sind Ihre Zugangsdaten:</p>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
<strong>Login-URL:</strong> <a href="https://admin.dienstly24.de/admin" style="color:#1e3a8a;">admin.dienstly24.de/admin</a><br><br>
<strong>E-Mail:</strong> {{ $employeeEmail }}<br><br>
<strong>Passwort:</strong> {{ $plainPassword }}
</td></tr>
</table>
<p style="font-size:13px;color:#b91c1c;"><strong>Wichtig:</strong> Bitte ändern Sie Ihr Passwort nach dem ersten Login.</p>
@if(count($permissions) > 0)
<p style="font-size:15px;color:#333;margin-top:20px;"><strong>Ihre Berechtigungen:</strong></p>
<ul style="font-size:14px;color:#333;padding-left:20px;">
@foreach($permissions as $perm)
<li style="margin-bottom:5px;">{{ $perm }}</li>
@endforeach
</ul>
@endif
<p style="font-size:15px;color:#333;margin-top:25px;">Bei Fragen wenden Sie sich bitte an die Geschäftsleitung.</p>
<p style="font-size:15px;color:#333;">Ihr Dienstly24 Team</p>
</td>
</tr>
<tr>
<td style="background:#f8fafc;padding:15px 30px;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;color:#94a3b8;margin:0;">Dienstly24 · Hamburg · Diese E-Mail wurde automatisch generiert.</p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
