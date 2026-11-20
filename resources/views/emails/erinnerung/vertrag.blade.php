<!DOCTYPE html>
{{-- Vertrags-Erinnerung (Tippgeber-Rahmen, 20.11.2026). Tabellenbasiert,
     Inline-Styles, kein SVG, kein JavaScript. Alle Texte kommen aus
     App\Support\ErinnerungsTexte (dieselben wie in vertrag_text). --}}
@php
    $ar = $e->sprache === 'ar';
    $r = $e->rahmen;
    $align = $ar ? 'right' : 'left';
@endphp
<html lang="{{ $e->sprache }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ $e->betreff }}</title></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:#17191d;padding:22px 28px;text-align:{{ $align }};">
<p style="color:#ffffff;margin:0;font-size:18px;font-weight:bold;">Dienstly<span style="color:#17A65B;">24</span></p>
</td></tr>
<tr><td style="padding:28px;text-align:{{ $align }};font-size:15px;line-height:1.6;color:#333333;">

<p style="margin:0 0 16px;">{{ $r['gruss'] }}@if($e->anrede !== '') {{ $e->anrede }}@endif{{ $ar ? '،' : ',' }}</p>

@foreach($e->absaetze as $absatz)
<p style="margin:0 0 14px;">{{ $absatz }}</p>
@endforeach

{{-- Kontaktwege: "bulletproof" Knoepfe (Tabellenzellen mit bgcolor). --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 6px;"><tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td align="center" bgcolor="#17A65B" style="border-radius:8px;">
<a href="{{ $e->chatUrl }}" target="_blank" style="display:inline-block;padding:12px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">{{ $r['chat'] }}</a>
</td>
@if($e->whatsappUrl)
<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>
<td align="center" bgcolor="#ffffff" style="border:2px solid #1F9E57;border-radius:8px;">
<a href="{{ $e->whatsappUrl }}" target="_blank" style="display:inline-block;padding:10px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#1F9E57;text-decoration:none;border-radius:8px;">{{ $r['whatsapp'] }}</a>
</td>
@endif
</tr></table>
</td></tr></table>
<p style="margin:6px 0 20px;text-align:center;font-size:14px;color:#555555;">{{ $r['antworten'] }}@if($e->telefon !== '') {{ $r['telefon'] }}: <span dir="ltr">{{ $e->telefon }}</span>@endif</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F3;border:1px solid #D7E3DC;border-radius:8px;margin:0 0 20px;">
<tr><td style="padding:14px 18px;text-align:{{ $align }};">
<p style="margin:0 0 8px;font-size:14px;font-weight:bold;color:#16211C;">{{ $r['daten'] }}</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;color:#333333;">
@foreach($e->zeilen as $label => $wert)
<tr><td style="padding:2px 0;padding-{{ $ar ? 'left' : 'right' }}:12px;color:#5F6B62;vertical-align:top;">{{ $label }}:</td><td style="padding:2px 0;vertical-align:top;" @if(preg_match('/^[0-9.\-\/ ]+$/', (string) $wert)) dir="ltr" @endif>{{ $wert }}</td></tr>
@endforeach
</table>
</td></tr></table>

<p style="margin:0;">{{ $r['schluss'] }}<br>{{ $r['team'] }}</p>
</td></tr>
<tr><td style="background:#f8f9fa;padding:16px 28px;border-top:1px solid #e9ecef;text-align:center;font-size:12px;color:#8a8a8a;line-height:1.7;">
<a href="{{ $e->impressumUrl }}" style="color:#5F6B62;">{{ $r['impressum'] }}</a> &middot;
<a href="{{ $e->datenschutzUrl }}" style="color:#5F6B62;">{{ $r['datenschutz'] }}</a>
@if($e->abmeldeUrl)
&middot; {{ $r['abbestellen_frage'] }} <a href="{{ $e->abmeldeUrl }}" style="color:#17A65B;">{{ $r['abbestellen'] }}</a>
@endif
</td></tr>
</table>
</td></tr></table>
</body>
</html>
