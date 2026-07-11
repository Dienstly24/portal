@php $a = $account ?? null; @endphp
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px;">

<div class="card">
    <div class="card-title" style="margin-bottom:20px;">📧 Postfach</div>
    <div class="field"><label>Anzeigename</label><input type="text" name="name" value="{{ old('name', $a->name ?? '') }}" required></div>
    <div class="field"><label>E-Mail-Adresse</label><input type="email" name="email_address" value="{{ old('email_address', $a->email_address ?? '') }}" required></div>
    <div class="field">
        <label>Anbieter</label>
        <select name="provider" id="provider-select" required>
            @foreach(\App\Models\EmailAccount::PROVIDERS as $key => $label)
                <option value="{{ $key }}" {{ old('provider', $a->provider ?? 'hostinger_imap') === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label>Überwachte Ordner (kommagetrennt)</label>
        <input type="text" name="folders" value="{{ old('folders', $a ? implode(', ', $a->watchedFolders()) : 'INBOX') }}">
    </div>
    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="is_active" value="1" style="width:auto;" {{ old('is_active', $a->is_active ?? true) ? 'checked' : '' }}>
            Aktiv (wird beim Sync berücksichtigt)
        </label>
    </div>
</div>

<div class="card" id="imap-fields">
    <div class="card-title" style="margin-bottom:20px;">🔌 IMAP-Zugangsdaten</div>
    <div style="font-size:12px;color:var(--ink-soft);margin-bottom:12px;">Gilt für Hostinger und generisches IMAP/SMTP. Für Gmail/Microsoft 365 ist die OAuth-Anbindung als Folgeschritt geplant (siehe Systemanalyse).</div>
    <div class="field"><label>IMAP-Host</label><input type="text" name="imap_host" value="{{ old('imap_host', $a->imap_host ?? '') }}" placeholder="imap.hostinger.com"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="field"><label>Port</label><input type="number" name="imap_port" value="{{ old('imap_port', $a->imap_port ?? 993) }}"></div>
        <div class="field">
            <label>Verschlüsselung</label>
            <select name="imap_encryption">
                @foreach(['ssl'=>'SSL','tls'=>'TLS','none'=>'Keine'] as $k=>$l)
                    <option value="{{ $k }}" {{ old('imap_encryption', $a->imap_encryption ?? 'ssl') === $k ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="field"><label>Benutzername</label><input type="text" name="username" value="{{ old('username', $a->username ?? '') }}" placeholder="entspricht meist der E-Mail-Adresse"></div>
    <div class="field">
        <label>Passwort {{ $a ? '(leer lassen, um es unverändert zu behalten)' : '' }}</label>
        <input type="password" name="password" autocomplete="new-password" placeholder="{{ $a ? '••••••••' : '' }}">
        <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">Wird verschlüsselt gespeichert (AES via APP_KEY), nie im Klartext angezeigt.</div>
    </div>
</div>

</div>
