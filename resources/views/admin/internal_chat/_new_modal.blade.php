<div id="new-conv-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:520px;position:relative;max-height:90vh;overflow-y:auto;">
        <button onclick="document.getElementById('new-conv-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:18px;">Neue interne Unterhaltung</div>
        <form method="POST" action="{{ route('admin.chat.store') }}">
            @csrf
            <div class="field"><label>Betreff *</label><input type="text" name="subject" required maxlength="255"></div>
            <div class="field"><label>Team einladen (optional)</label>
                <select name="team">
                    <option value="">— Kein ganzes Team —</option>
                    <option value="support">Gesamter Support</option>
                    <option value="manager">Alle Manager</option>
                    <option value="admin">Alle Admins</option>
                    <option value="all">Alle Mitarbeiter</option>
                </select>
            </div>
            <div class="field"><label>Teilnehmer *</label>
                <div style="max-height:200px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:10px;">
                    @foreach($staff as $u)
                    <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13.5px;cursor:pointer;">
                        <input type="checkbox" name="participants[]" value="{{ $u->id }}" style="width:auto;">
                        {{ $u->name }} <span style="font-size:11px;color:var(--ink-soft);">({{ ['admin'=>'Admin','manager'=>'Manager','support'=>'Support','employee'=>'Mitarbeiter'][$u->role] ?? $u->role }})</span>
                    </label>
                    @endforeach
                </div>
                <p style="font-size:11.5px;color:var(--ink-soft);margin-top:6px;">Nur Mitarbeiter – Kunden erscheinen hier nie.</p>
            </div>
            <div class="field"><label>Erste Nachricht *</label><textarea name="body" required maxlength="5000" style="width:100%;min-height:80px;padding:10px;border:1px solid var(--line);border-radius:8px;font-family:inherit;"></textarea></div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Unterhaltung starten</button>
        </form>
    </div>
</div>
