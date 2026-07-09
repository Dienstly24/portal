<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#1e3a8a;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '💬 رد جديد على طلبك' : '💬 Neue Antwort auf Ihre Anfrage' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $ticket->customer?->user?->name }}</strong>،</p>
<p style="font-size:15px;color:#333;">وصلك رد جديد من فريقنا على طلبك «<strong>{{ $ticket->subject }}</strong>»:</p>
@else
@include('emails._greeting', ['greetingCustomer' => $ticket->customer])
<p style="font-size:15px;color:#333;">Sie haben eine neue Antwort auf Ihre Anfrage «<strong>{{ $ticket->subject }}</strong>» erhalten:</p>
@endif
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">{{ \Illuminate\Support\Str::limit($replyBody, 300) }}</td></tr>
</table>
<p style="text-align:center;margin:25px 0;">
<a href="{{ route('portal.tickets.show', $ticket->id) }}" style="background:#1e3a8a;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;">{{ $lang === 'ar' ? 'فتح الطلب والرد' : 'Anfrage öffnen & antworten' }}</a>
</p>
<p style="font-size:15px;color:#333;">{{ $lang === 'ar' ? 'مع أطيب التحيات،' : 'Mit freundlichen Grüßen' }}<br>{{ $lang === 'ar' ? 'فريق Dienstly24' : 'Ihr Dienstly24 Team' }}</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
