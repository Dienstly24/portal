@php
    $plate = $vehicle?->license_plate;
    $vin = $vehicle?->vin;
    $model = trim(($vehicle?->manufacturer ?? '') . ' ' . ($vehicle?->model ?? ''));
    $endDe = $seasonEnd ? $seasonEnd->format('d.m.Y') : null;
    $startDe = $newSeasonStart ? $newSeasonStart->format('d.m.Y') : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '🛴 لوحة السكوتر الجديدة' : '🛴 Neues E-Scooter-Kennzeichen' }}</h1>
</td></tr>
<tr><td style="padding:30px;">

@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $contract->customer?->user?->name }}</strong>،</p>
<p style="font-size:15px;color:#333;">لوحة تأمين سكوترك الكهربائي {!! $endDe ? 'صالحة حتى <strong>' . $endDe . '</strong>' : '' !!}. اعتباراً من <strong>{{ $startDe ?? '01.03.' }}</strong> يلزم لوحة تأمين <strong>جديدة</strong> حتى تكمل القيادة بشكل قانوني.</p>
<p style="font-size:15px;color:#333;">إذا كان السكوتر <strong>ما زال بحوزتك</strong> وبدك تتابع التأمين، رجاءً رد على هذا الإيميل بتأكيد بسيط — ونحنا منصدرلك لوحة (رقم) جديدة صالحة من <strong>{{ $startDe ?? '01.03.' }}</strong>.</p>
<p style="font-size:15px;color:#333;">إذا لم يعد السكوتر بحوزتك، ما في داعي لأي إجراء — التأمين ينتهي تلقائياً في نهاية شباط (بدون حاجة لإلغاء).</p>
@else
@include('emails._greeting', ['greetingCustomer' => $contract->customer])
<p style="font-size:15px;color:#333;">Das Versicherungskennzeichen Ihres E-Scooters {!! $endDe ? 'ist noch bis zum <strong>' . $endDe . '</strong> gueltig' : '' !!}. Ab dem <strong>{{ $startDe ?? '01.03.' }}</strong> benoetigen Sie ein <strong>neues</strong> Kennzeichen, um weiter legal fahren zu duerfen.</p>
<p style="font-size:15px;color:#333;">Wenn Sie den E-Scooter <strong>weiterhin nutzen</strong> und versichert lassen moechten, antworten Sie bitte kurz auf diese E-Mail. Wir stellen Ihnen dann ein neues Kennzeichen aus, gueltig ab dem <strong>{{ $startDe ?? '01.03.' }}</strong>.</p>
<p style="font-size:15px;color:#333;">Falls Sie den E-Scooter nicht mehr haben, brauchen Sie nichts zu tun – die Versicherung endet automatisch Ende Februar (bedarf keiner Kuendigung).</p>
@endif

<table width="100%" cellpadding="0" cellspacing="0" style="background:#E6F1FB;border:1px solid #B6D7F2;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
<strong>{{ $lang === 'ar' ? 'المزوّد:' : 'Versicherer:' }}</strong> {{ $contract->insurer }}<br>
@if($plate)<strong>{{ $lang === 'ar' ? 'رقم اللوحة الحالية:' : 'Kennzeichen (aktuell):' }}</strong> {{ $plate }}<br>@endif
@if($vin)<strong>{{ $lang === 'ar' ? 'رقم الشاسي (FIN):' : 'Fahrgestellnummer (FIN):' }}</strong> {{ $vin }}<br>@endif
@if($model !== '')<strong>{{ $lang === 'ar' ? 'الطراز:' : 'Modell:' }}</strong> {{ $model }}<br>@endif
@if($endDe)<strong>{{ $lang === 'ar' ? 'انتهاء التأمين:' : 'Versicherungsende:' }}</strong> {{ $endDe }}@endif
</td></tr></table>

@if($lang === 'ar')
<p style="font-size:15px;color:#333;">للاستفسار اتصل فينا أو رد على هذا الإيميل — نحنا جاهزين لمساعدتك.</p>
<p style="font-size:15px;color:#333;">مع أطيب التحيات،<br>فريق Dienstly24</p>
@else
<p style="font-size:15px;color:#333;">Bei Fragen rufen Sie uns an oder antworten Sie auf diese E-Mail – wir helfen Ihnen gerne weiter.</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
@endif

</td></tr>
<tr><td style="background:#f8f9fa;padding:18px 30px;border-top:1px solid #e9ecef;">
<p style="font-size:12px;color:#8a8a8a;margin:0;">Dienstly24 · Diese E-Mail wurde über das Kundenportal versendet.</p>
@if(!empty($unsubscribeUrl))
<p style="font-size:12px;color:#8a8a8a;margin:6px 0 0;">
<a href="{{ $unsubscribeUrl }}" style="color:#17A65B;">Abmelden / إلغاء الاشتراك</a>
</p>
@endif
</td></tr>
</table>
</td></tr></table>
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4) --}}
@stack('cspScripts')
</body>
</html>
