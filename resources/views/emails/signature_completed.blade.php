@php
    $req = $signatureRequest;
    $sprache = app()->getLocale();
    $rtl = in_array($sprache, \App\Models\SignatureSigner::RTL, true);
    $seite = $rtl ? 'right' : 'left';
@endphp
<!DOCTYPE html>
<html lang="{{ $sprache }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
{{-- Abschluss-Mail mit dem unterschriebenen PDF im Anhang. Bewusst OHNE
     Link auf das Dokument: nach dem Abschluss ist der Zugang widerrufen. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;text-align:{{ $seite }};">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">

<tr><td style="background:#17191d;padding:25px 30px;text-align:{{ $seite }};">
<h1 style="color:#ffffff;margin:0;font-size:22px;">{{ __('signing.mail_completed_head') }}</h1>
</td></tr>

<tr><td style="padding:30px;text-align:{{ $seite }};">
<p style="font-size:15px;color:#333;margin:0 0 4px;">{{ __('signing.mail_greeting', ['name' => $signer->name]) }}</p>
<p style="font-size:15px;color:#333;">{{ __('signing.mail_completed_intro') }}</p>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;margin:15px 0;"><tr><td style="padding:18px 20px;text-align:{{ $seite }};">
    <p style="font-size:16px;font-weight:bold;color:#17A65B;margin:0 0 6px;">{{ $req->title }}</p>
    <p style="font-size:13px;color:#555;margin:0;">
        {{ __('signing.mail_completed_at', ['date' => $req->completed_at?->lokal()->format('d.m.Y H:i')]) }}
    </p>
    <p style="font-size:12px;color:#888;margin:8px 0 0;word-break:break-all;">
        {{ __('signing.mail_checksum_label') }}<br>
        <span dir="ltr" style="display:inline-block;text-align:left;">{{ $req->signed_hash }}</span>
    </p>
</td></tr></table>

<p style="font-size:13px;color:#666;">{{ __('signing.mail_protocol_note') }}</p>

<p style="font-size:15px;color:#333;margin-top:22px;">{{ __('signing.mail_regards') }}<br>{{ __('signing.mail_team') }}</p>
</td></tr>

</table></td></tr></table>
</body>
</html>
