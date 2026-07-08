<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#B45309;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '⏰ تنبيه بخصوص عقدك' : '⏰ Erinnerung zu Ihrem Vertrag' }}</h1>
</td></tr>
<tr><td style="padding:30px;">
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $contract->customer?->user?->name }}</strong>،</p>
<p style="font-size:15px;color:#333;">نودّ تذكيرك بأن العقد التالي سينتهي بعد <strong>{{ $days }}</strong> يوم:</p>
@else
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $contract->customer?->user?->name }}</strong>,</p>
<p style="font-size:15px;color:#333;">wir möchten Sie daran erinnern, dass folgender Vertrag in <strong>{{ $days }}</strong> Tagen abläuft:</p>
@endif
<table width="100%" cellpadding="0" cellspacing="0" style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
<strong>{{ $lang === 'ar' ? 'النوع:' : 'Sparte:' }}</strong> {{ $contract->type }}<br>
<strong>{{ $lang === 'ar' ? 'شركة التأمين:' : 'Versicherer:' }}</strong> {{ $contract->insurer }}<br>
<strong>{{ $lang === 'ar' ? 'رقم العقد:' : 'Vertragsnummer:' }}</strong> {{ $contract->contract_number }}<br>
<strong>{{ $lang === 'ar' ? 'تاريخ الانتهاء:' : 'Ablaufdatum:' }}</strong> {{ \Carbon\Carbon::parse($contract->end_date)->format('d.m.Y') }}
</td></tr></table>
@if($lang === 'ar')
<p style="font-size:15px;color:#333;">تواصل معنا لنراجع معاً أفضل الخيارات المتاحة لك قبل انتهاء العقد.</p>
<p style="font-size:15px;color:#333;">مع أطيب التحيات،<br>فريق Dienstly24</p>
@else
<p style="font-size:15px;color:#333;">Kontaktieren Sie uns gerne, damit wir gemeinsam die besten Optionen für Sie prüfen.</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
@endif
</td></tr>
</table>
</td></tr></table>
</body>
</html>
