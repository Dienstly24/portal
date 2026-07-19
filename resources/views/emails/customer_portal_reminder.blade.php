<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? 'بوابتك في انتظارك 🔔' : 'Ihr Portal wartet auf Sie 🔔' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">مرحباً <strong>{{ $customerName }}</strong>،</p>
<p style="font-size:15px;color:#333;">لاحظنا أنك لم تسجّل الدخول بعد إلى بوابة العملاء الخاصة بك. من خلال البوابة يمكنك الاطلاع على عقودك، إرسال طلباتك، وإكمال بياناتك وبيانات عائلتك بكل سهولة.</p>
<p style="text-align:center;margin:25px 0;"><a href="https://portal.dienstly24.de" style="background:#17A65B;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;">تسجيل الدخول الآن</a></p>
<p style="font-size:14px;color:#666;">بيانات الدخول موجودة في رسالة الترحيب التي وصلتك سابقاً. إذا واجهت أي مشكلة، لا تتردد بالتواصل معنا.</p>
<p style="font-size:15px;color:#333;">مع أطيب التحيات،<br>فريق Dienstly24</p>
@else
@include('emails._greeting', ['greetingName' => $customerName])
<p style="font-size:15px;color:#333;">wir haben bemerkt, dass Sie sich noch nicht in Ihrem Kundenportal angemeldet haben. Dort können Sie Ihre Verträge einsehen, Anfragen stellen und ganz einfach Ihre Daten und Familienmitglieder vervollständigen.</p>
<p style="text-align:center;margin:25px 0;"><a href="https://portal.dienstly24.de" style="background:#17A65B;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;">Jetzt einloggen</a></p>
<p style="font-size:14px;color:#666;">Ihre Zugangsdaten finden Sie in unserer Willkommens-E-Mail. Bei Fragen helfen wir gerne weiter.</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
@endif
</td></tr>
</table>
</td></tr></table>
</body>
</html>
