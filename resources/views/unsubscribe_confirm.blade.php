<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $lang === 'ar' ? 'تأكيد إلغاء الاشتراك' : 'Abmeldung bestätigen' }}</title>
</head>
<body style="margin:0;padding:0;background:#F7F5EF;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:60px 20px;"><tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:100%;">
<tr><td style="background:#185FA5;padding:22px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:20px;">Dienstly24</h1>
</td></tr>
<tr><td style="padding:30px;text-align:center;">
<div style="font-size:40px;margin-bottom:12px;">✉️</div>
@if($lang === 'ar')
<p style="font-size:16px;color:#333;margin:0 0 10px;"><strong>هل تريد إلغاء الاشتراك من رسائلنا التسويقية؟</strong></p>
<p style="font-size:14px;color:#666;margin:0 0 22px;">اضغط الزر للتأكيد. رسائل الخدمة المتعلقة بعقودك تبقى تصلك كالمعتاد.</p>
@else
<p style="font-size:16px;color:#333;margin:0 0 10px;"><strong>Möchten Sie sich von unseren Marketing-E-Mails abmelden?</strong></p>
<p style="font-size:14px;color:#666;margin:0 0 22px;">Bitte bestätigen Sie mit einem Klick. Service-Mails zu Ihren Verträgen erhalten Sie weiterhin.</p>
@endif
<form method="POST" action="{{ route('unsubscribe.oneclick', $token) }}" style="margin:0;">
<button type="submit" style="display:inline-block;background:#185FA5;color:#ffffff;border:0;border-radius:8px;padding:13px 28px;font-size:15px;font-weight:700;cursor:pointer;">
{{ $lang === 'ar' ? 'تأكيد إلغاء الاشتراك' : 'Abmeldung bestätigen' }}
</button>
</form>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
