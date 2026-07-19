@php
    $ar = ($lang ?? 'de') === 'ar';
    if ($ar) {
        $hello = trim($customerName) !== '' ? 'مرحباً ' . $customerName : 'مرحباً';
    } else {
        $hello = 'Hallo';
        if ($customer->gender === 'male') { $hello = 'Hallo Herr ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
        elseif ($customer->gender === 'female') { $hello = 'Hallo Frau ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
        elseif (trim($customerName) !== '') { $hello = 'Hallo ' . $customerName; }
    }
@endphp
@if($ar)
أهلاً بك في Dienstly24
=========================

{{ $hello }},
بوابة العملاء الخاصة بك جاهزة الآن - طريقك المباشر إلى عقودك ومستنداتك وإلينا.
@if($magicLoginUrl)

تسجيل الدخول تلقائياً الآن
نقرة واحدة تكفي، صالح 90 يوماً، فقط بهذا البريد.
بعدها يمكنك تعيين كلمة مرور خاصة بك:
{!! $magicLoginUrl !!}
@endif

بيانات الدخول الخاصة بك
-----------------
البوابة:  {{ $portalBase }}
البريد:   {{ $loginEmail }}
@if($mode === 'birthdate')
كلمة المرور: تاريخ ميلادك بالصيغة يوم.شهر.سنة (مثال: 01.01.1990)
@elseif($mode === 'setlink')
تعيين كلمة المرور: {!! $setPasswordUrl !!}
@else
كلمة المرور الأولية: {{ $plainPassword }}
@endif

ماذا يمكنك أن تفعل في البوابة؟
------------------------------
- العقود:    {{ $portalBase }}/contracts
- المستندات: {{ $portalBase }}/documents
- الدعم:     {{ $portalBase }}/tickets/create
- بياناتي:   {{ $portalBase }}/profile

هل تحتاج مساعدة؟
-------------------
فريقنا يسعده مساعدتك: info@dienstly24.de
إرسال طلب: {!! $supportUrl !!}

الأمان: لن نطلب منك كلمة مرورك عبر البريد أو الهاتف أبداً.
نسيت كلمة المرور؟ استخدم "نسيت كلمة المرور" في صفحة الدخول.

--
Dienstly24 - شريكك للخدمات المالية
Website:     https://dienstly24.de
Impressum:   {{ $portalBase }}/impressum
Datenschutz: {{ $portalBase }}/datenschutz

أُرسلت هذه الرسالة تلقائياً.
@else
Willkommen bei Dienstly24
=========================

{{ $hello }},
Ihr Kundenportal ist jetzt bereit - Ihr direkter Draht zu Vertraegen,
Dokumenten und zu uns.
@if($magicLoginUrl)

JETZT AUTOMATISCH ANMELDEN
Ein Klick genuegt, 90 Tage gueltig, nur mit dieser E-Mail-Adresse.
Danach koennen Sie ein eigenes Passwort festlegen:
{!! $magicLoginUrl !!}
@endif

IHRE ZUGANGSDATEN
-----------------
Portal:  {{ $portalBase }}
E-Mail:  {{ $loginEmail }}
@if($mode === 'birthdate')
Passwort: Ihr Geburtsdatum im Format TT.MM.JJJJ (Beispiel: 01.01.1990)
@elseif($mode === 'setlink')
Passwort festlegen: {!! $setPasswordUrl !!}
@else
Startpasswort: {{ $plainPassword }}
@endif

WAS KOENNEN SIE IM PORTAL TUN?
------------------------------
- Vertraege:   {{ $portalBase }}/contracts
- Dokumente:   {{ $portalBase }}/documents
- Support:     {{ $portalBase }}/tickets/create
- Meine Daten: {{ $portalBase }}/profile

BRAUCHEN SIE HILFE?
-------------------
Unser Team hilft Ihnen gerne: info@dienstly24.de
Anfrage senden: {!! $supportUrl !!}

SICHERHEIT: Wir fragen niemals per E-Mail oder Telefon nach Ihrem Passwort.
Passwort vergessen? Nutzen Sie "Passwort vergessen" auf der Login-Seite.

--
Dienstly24 - Ihr Partner fuer Finanzdienstleistungen
Website:     https://dienstly24.de
Impressum:   {{ $portalBase }}/impressum
Datenschutz: {{ $portalBase }}/datenschutz

Diese E-Mail wurde automatisch versendet.
@endif
