<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
{{-- Bestätigungscode. Tabellenbasiert, Inline-Styles, KEIN SVG. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">

<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Ihr Bestätigungscode 🔐</h1>
</td></tr>

<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;margin:0 0 4px;">Guten Tag {{ $signer->name }},</p>
<p style="font-size:15px;color:#333;">
    bitte geben Sie diesen Code ein, um das Dokument
    „{{ $signatureRequest->title }}“ zu öffnen:
</p>

<p style="text-align:center;margin:26px 0;">
    <span style="display:inline-block;background:#f8f9fb;border:2px solid #17A65B;border-radius:10px;padding:16px 34px;font-size:32px;letter-spacing:8px;font-weight:bold;color:#17191d;">{{ $code }}</span>
</p>

<p style="font-size:13px;color:#666;">
    Der Code gilt 30 Minuten. Geben Sie ihn niemals an Dritte weiter – auch nicht an
    Mitarbeitende von Dienstly24. Haben Sie den Code nicht angefordert, ignorieren Sie diese E-Mail bitte.
</p>

<p style="font-size:15px;color:#333;margin-top:22px;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>

</table></td></tr></table>
</body>
</html>
