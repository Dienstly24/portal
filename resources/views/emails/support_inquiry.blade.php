<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:18px 30px;color:#fff;font-size:16px;font-weight:bold;">📩 Neue Kundenanfrage über die Webseite</td></tr>
<tr><td style="padding:25px 30px;">
<table width="100%" cellpadding="6" cellspacing="0" style="font-size:14px;color:#333;">
<tr><td style="color:#6B7280;width:150px;">Kundenname</td><td style="font-weight:bold;">{{ $ticket->guest_name }}</td></tr>
<tr><td style="color:#6B7280;">Kundennummer</td><td style="font-weight:bold;">{{ $customerNumber ?? 'kein Bestandskunde' }}</td></tr>
<tr><td style="color:#6B7280;">E-Mail-Adresse</td><td style="font-weight:bold;">{{ $ticket->guest_email }}</td></tr>
@if($ticket->guest_phone)<tr><td style="color:#6B7280;">Telefon</td><td style="font-weight:bold;">{{ $ticket->guest_phone }}</td></tr>@endif
<tr><td style="color:#6B7280;">Betreff</td><td style="font-weight:bold;">{{ $ticket->subject }}</td></tr>
<tr><td style="color:#6B7280;">Datum/Uhrzeit</td><td style="font-weight:bold;">{{ $ticket->created_at->format('d.m.Y H:i') }} Uhr</td></tr>
</table>
<div style="margin-top:16px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">{{ $ticket->description }}</div>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
