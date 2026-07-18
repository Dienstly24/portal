<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '💬 رسالة جديدة من مستشارك' : '💬 Neue Nachricht von Ihrem Berater' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $customerName }}</strong>،</p>
@if($mode === 'full')
<p style="font-size:15px;color:#333;">أرسل لك مستشارك في Dienstly24 الرسالة التالية:</p>
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="background:#f4f5f7;border-right:3px solid #17A65B;border-radius:8px;padding:16px 18px;font-size:14.5px;color:#333;line-height:1.7;">{!! nl2br(e(trim($customerMessage->body))) !!}</td>
</tr></table>
@if($attachmentCount > 0)
<p style="font-size:13.5px;color:#555;margin-top:14px;">📎 {{ $attachmentCount }} {{ $attachmentCount === 1 ? 'ملف مرفق' : 'ملفات مرفقة' }} – لأسباب تتعلق بحماية البيانات تجدها في بوابة العملاء فقط.</p>
@endif
<p style="font-size:15px;color:#333;margin-top:18px;">يمكنك الرد مباشرة عبر بوابة العملاء:</p>
@else
<p style="font-size:15px;color:#333;">لديك رسالة جديدة من مستشارك في بوابة العملاء. يرجى تسجيل الدخول للاطلاع عليها والرد.</p>
@endif
@else
@include('emails._greeting', ['greetingCustomer' => $customerMessage->customer])
@if($mode === 'full')
<p style="font-size:15px;color:#333;">Ihr Berater bei Dienstly24 hat Ihnen folgende Nachricht geschickt:</p>
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="background:#f4f5f7;border-left:3px solid #17A65B;border-radius:8px;padding:16px 18px;font-size:14.5px;color:#333;line-height:1.7;">{!! nl2br(e(trim($customerMessage->body))) !!}</td>
</tr></table>
@if($attachmentCount > 0)
<p style="font-size:13.5px;color:#555;margin-top:14px;">📎 {{ $attachmentCount }} {{ $attachmentCount === 1 ? 'Anhang liegt' : 'Anhänge liegen' }} aus Datenschutzgründen nur im Kundenportal für Sie bereit.</p>
@endif
<p style="font-size:15px;color:#333;margin-top:18px;">Sie können direkt im Kundenportal antworten:</p>
@else
<p style="font-size:15px;color:#333;">Sie haben eine neue Nachricht von Ihrem Berater im Kundenportal. Bitte melden Sie sich an, um sie zu lesen und zu antworten.</p>
@endif
@endif
<p style="text-align:center;margin:25px 0;">
<a href="{{ $messagesUrl }}" style="background:#17A65B;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;font-weight:bold;">{{ $lang === 'ar' ? 'فتح الرسائل والرد' : 'Nachricht öffnen & antworten' }}</a>
</p>
<p style="font-size:15px;color:#333;">{{ $lang === 'ar' ? 'مع أطيب التحيات،' : 'Mit freundlichen Grüßen' }}<br>{{ $lang === 'ar' ? 'فريق Dienstly24' : 'Ihr Dienstly24 Team' }}</p>
</td></tr>
<tr><td style="background:#f4f5f7;padding:16px 30px;font-size:12px;color:#888;text-align:center;">
Dienstly24 · {{ $lang === 'ar' ? 'هذه رسالة تلقائية من بوابة العملاء' : 'Diese Nachricht wurde automatisch vom Kundenportal versendet' }}
</td></tr>
</table>
</td></tr></table>
</body>
</html>
