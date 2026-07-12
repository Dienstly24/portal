<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $lang === 'ar' ? 'تم إلغاء الاشتراك' : 'Abmeldung erfolgreich' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:60px 20px;"><tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:100%;">
<tr><td style="background:#185FA5;padding:22px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:20px;">Dienstly24</h1>
</td></tr>
<tr><td style="padding:30px;text-align:center;">
<div style="font-size:40px;margin-bottom:12px;">✅</div>
@if($lang === 'ar')
<p style="font-size:16px;color:#333;margin:0 0 10px;"><strong>تم إلغاء اشتراكك بنجاح.</strong></p>
<p style="font-size:14px;color:#666;margin:0;">لن تصلك بعد الآن رسائل تسويقية أو عروض منّا. رسائل الخدمة المتعلقة بعقودك (مثل استعادة كلمة المرور أو الرد على تذاكرك) ستبقى تصلك كالمعتاد.</p>
@else
<p style="font-size:16px;color:#333;margin:0 0 10px;"><strong>Sie wurden erfolgreich abgemeldet.</strong></p>
<p style="font-size:14px;color:#666;margin:0;">Sie erhalten von uns keine Marketing- oder Angebots-E-Mails mehr. Service-Mails zu Ihren Verträgen (z.&nbsp;B. Passwort-Zurücksetzen oder Ticket-Antworten) erhalten Sie weiterhin.</p>
@endif
</td></tr>
</table>
</td></tr></table>
</body>
</html>
