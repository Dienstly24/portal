@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Einstellungen</span></div>
    <div class="page-title">Einstellungen</div>
    <div class="page-sub">Systemkonfiguration und Integrationen</div>
</div>

<form method="POST" action="{{ route('admin.settings.update') }}">
@csrf @method('PUT')

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px;">

<div class="card">
    <div class="card-title" style="margin-bottom:20px;">🏢 Unternehmen</div>
    <div class="field"><label>Firmenname</label><input type="text" name="company_name" value="{{ $settings['company_name'] }}"></div>
    <div class="field"><label>E-Mail</label><input type="email" name="company_email" value="{{ $settings['company_email'] }}"></div>
    <div class="field"><label>Telefon</label><input type="tel" name="company_phone" value="{{ $settings['company_phone'] }}"></div>
    <div class="field"><label>Adresse</label><input type="text" name="company_address" value="{{ $settings['company_address'] }}"></div>
</div>

<div class="card">
    <div class="card-title" style="margin-bottom:20px;">🔗 Portal URLs</div>
    <div class="field"><label>Kunden-Portal URL</label><input type="text" name="portal_url" value="{{ $settings['portal_url'] }}"></div>
    <div class="field"><label>Admin URL</label><input type="text" name="admin_url" value="{{ $settings['admin_url'] }}"></div>
    <div class="field">
        <label>Vertrags-Erinnerung (Tage vor Ablauf)</label>
        <input type="text" name="contract_reminder_days" value="{{ $settings['contract_reminder_days'] }}" placeholder="30,14,7">
        <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">Kommagetrennte Tagesangaben</div>
    </div>
</div>

<div class="card">
    <div class="card-title" style="margin-bottom:20px;">📧 E-Mail Einstellungen</div>
    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="welcome_email_enabled" value="1" style="width:auto;" {{ $settings['welcome_email_enabled'] ? 'checked' : '' }}>
            Willkommens-E-Mail bei neuem Kunden senden
        </label>
    </div>
    <div style="background:#F4F5F7;border-radius:8px;padding:14px;font-size:13px;">
        <div style="font-weight:600;margin-bottom:8px;">SMTP Konfiguration</div>
        <div style="color:var(--ink-soft);">Host: smtp.hostinger.com · Port: 587</div>
        <div style="color:var(--ink-soft);margin-top:4px;">Von: noreply@dienstly24.de</div>
    </div>
</div>

<div class="card">
    <div class="card-title" style="margin-bottom:20px;">🔌 Integrationen</div>
    <div class="field">
        <label>lexoffice API Key</label>
        <input type="text" name="lexoffice_api_key" value="{{ $settings['lexoffice_api_key'] }}" placeholder="API Key eingeben">
        <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">
            <a href="https://app.lexoffice.de/addons/public-api" target="_blank" style="color:var(--petrol);">API Key generieren →</a>
        </div>
    </div>
    <div style="background:#E4F0E7;border-radius:8px;padding:12px;font-size:13px;color:#3B7A57;">
        ✅ lexoffice verbunden — 1031 Kontakte importiert
    </div>
</div>

<div class="card">
    <div class="card-title" style="margin-bottom:8px;">📜 Rechtliches</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;">
        Inhalte der öffentlichen Portal-Seiten
        <a href="{{ url('/impressum') }}" target="_blank" style="color:var(--petrol);">/impressum</a> ·
        <a href="{{ url('/agb') }}" target="_blank" style="color:var(--petrol);">/agb</a> ·
        <a href="{{ url('/datenschutz') }}" target="_blank" style="color:var(--petrol);">/datenschutz</a> ·
        <a href="{{ url('/cookie-richtlinie') }}" target="_blank" style="color:var(--petrol);">/cookie-richtlinie</a> ·
        <a href="{{ url('/kontakt') }}" target="_blank" style="color:var(--petrol);">/kontakt</a>.
        Leer = sinnvoller Standardtext; Firmendaten kommen aus „Unternehmen" oben.
    </div>
    <div class="field"><label>Impressum – Zusatzangaben (Inhaber, USt-IdNr., Aufsichtsbehörde …)</label>
        <textarea name="legal_impressum" rows="4">{{ $settings['legal_impressum'] }}</textarea></div>
    <div class="field"><label>AGB – vollständiger Text (ersetzt den Standardtext)</label>
        <textarea name="legal_agb" rows="6">{{ $settings['legal_agb'] }}</textarea></div>
    <div class="field"><label>Datenschutzerklärung – Zusatzabschnitte</label>
        <textarea name="legal_datenschutz" rows="4">{{ $settings['legal_datenschutz'] }}</textarea></div>
    <div class="field"><label>Cookie-Richtlinie – eigener Text (ersetzt den Standardtext)</label>
        <textarea name="legal_cookies" rows="4">{{ $settings['legal_cookies'] }}</textarea></div>
</div>

</div>

<div style="margin-top:20px;max-width:900px;">
    <button type="submit" class="btn btn-primary">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Einstellungen speichern
    </button>
</div>
</form>
@endsection
