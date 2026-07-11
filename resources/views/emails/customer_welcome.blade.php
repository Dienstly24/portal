<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:30px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#1e3a8a;padding:25px 30px;">
<h1 style="color:#ffffff;margin:0;font-size:22px;">Willkommen bei Dienstly24 👋</h1>
</td></tr>
<tr><td style="padding:30px;">
@include('emails._greeting', ['greetingCustomer' => $customer])
<p style="font-size:15px;color:#333;">Ihr persönliches Kundenportal ist eingerichtet. Dort sehen Sie Ihre Verträge und Dokumente, können Anfragen stellen und Ihre Daten bequem selbst pflegen.</p>

{{-- Zugangsdaten-Box --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:8px;margin:18px 0;"><tr><td style="padding:18px 20px;">
<p style="font-size:14px;color:#333;margin:0 0 10px;"><strong>Ihre Zugangsdaten:</strong></p>
<p style="font-size:14px;color:#333;margin:0 0 6px;">🌐 Portal: <a href="https://portal.dienstly24.de" style="color:#1e3a8a;">portal.dienstly24.de</a></p>
<p style="font-size:14px;color:#333;margin:0 0 10px;">👤 Benutzername: <strong>{{ $loginEmail }}</strong></p>
@if($mode === 'birthdate')
<p style="font-size:14px;color:#333;margin:0;">🔑 <strong>Ihr erstes Passwort ist Ihr Geburtsdatum im Format TT.MM.JJJJ</strong><br>
<span style="color:#666;font-size:13px;">Beispiel für das Format: 01.01.1990 – bitte geben Sie Ihr eigenes Geburtsdatum mit Punkten ein.</span></p>
@elseif($mode === 'setlink')
<p style="font-size:14px;color:#333;margin:0;">🔑 Bitte legen Sie zunächst Ihr persönliches Passwort fest:</p>
<p style="text-align:center;margin:14px 0 0;">
    <a href="{{ $setPasswordUrl }}" style="background:#1e3a8a;color:#ffffff;padding:11px 26px;border-radius:8px;text-decoration:none;font-size:14px;">Passwort jetzt festlegen</a>
</p>
@else
<p style="font-size:14px;color:#333;margin:0;">🔑 Ihr Startpasswort: <strong>{{ $plainPassword }}</strong></p>
@endif
</td></tr></table>

{{-- Erste Schritte --}}
<p style="font-size:14px;color:#333;margin:0 0 6px;"><strong>Ihr erster Login in 3 Schritten:</strong></p>
<ol style="font-size:14px;color:#333;margin:0 0 16px;padding-left:20px;">
    <li>Öffnen Sie <a href="https://portal.dienstly24.de" style="color:#1e3a8a;">portal.dienstly24.de</a></li>
    <li>Melden Sie sich mit Ihrer E-Mail-Adresse und dem {{ $mode === 'setlink' ? 'selbst festgelegten' : 'oben beschriebenen' }} Passwort an</li>
    <li>Ändern Sie Ihr Passwort anschließend jederzeit im Portal unter „Meine Daten“</li>
</ol>

<p style="font-size:13px;color:#666;margin:0 0 4px;"><strong>Passwort vergessen?</strong> Klicken Sie auf der Login-Seite auf „Passwort vergessen“ – Sie erhalten dann einen sicheren Link zum Zurücksetzen an diese E-Mail-Adresse.</p>

<div style="background:#EFF6FF;border-radius:8px;padding:14px 18px;margin:18px 0 0;">
<p style="font-size:13.5px;color:#1e3a8a;margin:0;"><strong>📋 Unsere Bitte:</strong> Vervollständigen Sie nach dem ersten Login Ihre persönlichen Daten und Familienmitglieder – so können wir Sie optimal beraten.</p>
</div>

<p style="font-size:15px;color:#333;margin-top:18px;">Mit freundlichen Grüßen<br>Ihr Dienstly24 Team</p>
</td></tr>
</table></td></tr></table>
</body>
</html>
