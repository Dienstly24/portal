<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:22px 30px;">
<span style="color:#ffffff;font-size:20px;font-weight:bold;">Dienstly<span style="color:#17A65B;">24</span></span>
</td></tr>
<tr><td style="padding:30px;">
<div style="font-size:14.5px;color:#333;line-height:1.7;">{!! nl2br(e(trim($mailBody))) !!}</div>
</td></tr>
<tr><td style="background:#f4f5f7;padding:16px 30px;font-size:12px;color:#888;">
Dienstly24 – Ihr Experte für Versicherungen &amp; Energie<br>
@if($senderName !== '')Ihr Ansprechpartner: {{ $senderName }}<br>@endif
E-Mail: info@dienstly24.de
</td></tr>
</table>
</td></tr></table>
</body>
</html>
