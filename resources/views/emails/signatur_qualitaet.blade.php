<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#131A17;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:20px;">⚠️ Signaturen: Qualitätsprüfung</h1>
</td></tr>
<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;">Hallo <strong>{{ $recipient->name }}</strong>,</p>
<p style="font-size:15px;color:#333;">
{{ $gesamt }} Signaturvorgang/Signaturvorgänge haben die Qualitätsprüfung derzeit nicht bestanden
@if($neueFehlschlaege > 0)
– davon {{ $neueFehlschlaege }} in den letzten 24 Stunden neu
@endif
.
Ein betroffenes Dokument zeigt eine gesetzte Unterschrift oder einen Stempel möglicherweise nicht.
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:15px 0;">
@foreach($zeilen as $z)
<tr><td style="padding:8px 0;border-bottom:1px solid #E0DCD0;font-size:14px;color:#16211C;">
<strong>{{ $z['titel'] }}</strong> <span style="color:#5F6B62;">({{ $z['status'] }})</span><br>
<span style="font-size:13px;color:#5F6B62;">{{ $z['befund'] }}</span>
</td></tr>
@endforeach
</table>
<p style="font-size:15px;color:#333;">In der Beraterwelt unter <strong>Signaturen → Qualität</strong> lässt sich jeder Vorgang neu erzeugen oder erneut prüfen:</p>
<p><a href="{{ route('admin.signatures.quality') }}" style="display:inline-block;background:#17A65B;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;">Signatur-Qualität öffnen</a></p>
<p style="font-size:13px;color:#8a8a8a;">Diese Mail kommt nur, wenn etwas fehlgeschlagen ist.</p>
</td></tr>
</table>
</td></tr></table>
</body>
</html>
