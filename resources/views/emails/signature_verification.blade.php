@php
    $sprache = app()->getLocale();
    $rtl = in_array($sprache, \App\Models\SignatureSigner::RTL, true);
    $seite = $rtl ? 'right' : 'left';
@endphp
<!DOCTYPE html>
<html lang="{{ $sprache }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
{{-- Bestätigungscode. Tabellenbasiert, Inline-Styles, KEIN SVG. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;text-align:{{ $seite }};">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">

<tr><td style="background:#17191d;padding:25px 30px;text-align:{{ $seite }};">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ __('signing.mail_code_head') }}</h1>
</td></tr>

<tr><td style="padding:30px;text-align:{{ $seite }};">
<p style="font-size:15px;color:#333;margin:0 0 4px;">{{ __('signing.mail_greeting', ['name' => $signer->name]) }}</p>
<p style="font-size:15px;color:#333;">{{ __('signing.mail_code_intro', ['title' => $signatureRequest->title]) }}</p>

<p style="text-align:center;margin:26px 0;">
    {{-- dir="ltr": eine Ziffernfolge wird nie gespiegelt. --}}
    <span dir="ltr" style="display:inline-block;background:#f8f9fb;border:2px solid #17A65B;border-radius:10px;padding:16px 34px;font-size:32px;letter-spacing:8px;font-weight:bold;color:#17191d;">{{ $code }}</span>
</p>

<p style="font-size:13px;color:#666;">{{ __('signing.mail_code_note') }}</p>

<p style="font-size:15px;color:#333;margin-top:22px;">{{ __('signing.mail_regards') }}<br>{{ __('signing.mail_team') }}</p>
</td></tr>

</table></td></tr></table>
</body>
</html>
