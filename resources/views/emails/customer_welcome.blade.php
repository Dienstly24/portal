<!DOCTYPE html>
@php $ar = ($lang ?? 'de') === 'ar'; @endphp
<html lang="{{ $ar ? 'ar' : 'de' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
{{-- E-Mail-HTML: tabellenbasiert, Inline-Styles, absolute URLs, KEIN SVG
     (Gmail/Outlook entfernen SVG) – bewusst Emoji/Unicode als Icons.
     Zweisprachig DE/AR mit RTL (Audit I18N-1). Alle Kundenlinks zeigen auf
     {{ $portalBase }}. Markenfarben: Graphit #17191d, Smaragd #17A65B. --}}
<body style="margin:0;padding:0;background:#f0f2f1;font-family:Arial,Helvetica,sans-serif;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f1;padding:8px;"><tr><td align="center">
<table cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">

{{-- Hero-Band mit Textmarke (kein Bild) --}}
<tr><td align="center" style="background:#17191d;padding:20px 30px;">
    <p style="color:#17A65B;margin:0 0 4px;font-size:15px;font-weight:bold;letter-spacing:.04em;">DIENSTLY24</p>
    <h1 style="color:#ffffff;margin:0 0 3px;font-size:20px;">{{ $ar ? 'أهلاً بك في Dienstly24 👋' : 'Willkommen bei Dienstly24 👋' }}</h1>
    <p style="color:#c6cbd3;margin:0;font-size:13.5px;">{{ $ar ? 'بوابة العملاء الخاصة بك جاهزة الآن.' : 'Ihr Kundenportal ist jetzt bereit.' }}</p>
</td></tr>

{{-- Anrede – kurz --}}
<tr><td style="padding:14px 30px 0;text-align:{{ $ar ? 'right' : 'left' }};">
@php
    if ($ar) {
        $hello = trim($customerName) !== '' ? 'مرحباً ' . $customerName : 'مرحباً';
    } else {
        $hello = 'Hallo';
        if ($customer->gender === 'male') { $hello = 'Hallo Herr ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
        elseif ($customer->gender === 'female') { $hello = 'Hallo Frau ' . (\Illuminate\Support\Str::afterLast(trim($customerName), ' ') ?: $customerName); }
        elseif (trim($customerName) !== '') { $hello = 'Hallo ' . $customerName; }
    }
@endphp
<p style="font-size:14.5px;color:#152826;margin:0;"><strong>{{ $hello }} 👋</strong> – {{ $ar ? 'طريقك المباشر إلى عقودك ومستنداتك وإلينا.' : 'Ihr direkter Draht zu Verträgen, Dokumenten und zu uns.' }}</p>
</td></tr>

{{-- Magic Login – großer grüner Button --}}
@if($magicLoginUrl)
<tr><td align="center" style="padding:14px 30px 4px;">
    <a href="{{ $magicLoginUrl }}" style="display:inline-block;background:#17A65B;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:none;padding:13px 36px;border-radius:10px;">{{ $ar ? 'تسجيل الدخول تلقائياً الآن ➜' : '➜  Jetzt automatisch anmelden' }}</a>
    <p style="font-size:11.5px;color:#666;margin:6px 0 0;">{{ $ar ? 'نقرة واحدة تكفي – صالح 90 يوماً، فقط بهذا البريد. بعدها عيّن كلمة مرور خاصة بك.' : 'Ein Klick genügt – 90 Tage gültig, nur mit dieser E-Mail-Adresse. Danach eigenes Passwort festlegen.' }}</p>
</td></tr>
@endif

{{-- Zugangsdaten + Passwort in EINER kompakten Card --}}
<tr><td style="padding:12px 30px 2px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;">
<tr><td style="padding:13px 18px;text-align:{{ $ar ? 'right' : 'left' }};">
    <p style="font-size:12px;color:#17191d;margin:0 0 8px;letter-spacing:.06em;"><strong>{{ $ar ? 'بيانات الدخول الخاصة بك' : 'IHRE ZUGANGSDATEN' }}</strong></p>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="28" style="font-size:15px;padding:3px 0;">🌍</td>
            <td style="padding:3px 0;font-size:13.5px;"><a href="{{ $portalBase }}" style="color:#17A65B;font-weight:bold;text-decoration:none;">portal.dienstly24.de</a></td>
        </tr>
        <tr>
            <td width="28" style="font-size:15px;padding:3px 0;">👤</td>
            <td style="padding:3px 0;font-size:13.5px;color:#152826;"><strong>{{ $loginEmail }}</strong></td>
        </tr>
        <tr>
            <td width="28" style="font-size:15px;padding:3px 0;vertical-align:top;">🔑</td>
            <td style="padding:3px 0;font-size:13px;color:#152826;">
                @if($mode === 'birthdate')
                    <strong>{{ $ar ? 'كلمة مرورك الأولى هي تاريخ ميلادك بالصيغة يوم.شهر.سنة' : 'Ihr erstes Passwort ist Ihr Geburtsdatum im Format TT.MM.JJJJ' }}</strong><br>
                    <span style="color:#666;font-size:12px;">{{ $ar ? 'مثال: 01.01.1990 – أدخل تاريخ ميلادك مع النقاط.' : 'Formatbeispiel: 01.01.1990 – bitte Ihr eigenes Geburtsdatum mit Punkten eingeben.' }}</span>
                @elseif($mode === 'setlink')
                    <a href="{{ $setPasswordUrl }}" style="display:inline-block;background:#17191d;color:#ffffff;font-size:13px;font-weight:bold;text-decoration:none;padding:9px 20px;border-radius:8px;">{{ $ar ? 'عيّن كلمة المرور الآن' : 'Passwort jetzt festlegen' }}</a>
                @else
                    {{ $ar ? 'كلمة المرور الأولية:' : 'Startpasswort:' }} <strong style="font-size:15px;letter-spacing:.04em;">{{ $plainPassword }}</strong>
                @endif
            </td>
        </tr>
    </table>
</td></tr>
</table>
</td></tr>

{{-- Portal-Funktionen: 2x2 grosse rechteckige Karten mit Icons --}}
<tr><td style="padding:14px 30px 2px;">
    <p style="font-size:12px;color:#17191d;margin:0 0 10px;letter-spacing:.06em;text-align:center;"><strong>{{ $ar ? '✨ ماذا يمكنك أن تفعل في البوابة؟' : '✨ WAS KÖNNEN SIE IM PORTAL TUN?' }}</strong></p>
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="50%" style="padding:0 5px 10px 0;">
                <a href="{{ $portalBase }}/contracts" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #dfe3e8;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">📄</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#17191d;font-weight:bold;">{{ $ar ? 'العقود' : 'Verträge' }}</span><br><span style="font-size:12px;color:#777;">{{ $ar ? 'كلها في لمحة' : 'alle auf einen Blick' }}</span></td>
                </tr></table></a>
            </td>
            <td width="50%" style="padding:0 0 10px 5px;">
                <a href="{{ $portalBase }}/documents" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #dfe3e8;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">📁</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#17191d;font-weight:bold;">{{ $ar ? 'المستندات' : 'Dokumente' }}</span><br><span style="font-size:12px;color:#777;">{{ $ar ? 'استرجاع آمن' : 'sicher abrufen' }}</span></td>
                </tr></table></a>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding:0 5px 0 0;">
                <a href="{{ $portalBase }}/tickets/create" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #dfe3e8;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">💬</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#17191d;font-weight:bold;">{{ $ar ? 'الدعم' : 'Support' }}</span><br><span style="font-size:12px;color:#777;">{{ $ar ? 'إرسال طلب' : 'Anfrage stellen' }}</span></td>
                </tr></table></a>
            </td>
            <td width="50%" style="padding:0 0 0 5px;">
                <a href="{{ $portalBase }}/profile" style="text-decoration:none;"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #dfe3e8;border-radius:12px;"><tr>
                    <td width="52" align="center" style="font-size:26px;padding:16px 4px 16px 14px;">👤</td>
                    <td style="padding:16px 12px 16px 6px;"><span style="font-size:15px;color:#17191d;font-weight:bold;">{{ $ar ? 'بياناتي' : 'Meine Daten' }}</span><br><span style="font-size:12px;color:#777;">{{ $ar ? 'حافظ عليها محدثة' : 'aktuell halten' }}</span></td>
                </tr></table></a>
            </td>
        </tr>
    </table>
</td></tr>

{{-- Hilfe-Box (volle Breite) --}}
<tr><td style="padding:12px 30px 2px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#17191d;border-radius:10px;">
<tr>
    <td style="padding:14px 20px;text-align:{{ $ar ? 'right' : 'left' }};">
        <p style="font-size:14px;color:#ffffff;margin:0 0 2px;"><strong>{{ $ar ? 'هل تحتاج مساعدة؟' : 'Brauchen Sie Hilfe?' }}</strong></p>
        <p style="font-size:12px;color:#c6cbd3;margin:0;">{{ $ar ? 'فريقنا يسعده مساعدتك:' : 'Unser Team hilft Ihnen gerne:' }} <a href="mailto:info@dienstly24.de" style="color:#17A65B;text-decoration:none;font-weight:bold;">info@dienstly24.de</a></p>
    </td>
    <td align="{{ $ar ? 'left' : 'right' }}" style="padding:14px 20px 14px 0;white-space:nowrap;">
        <a href="{{ $supportUrl }}" style="display:inline-block;background:#17A65B;color:#ffffff;font-size:13px;font-weight:bold;text-decoration:none;padding:11px 20px;border-radius:8px;">💬 {{ $ar ? 'إرسال طلب' : 'Anfrage senden' }}</a>
    </td>
</tr>
</table>
</td></tr>

{{-- Sicherheit + Datenschutz – kompakt --}}
<tr><td style="padding:12px 30px 2px;text-align:{{ $ar ? 'right' : 'left' }};">
    <p style="font-size:11.5px;color:#7a5c12;background:#fff8e6;border:1px solid #f0e0b0;border-radius:8px;padding:9px 12px;margin:0;"><strong>🔒 {{ $ar ? 'الأمان:' : 'Sicherheit:' }}</strong> {{ $ar ? 'لن نطلب منك كلمة مرورك عبر البريد أو الهاتف أبداً. نسيت كلمة المرور؟ استخدم „نسيت كلمة المرور" في صفحة الدخول.' : 'Wir fragen niemals per E-Mail oder Telefon nach Ihrem Passwort. Passwort vergessen? Einfach „Passwort vergessen" auf der Login-Seite nutzen.' }}</p>
</td></tr>
<tr><td style="padding:8px 30px 12px;text-align:{{ $ar ? 'right' : 'left' }};">
    <p style="font-size:10.5px;color:#8a938f;line-height:1.5;margin:0;"><strong>{{ $ar ? 'ملاحظة بشأن حماية البيانات:' : 'Hinweis zum Datenschutz:' }}</strong> {{ $ar ? 'لخدمة عقودك نعالج أيضاً المراسلات المتعلقة بالعقود (مثلاً من شركات التأمين أو الطاقة) ونتيحها لك في البوابة – حصراً لخدمة العقود، دون تمرير غير مصرّح.' : 'Zur Betreuung Ihrer Verträge verarbeiten wir auch vertragsbezogene Korrespondenz (z. B. von Versicherungs- oder Energieunternehmen) und stellen sie Ihnen im Portal bereit – ausschließlich zur Vertragsbetreuung, ohne unbefugte Weitergabe.' }} <a href="{{ $portalBase }}/datenschutz" style="color:#17A65B;text-decoration:none;">{{ $ar ? 'سياسة الخصوصية' : 'Datenschutzerklärung' }}</a>.</p>
</td></tr>

{{-- Footer --}}
<tr><td align="center" style="background:#f8f9fb;border-top:1px solid #e2e8f0;padding:14px 30px;">
    <p style="font-size:12px;color:#152826;margin:0 0 4px;"><strong>Dienstly24</strong> – {{ $ar ? 'شريكك للخدمات المالية' : 'Ihr Partner für Finanzdienstleistungen' }}</p>
    <p style="font-size:11.5px;margin:0 0 6px;">
        <a href="https://dienstly24.de" style="color:#17A65B;text-decoration:none;">Website</a> &nbsp;·&nbsp;
        <a href="{{ $portalBase }}/impressum" style="color:#17A65B;text-decoration:none;">{{ $ar ? 'بيانات الناشر' : 'Impressum' }}</a> &nbsp;·&nbsp;
        <a href="{{ $portalBase }}/datenschutz" style="color:#17A65B;text-decoration:none;">{{ $ar ? 'الخصوصية' : 'Datenschutz' }}</a>
    </p>
    <p style="font-size:10.5px;color:#9aa39f;margin:0;">{{ $ar ? 'أُرسلت هذه الرسالة تلقائياً. الرجاء عدم الرد عليها مباشرة.' : 'Diese E-Mail wurde automatisch versendet. Bitte antworten Sie nicht direkt darauf.' }}</p>
</td></tr>

</table>
</td></tr></table>
</body>
</html>
