<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#1e3a8a;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Passwort zurücksetzen</h1>
</td></tr>
<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;">Guten Tag,</p>
<p style="font-size:15px;color:#333;">Sie erhalten diese E-Mail, weil Sie uns darum gebeten haben, Ihre Zugangsdaten für Ihr Konto zurückzusetzen. Bitte klicken Sie auf den folgenden Link und folgen Sie dann den Anweisungen.</p>
<p style="text-align:center;margin:28px 0;">
    <a href="{{ $resetUrl }}" style="background:#1e3a8a;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;">Passwort zurücksetzen</a>
</p>
<p style="font-size:13px;color:#666;">Falls der Button nicht funktioniert, kopieren Sie bitte diesen Link in Ihren Browser:<br>
<a href="{{ $resetUrl }}" style="color:#1e3a8a;word-break:break-all;">{{ $resetUrl }}</a></p>
<p style="font-size:14px;color:#666;">Bitte ignorieren Sie diese E-Mail, falls diese Anfrage nicht von Ihnen stammt. Ihr Passwort ändert sich nur, wenn Sie den obigen Link anklicken und die Anweisungen ausführen.</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>
</table></td></tr></table>
</body>
</html>
