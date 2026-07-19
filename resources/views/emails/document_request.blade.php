<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Wir benötigen ein Dokument von Ihnen 📄</h1>
</td></tr>
<tr><td style="padding:30px;">
@include('emails._greeting', ['greetingCustomer' => $documentRequest->customer])
<p style="font-size:15px;color:#333;">für die weitere Bearbeitung benötigen wir folgendes Dokument von Ihnen:</p>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;margin:15px 0;"><tr><td style="padding:18px 20px;">
<p style="font-size:16px;font-weight:bold;color:#17A65B;margin:0 0 6px;">{{ $documentRequest->title }}</p>
@if($documentRequest->description)
<p style="font-size:14px;color:#555;margin:0 0 6px;">{{ $documentRequest->description }}</p>
@endif
@if($documentRequest->contract)
<p style="font-size:13px;color:#666;margin:0 0 6px;">Betrifft Vertrag: {{ $documentRequest->contract->contract_number }} ({{ $documentRequest->contract->insurer }})</p>
@endif
@if($documentRequest->deadline)
<p style="font-size:13px;color:#B3261E;margin:0;"><strong>Frist: {{ $documentRequest->deadline->format('d.m.Y') }}</strong></p>
@endif
</td></tr></table>
@if($documentRequest->status === 'rejected' && $documentRequest->rejection_note)
<p style="font-size:14px;color:#B3261E;">Hinweis zu Ihrem letzten Upload: {{ $documentRequest->rejection_note }}</p>
@endif
<p style="font-size:15px;color:#333;">Sie können das Dokument bequem über Ihr Kundenportal hochladen:</p>
<p style="text-align:center;margin:25px 0;"><a href="{{ route('portal.documents') }}" style="background:#17A65B;color:#ffffff;padding:12px 30px;border-radius:8px;text-decoration:none;font-size:15px;">Dokument jetzt hochladen</a></p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>
</table></td></tr></table>
</body>
</html>
