@extends('layouts.portal')
@section('content')
<div class="page-title">{{ __('E-Mail-Verbindung') }}</div>
<div class="page-sub">{{ __('Lassen Sie vertragsbezogene Post automatisch Ihrem Konto zuordnen - freiwillig und jederzeit widerrufbar.') }}</div>

@if($consent)
    {{-- Aktive Einwilligung: Import-Adresse + Weiterleitungs-Anleitung --}}
    <div class="card">
        <div class="card-title">✅ {{ __('Verbindung aktiv') }}</div>
        <p style="font-size:14px;color:var(--ink);line-height:1.6;">
            {{ __('Ihre E-Mail-Verbindung ist seit dem') }}
            <strong>{{ $consent->granted_at?->format('d.m.Y') }}</strong>
            {{ __('aktiv. Leiten Sie vertragsbezogene E-Mails an folgende persoenliche Adresse weiter:') }}
        </p>
        <div style="background:var(--surface,#101216);color:#fff;border-radius:10px;padding:14px 16px;font-family:monospace;font-size:15px;word-break:break-all;margin:12px 0;">
            {{ $importAddress }}
        </div>
        <p style="font-size:12.5px;color:var(--ink-soft);line-height:1.6;">
            {{ __('Diese Adresse ist nur fuer Sie bestimmt. Bitte teilen Sie sie nicht mit Dritten.') }}
        </p>
    </div>

    <div class="card">
        <div class="card-title">↪️ {{ __('Weiterleitung einrichten') }}</div>
        <ul style="font-size:14px;color:var(--ink);line-height:1.8;padding-left:20px;margin:0;">
            <li><strong>Gmail:</strong> {{ __('Einstellungen → Weiterleitung → Adresse hinzufuegen; Filter fuer Absender Ihrer Versicherer/Energieanbieter anlegen.') }}</li>
            <li><strong>Outlook / Microsoft 365:</strong> {{ __('Einstellungen → E-Mail → Regeln → Weiterleiten an die obige Adresse.') }}</li>
            <li>{{ __('Empfehlung: Nur Post von Versicherungs- und Energieunternehmen weiterleiten.') }}</li>
        </ul>
    </div>

    <div class="card">
        <div class="card-title">🔒 {{ __('Was verarbeitet wird') }}</div>
        <ul style="font-size:14px;color:var(--ink);line-height:1.8;padding-left:20px;margin:0;">
            <li>{{ __('Nur vertragsbezogene Post von bekannten Versicherern, Energieanbietern und Partnern.') }}</li>
            <li>{{ __('Private oder nicht zuordenbare Nachrichten werden verworfen und nicht gespeichert.') }}</li>
            <li>{{ __('Jede automatische Zuordnung wird von unseren Mitarbeitern geprueft.') }}</li>
        </ul>
    </div>

    <div class="card">
        <div class="card-title">🚫 {{ __('Verbindung trennen') }}</div>
        <p style="font-size:14px;color:var(--ink);line-height:1.6;">
            {{ __('Sie koennen Ihre Einwilligung jederzeit widerrufen. Danach wird keine weitere E-Mail verarbeitet.') }}
        </p>
        <form method="POST" action="{{ route('portal.email_connection.revoke') }}" style="margin-top:12px;">
            @csrf
            <button type="submit" class="btn" style="background:#A32D2D;color:#fff;">{{ __('Verbindung trennen') }}</button>
        </form>
    </div>
@else
    {{-- Keine aktive Einwilligung: getrennte, nicht vorausgewaehlte Checkbox --}}
    <div class="card">
        <div class="card-title">📨 {{ __('So funktioniert es') }}</div>
        <p style="font-size:14px;color:var(--ink);line-height:1.6;">
            {{ __('Mit Ihrer Einwilligung erhalten Sie eine persoenliche Weiterleitungs-Adresse. Post, die Sie dorthin weiterleiten (z. B. von Versicherern oder Energieanbietern), ordnen wir automatisch Ihren Vertraegen zu und stellen sie Ihnen im Portal bereit.') }}
        </p>
        <p style="font-size:13px;color:var(--ink-soft);line-height:1.6;">
            {{ __('Rechtsgrundlage: Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Die Einwilligung ist freiwillig, unabhaengig von den AGB und jederzeit widerrufbar.') }}
        </p>

        <form method="POST" action="{{ route('portal.email_connection.grant') }}" style="margin-top:16px;">
            @csrf
            <label style="display:flex;gap:10px;align-items:flex-start;font-size:14px;color:var(--ink);line-height:1.5;cursor:pointer;">
                <input type="checkbox" name="consent" value="1" style="width:auto;margin-top:3px;">
                <span>{{ __('Ich willige ein, dass Dienstly24 die an meine persoenliche Import-Adresse weitergeleiteten, vertragsbezogenen E-Mails und Anhaenge zur Betreuung meiner Vertraege verarbeitet. Nicht vertragsbezogene Nachrichten werden verworfen.') }}</span>
            </label>
            <button type="submit" class="btn" style="margin-top:16px;">{{ __('E-Mail-Verbindung aktivieren') }}</button>
        </form>
    </div>
@endif
@endsection
