@extends('layouts.portal')
@section('content')
<div class="page-title">{{ __('Datenschutz') }} &amp; {{ __('Ihre E-Mails') }}</div>
<div class="page-sub">{{ __('Transparente Information darüber, welche vertragsbezogene Korrespondenz wir für Sie verarbeiten.') }}</div>

<div class="card">
    <div class="card-title">📨 {{ __('Verarbeitung vertragsbezogener Korrespondenz') }}</div>
    <p style="font-size:14px;color:var(--ink);line-height:1.6;">
        {{ __('Damit wir Ihre Verträge zuverlässig betreuen können, empfangen und verarbeiten wir auch Korrespondenz, die Ihre Verträge betrifft – zum Beispiel Nachrichten und Dokumente von Versicherungs- oder Energieunternehmen sowie von Vertriebspartnern.') }}
    </p>
    <p style="font-size:14px;color:var(--ink);line-height:1.6;">
        {{ __('Solche Vorgänge ordnen wir automatisiert Ihrem Kundenkonto zu und stellen sie Ihnen – soweit für Sie relevant – hier in Ihrem Portal unter „Dokumente" und „Nachrichten" bereit.') }}
    </p>
</div>

<div class="card">
    <div class="card-title">🔗 {{ __('E-Mail-Verbindung') }}</div>
    <p style="font-size:14px;color:var(--ink);line-height:1.6;">
        {{ __('Optional koennen Sie vertragsbezogene Post automatisch Ihrem Konto zuordnen lassen - freiwillig und jederzeit widerrufbar.') }}
    </p>
    <a href="{{ route('portal.email_connection') }}" class="btn" style="margin-top:8px;display:inline-block;">{{ __('E-Mail-Verbindung verwalten') }}</a>
</div>

<div class="card">
    <div class="card-title">🔒 {{ __('Wie wir mit Ihren Daten umgehen') }}</div>
    <ul style="font-size:14px;color:var(--ink);line-height:1.8;padding-left:20px;margin:0;">
        <li>{{ __('Die Verarbeitung erfolgt') }} <strong>{{ __('ausschließlich zur Betreuung Ihrer Verträge') }}</strong>.</li>
        <li>{{ __('Sensible Daten (z. B. Bankverbindung) werden') }} <strong>{{ __('verschlüsselt') }}</strong> {{ __('gespeichert.') }}</li>
        <li>{{ __('Ihre Daten geben wir') }} <strong>{{ __('nicht unbefugt an Dritte') }}</strong> {{ __('weiter.') }}</li>
        <li>{{ __('Jede automatische Zuordnung wird von unseren Mitarbeitern geprüft und freigegeben.') }}</li>
        <li>{{ __('Sie haben jederzeit das Recht auf Auskunft, Berichtigung und Löschung Ihrer Daten.') }}</li>
    </ul>
</div>

<div class="card">
    <div class="card-title">✉️ {{ __('Ihre Rechte') }} &amp; {{ __('Kontakt') }}</div>
    <p style="font-size:14px;color:var(--ink);line-height:1.6;">
        {{ __('Für Fragen zum Datenschutz oder zur Ausübung Ihrer Rechte erreichen Sie uns unter') }}
        <a href="mailto:info@dienstly24.de" style="color:var(--petrol);font-weight:600;">info@dienstly24.de</a>.
        {{ __('Es gelten unsere allgemeinen Datenschutzhinweise.') }}
    </p>
</div>
@endsection
