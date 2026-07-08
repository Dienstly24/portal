<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#185FA5;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $subjectLine }}</h1>
</td></tr>
<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $recipientName }}</strong>,</p>
{{-- Body is entered as plain text in the admin panel; escape it and keep line breaks. --}}
<p style="font-size:15px;color:#333;white-space:pre-line;">{{ $bodyText }}</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>
<tr><td style="background:#f8f9fa;padding:18px 30px;border-top:1px solid #e9ecef;">
<p style="font-size:12px;color:#8a8a8a;margin:0;">Dienstly24 · Diese E-Mail wurde über das Kundenportal versendet.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
