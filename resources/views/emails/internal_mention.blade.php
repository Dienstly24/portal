<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:20px;">💬 Sie wurden intern erwähnt</h1>
</td></tr>
<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $recipient->name }}</strong>,</p>
<p style="font-size:15px;color:#333;">
<strong>{{ $internalMessage->sender?->name ?? 'Ein Kollege' }}</strong> hat Sie in einer internen
{{ $internalMessage->type === 'note' ? 'Notiz' : 'Nachricht' }} zum Kunden
<strong>{{ $internalMessage->customer?->user?->name ?? '—' }}</strong> erwähnt:
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F5F7;border-left:3px solid #17A65B;border-radius:6px;margin:15px 0;">
<tr><td style="padding:14px 18px;font-size:14px;color:#333;">{{ \Illuminate\Support\Str::limit($internalMessage->message, 200) }}</td></tr>
</table>
<p style="font-size:13px;color:#8a8a8a;">Diese Nachricht ist ausschließlich für Mitarbeiter bestimmt. Bitte im Admin-Portal antworten.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
