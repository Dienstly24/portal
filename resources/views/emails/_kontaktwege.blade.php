{{-- Kontaktwege statt Portal-Link (Kundenportal gesperrt, 20.11.2026).
     Tabellenbasierte "bulletproof"-Knoepfe: kein JavaScript, kein SVG,
     funktionieren in Outlook, Gmail und Apple Mail. Erwartet $lang und
     $chatUrl (absolut, ggf. mit signiertem ?ref=). --}}
@php
    $kwAr = ($lang ?? 'de') === 'ar';
    $kwWa = \App\Support\Kundenportal::whatsappLink($kwAr
        ? 'مرحباً Dienstly24، لدي استفسار.'
        : 'Hallo Dienstly24, ich habe ein Anliegen.');
    $kwTel = \App\Support\Kundenportal::telefonAnzeige();
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;"><tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td align="center" bgcolor="#17A65B" style="border-radius:8px;">
<a href="{{ $chatUrl }}" target="_blank" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">{{ $kwAr ? 'اكتب لنا في المحادثة' : 'Im Chat schreiben' }}</a>
</td>
@if($kwWa)
<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>
<td align="center" bgcolor="#ffffff" style="border:2px solid #1F9E57;border-radius:8px;">
<a href="{{ $kwWa }}" target="_blank" style="display:inline-block;padding:10px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#1F9E57;text-decoration:none;border-radius:8px;">{{ $kwAr ? 'راسلنا عبر واتساب' : 'Per WhatsApp schreiben' }}</a>
</td>
@endif
</tr></table>
</td></tr></table>
<p style="font-size:14px;color:#555;text-align:center;margin:6px 0 18px;">
{{ $kwAr ? 'أو ردّ ببساطة على هذا البريد الإلكتروني.' : 'Oder antworten Sie einfach auf diese E-Mail.' }}
@if($kwTel !== '')<br>{{ $kwAr ? 'الهاتف:' : 'Telefon:' }} <span dir="ltr">{{ $kwTel }}</span>@endif
</p>
