@php
    $req = $signatureRequest;
    $frist = $req->expires_at?->lokal()->format('d.m.Y');
    $sprache = app()->getLocale();
    $rtl = in_array($sprache, \App\Models\SignatureSigner::RTL, true);
    $seite = $rtl ? 'right' : 'left';
@endphp
<!DOCTYPE html>
<html lang="{{ $sprache }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
{{-- E-Mail-HTML: tabellenbasiert, Inline-Styles, absolute URLs, KEIN SVG
     (Gmail/Outlook entfernen es). Markenfarben: Graphit #17191d,
     Smaragd #17A65B.
     ARABISCH: dir am <html> reicht bei Outlook nicht zuverlaessig - jeder
     Textblock bekommt deshalb zusaetzlich seine Ausrichtung. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;text-align:{{ $seite }};">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">

<tr><td style="background:#17191d;padding:25px 30px;text-align:{{ $seite }};">
<h1 style="color:#ffffff;margin:0;font-size:22px;">
    {{ $isReminder ? __('signing.mail_reminder_head') : __('signing.mail_invitation_head') }}
</h1>
</td></tr>

<tr><td style="padding:30px;text-align:{{ $seite }};">
<p style="font-size:15px;color:#333;margin:0 0 4px;">{{ __('signing.mail_greeting', ['name' => $signer->name]) }}</p>
<p style="font-size:15px;color:#333;">
    {{ $isReminder ? __('signing.mail_reminder_intro') : __('signing.mail_invitation_intro') }}
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;margin:15px 0;"><tr><td style="padding:18px 20px;text-align:{{ $seite }};">
    <p style="font-size:16px;font-weight:bold;color:#17A65B;margin:0 0 6px;">{{ $req->title }}</p>
    <p style="font-size:13px;color:#555;margin:0;">{{ __('signing.mail_pages', ['count' => $req->page_count]) }}</p>
    @if($req->reference)
    <p style="font-size:13px;color:#666;margin:6px 0 0;">{{ __('signing.mail_reference', ['ref' => $req->reference]) }}</p>
    @endif
    @if($frist)
    <p style="font-size:13px;color:#B3261E;margin:8px 0 0;"><strong>{{ __('signing.mail_deadline', ['date' => $frist]) }}</strong></p>
    @endif
</td></tr></table>

<p style="font-size:15px;color:#333;">{{ __('signing.mail_no_account') }}</p>

<p style="text-align:center;margin:26px 0;">
    <a href="{{ $signUrl }}" style="background:#17A65B;color:#ffffff;padding:14px 34px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:bold;">{{ __('signing.mail_invitation_button') }}</a>
</p>

@if($req->require_email_verification)
<p style="font-size:13px;color:#666;">{{ __('signing.mail_code_hint') }}</p>
@endif

<p style="font-size:13px;color:#666;">
    {{ __('signing.mail_link_personal') }}<br>
    {{-- Die URL selbst wird NIE gespiegelt: eine von rechts gelesene
         Adresse ist unbrauchbar. --}}
    <span dir="ltr" style="color:#17A65B;word-break:break-all;display:inline-block;text-align:left;">{{ $signUrl }}</span>
</p>

<p style="font-size:13px;color:#666;">{{ __('signing.mail_not_expected') }}</p>

<p style="font-size:15px;color:#333;margin-top:22px;">{{ __('signing.mail_regards') }}<br>{{ __('signing.mail_team') }}</p>
</td></tr>

</table></td></tr></table>
</body>
</html>
