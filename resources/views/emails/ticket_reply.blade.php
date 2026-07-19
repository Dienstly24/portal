<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '💬 رد جديد على طلبك' : '💬 Neue Antwort auf Ihre Anfrage' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $ticket->customer?->user?->name }}</strong>،</p>
<p style="font-size:15px;color:#333;">لديك رسالة جديدة في بوابة العملاء. يرجى تسجيل الدخول للاطلاع عليها.</p>
@else
@include('emails._greeting', ['greetingCustomer' => $ticket->customer])
{{-- Bewusst KEINE Nachrichtendetails per E-Mail (Review Punkt 10) --}}
<p style="font-size:15px;color:#333;">Sie haben eine neue Nachricht im Kundenportal. Bitte melden Sie sich an.</p>
@endif
<p style="text-align:center;margin:25px 0;">
<a href="{{ route('portal.tickets.show', $ticket->id) }}" style="background:#17A65B;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;">{{ $lang === 'ar' ? 'فتح الطلب والرد' : 'Anfrage öffnen & antworten' }}</a>
</p>
<p style="font-size:15px;color:#333;">{{ $lang === 'ar' ? 'مع أطيب التحيات،' : 'Mit freundlichen Grüßen' }}<br>{{ $lang === 'ar' ? 'فريق Dienstly24' : 'Ihr Dienstly24 Team' }}</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
