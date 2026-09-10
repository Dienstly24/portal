<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
{{-- Abschluss-Mail mit dem unterschriebenen PDF im Anhang. Bewusst OHNE
     Link auf das Dokument: nach dem Abschluss ist der Zugang widerrufen. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
@php $req = $signatureRequest; @endphp
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">

<tr><td style="background:#17191d;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Erfolgreich unterschrieben ✅</h1>
</td></tr>

<tr><td style="padding:30px;">
<p style="font-size:15px;color:#333;margin:0 0 4px;">Guten Tag {{ $signer->name }},</p>
<p style="font-size:15px;color:#333;">
    vielen Dank – das Dokument ist vollständig unterschrieben. Ihre Kopie finden Sie im Anhang dieser E-Mail.
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-radius:8px;margin:15px 0;"><tr><td style="padding:18px 20px;">
    <p style="font-size:16px;font-weight:bold;color:#17A65B;margin:0 0 6px;">{{ $req->title }}</p>
    <p style="font-size:13px;color:#555;margin:0;">
        Abgeschlossen am {{ $req->completed_at?->lokal()->format('d.m.Y \u\m H:i') }} Uhr
    </p>
    <p style="font-size:12px;color:#888;margin:8px 0 0;word-break:break-all;">
        Prüfsumme (SHA-256) des unterschriebenen Dokuments:<br>{{ $req->signed_hash }}
    </p>
</td></tr></table>

<p style="font-size:13px;color:#666;">
    Die letzte Seite des PDF enthält das Signaturprotokoll mit allen Angaben zum Ablauf.
    Bewahren Sie die Datei bitte auf – die Prüfsumme belegt, dass das Dokument seitdem nicht verändert wurde.
</p>

<p style="font-size:15px;color:#333;margin-top:22px;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>

</table></td></tr></table>
</body>
</html>
