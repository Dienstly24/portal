@php
    $typeLabels = [
        'kfz' => ['de' => 'Kfz-Versicherung', 'ar' => 'تأمين السيارة'],
        'strom' => ['de' => 'Stromvertrag', 'ar' => 'عقد الكهرباء'],
        'gas' => ['de' => 'Gasvertrag', 'ar' => 'عقد الغاز'],
        'strom_gas' => ['de' => 'Strom-/Gasvertrag', 'ar' => 'عقد الكهرباء/الغاز'],
        'internet' => ['de' => 'Internetvertrag', 'ar' => 'عقد الإنترنت'],
        'krankenversicherung' => ['de' => 'Gesetzliche Krankenversicherung', 'ar' => 'التأمين الصحي الحكومي'],
    ];
    $label = $typeLabels[$contract->type][$lang] ?? $contract->type;
    $isGkv = $contract->type === 'krankenversicherung';
    $endDate = $contract->end_date ? \Carbon\Carbon::parse($contract->end_date)->format('d.m.Y') : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#185FA5;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '💡 فرصة للتوفير' : '💡 Ihre Sparchance' }}</h1>
</td></tr>
<tr><td style="padding:30px;">

@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $contract->customer?->user?->name }}</strong>،</p>
@if($isGkv)
<p style="font-size:15px;color:#333;">مرّت أكثر من 12 شهر على عضويتك الحالية في التأمين الصحي الحكومي — يعني صار من حقك قانونياً تبديل الصندوق الصحي (§175 SGB V).</p>
<p style="font-size:15px;color:#333;">إذا قدّمنا الطلب خلال هذا الشهر، يصير التأمين الجديد فعّالاً اعتباراً من <strong>{{ $gkvActiveFrom->locale('ar')->translatedFormat('d F Y') }}</strong>.</p>
@elseif($stage === 'first')
<p style="font-size:15px;color:#333;">عقدك ({{ $label }}) ينتهي بتاريخ <strong>{{ $endDate }}</strong> — هذا هو الوقت المثالي لمقارنة العروض والتبديل لعقد أوفر.</p>
@else
<p style="font-size:15px;color:#333;">تذكير: عقدك ({{ $label }}) ينتهي بتاريخ <strong>{{ $endDate }}</strong> والوقت المتاح للتبديل بدأ يضيق. تواصل معنا اليوم لنؤمّن لك أفضل عرض قبل فوات المهلة.</p>
@endif
@if($contract->type === 'kfz')
<p style="font-size:15px;color:#333;">ملاحظة: مهلة فسخ تأمين السيارة شهر واحد قبل نهاية العقد — بعدها يتمدد تلقائياً سنة كاملة.</p>
@endif
@else
@include('emails._greeting', ['greetingCustomer' => $contract->customer])
@if($isGkv)
<p style="font-size:15px;color:#333;">Ihre Mitgliedschaft in Ihrer gesetzlichen Krankenkasse besteht seit über 12 Monaten – damit sind Sie gesetzlich wechselberechtigt (§175 SGB V).</p>
<p style="font-size:15px;color:#333;">Stellen wir den Antrag noch in diesem Monat, ist Ihre neue Kasse ab dem <strong>{{ $gkvActiveFrom->format('d.m.Y') }}</strong> aktiv.</p>
@elseif($stage === 'first')
<p style="font-size:15px;color:#333;">Ihr {{ $label }} läuft am <strong>{{ $endDate }}</strong> aus – jetzt ist der ideale Zeitpunkt, Angebote zu vergleichen und in einen günstigeren Tarif zu wechseln.</p>
@else
<p style="font-size:15px;color:#333;">Erinnerung: Ihr {{ $label }} läuft am <strong>{{ $endDate }}</strong> aus und das Zeitfenster für einen Wechsel wird knapp. Melden Sie sich am besten heute bei uns, damit wir rechtzeitig das beste Angebot für Sie sichern.</p>
@endif
@if($contract->type === 'kfz')
<p style="font-size:15px;color:#333;">Hinweis: Die Kündigungsfrist Ihrer Kfz-Versicherung beträgt einen Monat zum Vertragsende – danach verlängert sich der Vertrag automatisch um ein weiteres Jahr.</p>
@endif
@endif

<table width="100%" cellpadding="0" cellspacing="0" style="background:#E6F1FB;border:1px solid #B6D7F2;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
<strong>{{ $lang === 'ar' ? 'النوع:' : 'Sparte:' }}</strong> {{ $label }}<br>
<strong>{{ $lang === 'ar' ? 'المزوّد:' : 'Anbieter:' }}</strong> {{ $contract->insurer }}<br>
<strong>{{ $lang === 'ar' ? 'رقم العقد:' : 'Vertragsnummer:' }}</strong> {{ $contract->contract_number }}
@if($endDate)<br><strong>{{ $lang === 'ar' ? 'تاريخ الانتهاء:' : 'Vertragsende:' }}</strong> {{ $endDate }}@endif
</td></tr></table>

@if($lang === 'ar')
<p style="font-size:15px;color:#333;">رد على هذا الإيميل أو اتصل فينا — المقارنة والاستشارة مجانية بالكامل.</p>
<p style="font-size:15px;color:#333;">مع أطيب التحيات،<br>فريق Dienstly24</p>
@else
<p style="font-size:15px;color:#333;">Antworten Sie einfach auf diese E-Mail oder rufen Sie uns an – Vergleich und Beratung sind für Sie kostenlos.</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
@endif

</td></tr>
<tr><td style="background:#f8f9fa;padding:18px 30px;border-top:1px solid #e9ecef;">
<p style="font-size:12px;color:#8a8a8a;margin:0;">Dienstly24 · Diese E-Mail wurde über das Kundenportal versendet.</p>
@if(!empty($unsubscribeUrl))
<p style="font-size:12px;color:#8a8a8a;margin:6px 0 0;">
<a href="{{ $unsubscribeUrl }}" style="color:#185FA5;">Abmelden / إلغاء الاشتراك</a>
</p>
@endif
</td></tr>
</table>
</td></tr></table>
</body>
</html>
