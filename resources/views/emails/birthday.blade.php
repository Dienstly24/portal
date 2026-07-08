<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:linear-gradient(135deg,#1e3a8a,#3b5bd9);padding:35px 30px;text-align:center;">
<div style="font-size:44px;">🎂🎉</div>
<h1 style="color:#ffffff;margin:10px 0 0;font-size:24px;">{{ $lang === 'ar' ? 'عيد ميلاد سعيد!' : 'Herzlichen Glückwunsch!' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $recipientName }}</strong>،</p>
@if($isSelf)
<p style="font-size:15px;color:#333;">يتقدم فريق Dienstly24 بأحر التهاني بمناسبة عيد ميلادك! 🎈 نتمنى لك عاماً مليئاً بالصحة والسعادة والنجاح.</p>
@else
<p style="font-size:15px;color:#333;">اليوم عيد ميلاد <strong>{{ $birthdayName }}</strong>! 🎈 يتقدم فريق Dienstly24 بأحر التهاني، ونتمنى له/لها عاماً مليئاً بالصحة والسعادة.</p>
@endif
<p style="font-size:15px;color:#333;">شكراً لثقتك بنا — نحن دائماً في خدمتك وخدمة عائلتك.</p>
<p style="font-size:15px;color:#333;">مع أطيب التمنيات،<br>فريق Dienstly24</p>
@else
<p style="font-size:15px;color:#333;">Liebe/r <strong>{{ $recipientName }}</strong>,</p>
@if($isSelf)
<p style="font-size:15px;color:#333;">das gesamte Team von Dienstly24 gratuliert Ihnen herzlich zum Geburtstag! 🎈 Wir wünschen Ihnen ein Jahr voller Gesundheit, Glück und Erfolg.</p>
@else
<p style="font-size:15px;color:#333;">heute hat <strong>{{ $birthdayName }}</strong> Geburtstag! 🎈 Das gesamte Team von Dienstly24 gratuliert herzlich und wünscht ein Jahr voller Gesundheit und Glück.</p>
@endif
<p style="font-size:15px;color:#333;">Vielen Dank für Ihr Vertrauen — wir sind jederzeit für Sie und Ihre Familie da.</p>
<p style="font-size:15px;color:#333;">Herzliche Grüße<br>Ihr Dienstly24 Team</p>
@endif
</td></tr>
</table>
</td></tr></table>
</body>
</html>
