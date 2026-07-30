@php
    $ar = ($lang ?? 'de') === 'ar';
    $name = $ticket->guest_name ?: ($ar ? 'عميلنا العزيز' : 'Liebe Interessentin, lieber Interessent');
    $leistung = str_replace('Website-Anfrage: ', '', (string) $ticket->subject);
@endphp
<!DOCTYPE html>
<html lang="{{ $ar ? 'ar' : 'de' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F1EEE5;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1EEE5;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#FBFAF6;border:1px solid #E0DCD0;border-radius:14px;overflow:hidden;">
  <tr>
    <td style="background:#131A17;padding:22px 28px;text-align:center;">
      <span style="font-size:22px;font-weight:bold;color:#F5F3EC;letter-spacing:.5px;">Dienstly<span style="color:#17A65B;">24</span></span>
    </td>
  </tr>
  <tr>
    <td style="padding:28px;text-align:{{ $ar ? 'right' : 'left' }};" dir="{{ $ar ? 'rtl' : 'ltr' }}">
      @if($ar)
        <p style="margin:0 0 14px;font-size:16px;color:#16211C;"><strong>مرحباً {{ $name }}،</strong></p>
        <p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#3E4A42;">شكراً لتواصلكم معنا. وصلنا طلبكم بخصوص: <strong>{{ $leistung }}</strong>.</p>
        <p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#3E4A42;">سيراجع فريقنا طلبكم ويتواصل معكم عادةً خلال <strong>24 ساعة</strong> – بالعربية أو الألمانية، كما تفضلون.</p>
        <p style="margin:0 0 6px;font-size:14px;line-height:1.7;color:#3E4A42;">إذا كان الأمر عاجلاً:</p>
        <p style="margin:0 0 18px;font-size:14px;line-height:1.8;color:#3E4A42;">
          &#128222; <a href="tel:{{ config('website.phone_e164') }}" style="color:#128A4B;text-decoration:none;" dir="ltr">{{ config('website.phone_display') }}</a><br>
          &#128172; واتساب: <a href="https://wa.me/{{ config('website.whatsapp') }}" style="color:#128A4B;text-decoration:none;" dir="ltr">wa.me/{{ config('website.whatsapp') }}</a>
        </p>
        <p style="margin:0;font-size:13px;color:#5F6B62;">مع أطيب التحيات<br>فريق Dienstly24</p>
      @else
        <p style="margin:0 0 14px;font-size:16px;color:#16211C;"><strong>Hallo {{ $name }},</strong></p>
        <p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#3E4A42;">vielen Dank für Ihre Nachricht. Ihre Anfrage zu <strong>{{ $leistung }}</strong> ist bei uns eingegangen.</p>
        <p style="margin:0 0 14px;font-size:14px;line-height:1.7;color:#3E4A42;">Unser Team prüft Ihre Anfrage und meldet sich in der Regel innerhalb von <strong>24 Stunden</strong> bei Ihnen – auf Deutsch oder Arabisch, ganz wie Sie möchten.</p>
        <p style="margin:0 0 6px;font-size:14px;line-height:1.7;color:#3E4A42;">Wenn es eilig ist:</p>
        <p style="margin:0 0 18px;font-size:14px;line-height:1.8;color:#3E4A42;">
          &#128222; <a href="tel:{{ config('website.phone_e164') }}" style="color:#128A4B;text-decoration:none;">{{ config('website.phone_display') }}</a><br>
          &#128172; WhatsApp: <a href="https://wa.me/{{ config('website.whatsapp') }}" style="color:#128A4B;text-decoration:none;">wa.me/{{ config('website.whatsapp') }}</a>
        </p>
        <p style="margin:0;font-size:13px;color:#5F6B62;">Herzliche Grüße<br>Ihr Team von Dienstly24</p>
      @endif
    </td>
  </tr>
  <tr>
    <td style="padding:16px 28px;background:#F1EEE5;border-top:1px solid #E0DCD0;text-align:center;">
      <p style="margin:0;font-size:11px;color:#5F6B62;line-height:1.6;">
        Dienstly24 · Furtweg 51a · 22523 Hamburg<br>
        <a href="{{ \App\Support\WebsiteHosts::url('/impressum') }}" style="color:#5F6B62;">Impressum</a> ·
        <a href="{{ \App\Support\WebsiteHosts::url('/datenschutz') }}" style="color:#5F6B62;">Datenschutz</a>
      </p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
