<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#1e3a8a;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">💬 Antwort auf Ihre Anfrage</h1>
@if($ticket->ticket_number)<p style="color:#c7d2fe;margin:6px 0 0;font-size:13px;">Vorgangsnummer: {{ $ticket->ticket_number }}</p>@endif
</td></tr>
<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;">Guten Tag{{ $ticket->guest_name ? ' ' . $ticket->guest_name : '' }},</p>
<p style="font-size:15px;color:#333;">vielen Dank für Ihre Anfrage „{{ $ticket->subject }}“. Hier ist unsere Antwort:</p>
<table width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="background:#f8fafc;border-left:4px solid #1e3a8a;border-radius:6px;padding:16px 18px;">
<p style="font-size:15px;color:#333;margin:0;line-height:1.7;white-space:pre-line;">{{ $replyBody }}</p>
</td>
</tr></table>
<p style="font-size:14px;color:#555;margin-top:22px;">Sie haben noch Fragen? Antworten Sie einfach auf diese E-Mail und nennen Sie dabei Ihre Vorgangsnummer.</p>
<p style="font-size:15px;color:#333;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
