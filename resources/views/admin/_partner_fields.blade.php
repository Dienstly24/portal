<div style="display:grid;gap:12px;">
    <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Name *</label>
        <input type="text" name="name" value="{{ old('name', $partner->name ?? '') }}" required maxlength="255" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
        @error('name')<div style="color:#B3261E;font-size:12px;margin-top:3px;">{{ $message }}</div>@enderror
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Partner-Nr.</label>
            <input type="text" name="partner_number" value="{{ old('partner_number', $partner->partner_number ?? '') }}" maxlength="100" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
        </div>
        <div>
            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Kontakt-E-Mail</label>
            <input type="email" name="contact_email" value="{{ old('contact_email', $partner->contact_email ?? '') }}" maxlength="255" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
        </div>
    </div>
    <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Absender-Domains (für automatische Erkennung, kommagetrennt)</label>
        <input type="text" name="email_domains" value="{{ old('email_domains', implode(', ', $partner->email_domains ?? [])) }}" placeholder="z. B. fondsfinanz.de, partner-abc.de" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
        <div style="font-size:11px;color:var(--ink-soft);margin-top:3px;">Eingehende Provisions-Mails von diesen Domains werden diesem Partner zugeordnet.</div>
    </div>
    <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">IBAN</label>
        <input type="text" name="iban" value="{{ old('iban', $partner->iban ?? '') }}" maxlength="50" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
    </div>
    <div>
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Notizen</label>
        <textarea name="notes" rows="2" maxlength="5000" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">{{ old('notes', $partner->notes ?? '') }}</textarea>
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $partner->is_active ?? true) ? 'checked' : '' }}> Aktiv
    </label>
</div>
