@php
    $renewalDe = $renewalDate->format('d.m.Y');
    $cancelDe = $lastCancellationDate->format('d.m.Y');
    $nummer = $contract->contract_number;
    $beitrag = $contract->premium_amount
        ? number_format((float) $contract->premium_amount, 2, ',', '.') . ' €'
        : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" @if($lang === 'ar') dir="rtl" @endif>
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ $lang === 'ar' ? '🆘 عقد المساعدة على الطريق' : '🆘 Ihr Schutzbrief / Mobilclub' }}</h1>
</td></tr>
<tr><td style="padding:30px;">

@if($lang === 'ar')
<p style="font-size:15px;color:#333;">عزيزنا <strong>{{ $contract->customer?->user?->name }}</strong>،</p>
<p style="font-size:15px;color:#333;">نحب نذكّرك إنه عقد المساعدة على الطريق (Schutzbrief) تبعك مع <strong>{{ $contract->insurer }}</strong> <strong>بيتجدد تلقائياً لسنة إضافية بتاريخ {{ $renewalDe }}</strong>.</p>
<p style="font-size:15px;color:#333;"><strong>إذا بدك تكمل معنا — ما في داعي لأي إجراء.</strong> العقد بيتجدد لحاله وبتضل مغطّى بدون انقطاع.</p>
@else
@include('emails._greeting', ['greetingCustomer' => $contract->customer])
<p style="font-size:15px;color:#333;">wir moechten Sie rechtzeitig informieren: Ihr Schutzbrief bei <strong>{{ $contract->insurer }}</strong> <strong>verlaengert sich am {{ $renewalDe }} automatisch um ein weiteres Jahr</strong>.</p>
<p style="font-size:15px;color:#333;"><strong>Wenn Sie den Schutz behalten moechten, brauchen Sie nichts zu tun.</strong> Der Vertrag laeuft ohne Unterbrechung weiter.</p>
@endif

{{-- Vertragsdaten --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#FEF3C7;border:1px solid #F0D48A;border-radius:8px;margin:15px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
<strong>{{ $lang === 'ar' ? 'المزوّد:' : 'Anbieter:' }}</strong> {{ $contract->insurer }}<br>
@if($tierLabel)<strong>{{ $lang === 'ar' ? 'نوع العضوية:' : 'Mitgliedschaft:' }}</strong> {{ $tierLabel }}<br>@endif
@if($nummer)<strong>{{ $lang === 'ar' ? 'رقم العضوية:' : 'Mitglieds-/Vertragsnummer:' }}</strong> {{ $nummer }}<br>@endif
@if($beitrag)<strong>{{ $lang === 'ar' ? 'الاشتراك السنوي:' : 'Jahresbeitrag:' }}</strong> {{ $beitrag }}<br>@endif
<strong>{{ $lang === 'ar' ? 'تاريخ التجديد:' : 'Verlaengerung am:' }}</strong> {{ $renewalDe }}
</td></tr></table>

{{-- Leistungen: was der Kunde aufgeben wuerde --}}
@if($lang === 'ar')
<p style="font-size:15px;color:#333;margin-top:22px;"><strong>شو بيقدملك هالعقد؟</strong> منحب نذكّرك بأهميته قبل ما تاخد قرارك:</p>
<ul style="font-size:15px;color:#333;padding-{{ 'right' }}:20px;margin:10px 0;">
<li style="margin-bottom:6px;"><strong>مساعدة عند العطل (Pannenhilfe):</strong> بيجيك فني على الطريق ويصلّح العطل بمكانه إذا ممكن.</li>
<li style="margin-bottom:6px;"><strong>سحب السيارة (Abschleppen):</strong> إذا ما انصلحت، بينسحبوها للكراج على حسابهم.</li>
<li style="margin-bottom:6px;"><strong>متابعة الرحلة أو العودة:</strong> سيارة بديلة، أو تذكرة قطار، أو فندق لتوصل لبيتك أو تكمّل سفرك.</li>
<li style="margin-bottom:6px;"><strong>تغطية داخل ألمانيا وأوروبا</strong> — بما فيها إجازات السفر بالسيارة.</li>
<li style="margin-bottom:6px;"><strong>مساعدة عند البطارية الفاضية، الدولاب المبنشر، أو ضياع المفتاح.</strong></li>
</ul>
<p style="font-size:15px;color:#333;">بدون هالعقد، سحب سيارة واحد بيكلّف عادةً <strong>مئات اليوروهات</strong> — بينما الاشتراك السنوي أرخص بكثير.</p>
@else
<p style="font-size:15px;color:#333;margin-top:22px;"><strong>Was Ihr Schutzbrief leistet</strong> – damit Sie wissen, worauf Sie im Fall einer Kuendigung verzichten:</p>
<ul style="font-size:15px;color:#333;padding-left:20px;margin:10px 0;">
<li style="margin-bottom:6px;"><strong>Pannenhilfe vor Ort:</strong> Ein Techniker kommt und repariert, wenn moeglich, direkt an der Strasse.</li>
<li style="margin-bottom:6px;"><strong>Abschleppdienst:</strong> Laesst sich das Fahrzeug nicht reparieren, wird es in die Werkstatt geschleppt.</li>
<li style="margin-bottom:6px;"><strong>Weiter- oder Rueckreise:</strong> Mietwagen, Bahnticket oder Uebernachtung, damit Sie nach Hause oder ans Ziel kommen.</li>
<li style="margin-bottom:6px;"><strong>Schutz in Deutschland und Europa</strong> – auch im Urlaub mit dem Auto.</li>
<li style="margin-bottom:6px;"><strong>Hilfe bei leerer Batterie, Reifenpanne oder Schluesselverlust.</strong></li>
</ul>
<p style="font-size:15px;color:#333;">Ein einziger Abschleppvorgang kostet ohne Schutzbrief schnell <strong>mehrere hundert Euro</strong> – deutlich mehr als der Jahresbeitrag.</p>
@endif

{{-- Kuendigungsfrist: klar und handlungsleitend --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#FDECEC;border:1px solid #F5C2C2;border-radius:8px;margin:20px 0;">
<tr><td style="padding:15px 20px;font-size:14px;color:#333;">
@if($lang === 'ar')
<strong>إذا ما بدك تجدّد:</strong> آخر موعد للإلغاء هو <strong>{{ $cancelDe }}</strong> (٣ أشهر قبل التجديد). بعد هالتاريخ بيتمدد العقد سنة كاملة.<br>
رجاءً <strong>رد على هالإيميل</strong> قبل {{ $cancelDe }} ونحنا منساعدك بالإلغاء خطوة بخطوة.
@else
<strong>Falls Sie nicht verlaengern moechten:</strong> Die Kuendigung muss uns spaetestens am <strong>{{ $cancelDe }}</strong> vorliegen (3 Monate vor der Verlaengerung). Danach laeuft der Vertrag ein weiteres Jahr.<br>
Antworten Sie einfach <strong>auf diese E-Mail</strong> – wir uebernehmen die Kuendigung fuer Sie.
@endif
</td></tr></table>

@if($lang === 'ar')
<p style="font-size:15px;color:#333;">في أي سؤال؟ رد على هالإيميل أو اتصل فينا — نحنا جاهزين لمساعدتك.</p>
<p style="font-size:15px;color:#333;">مع أطيب التحيات،<br>فريق Dienstly24</p>
@else
<p style="font-size:15px;color:#333;">Bei Fragen antworten Sie auf diese E-Mail oder rufen Sie uns an – wir beraten Sie gerne.</p>
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
