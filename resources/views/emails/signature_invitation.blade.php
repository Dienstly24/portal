<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
{{-- E-Mail-HTML: tabellenbasiert, Inline-Styles, absolute URLs, KEIN SVG
     (Gmail/Outlook entfernen es). Markenfarben: Graphit #17191d,
     Smaragd #17A65B. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
@php
    $req = $signatureRequest;
    $frist = $req->expires_at?->lokal()->format('d.m.Y');
@endphp
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">

<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">
    {{ $isReminder ? 'Erinnerung: Ihre Unterschrift fehlt noch ✍️' : 'Dokument zur Unterschrift ✍️' }}
</h1>
</td></tr>

<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;margin:0 0 4px;">Guten Tag {{ $signer->name }},</p>
<p style="font-size:15px;color:#333;">
    @if($isReminder)
        wir möchten Sie freundlich daran erinnern, dass folgendes Dokument noch auf Ihre Unterschrift wartet:
    @else
        Dienstly24 bittet Sie, das folgende Dokument elektronisch zu unterschreiben:
    @endif
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;margin:15px 0;"><tr><td style="padding:18px 20px;">
    <p style="font-size:16px;font-weight:bold;color:#17A65B;margin:0 0 6px;">{{ $req->title }}</p>
    <p style="font-size:13px;color:#555;margin:0;">{{ $req->page_count }} Seite{{ $req->page_count === 1 ? '' : 'n' }}</p>
    @if($req->reference)
    <p style="font-size:13px;color:#666;margin:6px 0 0;">Referenz: {{ $req->reference }}</p>
    @endif
    @if($frist)
    <p style="font-size:13px;color:#B3261E;margin:8px 0 0;"><strong>Bitte bis zum {{ $frist }} unterschreiben.</strong></p>
    @endif
</td></tr></table>

<p style="font-size:15px;color:#333;">
    Sie brauchen dafür kein Benutzerkonto. Der folgende Button führt Sie direkt zum Dokument –
    unterschreiben können Sie mit dem Finger auf dem Handy oder mit der Maus am Computer.
</p>

<p style="text-align:center;margin:26px 0;">
    <a href="{{ $signUrl }}" style="background:#17A65B;color:#ffffff;padding:14px 34px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:bold;">Dokument öffnen und unterschreiben</a>
</p>

@if($req->require_email_verification)
<p style="font-size:13px;color:#666;">
    Zu Ihrer Sicherheit senden wir Ihnen beim Öffnen zusätzlich einen sechsstelligen Bestätigungscode an diese
    E-Mail-Adresse.
</p>
@endif

<p style="font-size:13px;color:#666;">
    Dieser Link ist persönlich und gilt nur für Sie. Bitte geben Sie ihn nicht weiter.
    Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:<br>
    <span style="color:#17A65B;word-break:break-all;">{{ $signUrl }}</span>
</p>

<p style="font-size:13px;color:#666;">
    Sie erwarten kein Dokument von uns? Dann ignorieren Sie diese E-Mail bitte – ohne Ihre Bestätigung
    passiert nichts.
</p>

<p style="font-size:15px;color:#333;margin-top:22px;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>

</table></td></tr></table>
</body>
</html>
