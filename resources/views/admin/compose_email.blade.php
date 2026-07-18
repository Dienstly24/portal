@extends('layouts.admin')
@section('content')

<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <span>E-Mail verfassen</span>
    </div>
    <div class="page-title">✉️ E-Mail verfassen</div>
    <div style="font-size:14px;color:var(--ink-soft);">Kunde suchen, Vorlage wählen, prüfen, senden – Platzhalter und Anrede werden automatisch gefüllt.</div>
</div>

<style>
.compose-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start;}
@media (max-width:1100px){.compose-grid{grid-template-columns:1fr;}}
.sc-result{display:flex;gap:10px;align-items:center;padding:9px 10px;border-radius:8px;cursor:pointer;border:1px solid transparent;}
.sc-result:hover{background:var(--canvas);border-color:var(--line);}
.sc-star{border:none;background:none;cursor:pointer;font-size:15px;padding:2px 4px;flex:none;opacity:.85;}
.sc-chip{font-size:11px;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:2px 8px;color:var(--ink-soft);}
.tpl-chip{font-size:12px;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:4px 11px;cursor:pointer;transition:.15s;}
.tpl-chip:hover,.tpl-chip.active{background:var(--petrol);color:#fff;border-color:var(--petrol);}
.tpl-item{display:block;width:100%;text-align:left;padding:8px 10px;border-radius:8px;border:1px solid transparent;background:none;cursor:pointer;font-size:13px;font-family:inherit;}
.tpl-item:hover{background:var(--canvas);border-color:var(--line);}
.hist-row{display:flex;gap:8px;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line);align-items:baseline;}
.hist-row:last-child{border-bottom:none;}
#sc-suggest{display:none;margin-top:6px;font-size:12.5px;background:#F0F7F3;border:1px solid #CBE3D2;border-radius:8px;padding:7px 12px;color:#2F6B4A;}
#sc-suggest b{font-weight:600;}
#sc-suggest .tabhint{float:right;font-size:11px;background:#fff;border:1px solid #CBE3D2;border-radius:5px;padding:1px 7px;color:var(--ink-soft);}
.kbadge{font-size:10.5px;font-weight:700;background:var(--canvas);border:1px solid var(--line);border-radius:5px;padding:1px 6px;}
</style>

<div class="compose-grid">

{{-- ===================== Hauptspalte: Editor ===================== --}}
<div>
<div class="card">
    <form id="compose-form" method="POST" action="{{ route('admin.email.compose.send') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="customer_id" id="f-customer-id" value="{{ $customer?->id }}">
        <div class="field">
            <label>Empfänger (E-Mail) *</label>
            <input type="email" name="to" id="f-to" required maxlength="190" list="to-suggestions" autocomplete="off"
                value="{{ old('to', $customer?->user?->email && !str_contains($customer->user->email, '@dienstly24.internal') ? $customer->user->email : '') }}"
                placeholder="Kunde suchen (rechts) oder E-Mail direkt eingeben – z. B. service@gesellschaft.de">
            <datalist id="to-suggestions"></datalist>
        </div>
        <div class="field">
            <label>Betreff *</label>
            <input type="text" name="subject" id="f-subject" required maxlength="200" value="{{ old('subject') }}">
        </div>
        <div id="anrede-row" style="display:none;margin:-6px 0 12px;">
            <span style="font-size:12px;color:var(--ink-soft);margin-right:6px;">Anrede einfügen:</span>
            <button type="button" class="tpl-chip" onclick="insertSalutation('formell')">Formell</button>
            <button type="button" class="tpl-chip" onclick="insertSalutation('locker')">Locker („Hallo Vorname")</button>
        </div>
        <div class="field" style="margin-bottom:6px;">
            <label>Nachricht *</label>
            <textarea name="body" id="f-body" required maxlength="10000" rows="13"
                placeholder="Text schreiben, Vorlage wählen (rechts) oder ✨ KI-Entwurf nutzen. Während des Tippens werden Satz-Vervollständigungen vorgeschlagen – mit Tab übernehmen.">{{ old('body') }}</textarea>
            <div id="sc-suggest"><span class="tabhint">Tab ⇥</span>💡 <b id="sc-suggest-text"></b></div>
        </div>
        <div class="field">
            <label>📎 Anhänge (optional, max. 5 · PDF/JPG/PNG/WEBP/DOC/DOCX, je max. 10 MB)</label>
            <input type="file" name="attachments[]" id="f-attachments" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn btn-gold">✉️ E-Mail senden</button>
            <button type="button" class="btn btn-ghost" onclick="openPreview()">👁️ Vorschau</button>
            @if($aiAvailable)
            <button type="button" class="btn btn-ghost" onclick="toggleAiPanel()">✨ KI-Entwurf</button>
            @endif
            <a href="{{ route('admin.email.compose') }}" class="btn btn-ghost" style="margin-left:auto;">↺ Leeren</a>
        </div>
        @if($aiAvailable)
        <div id="ai-panel" style="display:none;margin-top:14px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px;">
            <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Worum soll es in der E-Mail gehen?</label>
            <textarea id="ai-goal" rows="2" maxlength="1000" placeholder="z. B. Angebot zur KFZ-Versicherung nachfassen und um Rückmeldung bis Freitag bitten" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;"></textarea>
            <div style="display:flex;gap:10px;align-items:center;margin-top:8px;">
                <button type="button" class="btn btn-primary btn-sm" id="ai-go" onclick="requestAiDraft()">Entwurf erstellen</button>
                <span id="ai-status" style="font-size:12.5px;color:var(--ink-soft);"></span>
            </div>
            <p style="font-size:11.5px;color:var(--ink-soft);margin-top:8px;">Die KI nutzt Kundenname, Anrede und die letzten Interaktionen als Kontext. Der Entwurf wird nie automatisch gesendet – Sie prüfen und senden selbst.</p>
        </div>
        @endif
    </form>
</div>

{{-- Platzhalter-Referenz --}}
<div class="card" style="margin-top:16px;">
    <div class="card-title" style="margin-bottom:10px;">🧩 Platzhalter <span style="font-weight:400;font-size:12px;color:var(--ink-soft);">(Klick fügt an Cursor-Position ein)</span></div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        @foreach($placeholders as $key => $desc)
        <span title="{{ $desc }}" onclick="insertPlaceholder('{{ $key }}')" style="font-size:12.5px;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:4px 12px;font-family:monospace;cursor:pointer;">&#123;&#123;{{ $key }}&#125;&#125;</span>
        @endforeach
    </div>
</div>
</div>

{{-- ===================== Seitenleiste ===================== --}}
<div>
{{-- Kundensuche + Kundenkarte --}}
<div class="card">
    <div class="card-title" style="margin-bottom:10px;">🔍 Kunde</div>
    <input type="text" id="sc-search" autocomplete="off" placeholder="Name, E-Mail, Nummer oder Firma…"
        style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    <div id="sc-results" style="margin-top:8px;"></div>

    <div id="sc-card" style="display:none;margin-top:10px;border:1px solid var(--line);border-radius:10px;padding:12px;background:var(--canvas);">
        <div style="display:flex;align-items:flex-start;gap:8px;">
            <div style="flex:1;">
                <div style="font-weight:700;font-size:14px;" id="cc-name"></div>
                <div style="font-size:12px;color:var(--ink-soft);" id="cc-sub"></div>
            </div>
            <button type="button" class="sc-star" id="cc-star" title="Als Favorit merken" onclick="toggleFavorite()">☆</button>
            <button type="button" class="sc-star" title="Kundenbezug entfernen" onclick="clearCustomer()">✕</button>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:3px 10px;font-size:12.5px;margin-top:8px;color:var(--ink-soft);" id="cc-details"></div>
        <div id="cc-history-wrap" style="display:none;margin-top:10px;">
            <div style="font-size:12px;font-weight:700;margin-bottom:3px;">Letzte Aktivitäten</div>
            <div id="cc-history"></div>
        </div>
    </div>
</div>

{{-- Vorlagen --}}
<div class="card" style="margin-top:16px;">
    <div class="card-title" style="margin-bottom:10px;">📄 Vorlagen</div>
    <input type="text" id="tpl-search" autocomplete="off" placeholder="Vorlage suchen… z. B. angebot"
        style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;" id="tpl-chips">
        <button type="button" class="tpl-chip" data-q="angebot">Angebot</button>
        <button type="button" class="tpl-chip" data-q="termin">Termin</button>
        <button type="button" class="tpl-chip" data-q="unterlagen">Unterlagen</button>
        <button type="button" class="tpl-chip" data-q="rueckruf">Rückruf</button>
        <button type="button" class="tpl-chip" data-q="kuendigung">Kündigung</button>
        <button type="button" class="tpl-chip" data-q="status">Status</button>
    </div>
    <div id="tpl-list" style="margin-top:10px;max-height:300px;overflow-y:auto;"></div>
    @if(in_array(auth()->user()->role, ['admin','manager']))
    <a href="{{ route('admin.templates') }}" style="font-size:12px;color:var(--ink-soft);display:inline-block;margin-top:8px;">Vorlagen verwalten →</a>
    @endif
</div>
</div>
</div>

{{-- Vorschau-Modal --}}
<div id="preview-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:120;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#f4f5f7;border-radius:14px;width:640px;max-width:95vw;max-height:92vh;overflow-y:auto;position:relative;padding:22px;">
        <button onclick="document.getElementById('preview-modal').style.display='none'" style="position:absolute;top:12px;right:14px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:10px;">An: <span id="pv-to"></span> · Betreff: <strong id="pv-subject"></strong> <span id="pv-att"></span></div>
        <div style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);">
            <div style="background:#17191d;padding:18px 24px;"><span style="color:#fff;font-size:18px;font-weight:bold;">Dienstly<span style="color:#17A65B;">24</span></span></div>
            <div style="padding:24px;font-size:14px;color:#333;line-height:1.7;" id="pv-body"></div>
            <div style="background:#f4f5f7;padding:12px 24px;font-size:11.5px;color:#888;">Dienstly24 – Ihr Experte für Versicherungen &amp; Energie<br>Ihr Ansprechpartner: {{ auth()->user()->name }}</div>
        </div>
    </div>
</div>

<script type="application/json" id="tpl-data">@json($templates)</script>
<script>
const TEMPLATES = JSON.parse(document.getElementById('tpl-data').textContent);
const INITIAL_CUSTOMER = @json($customer?->id);
const CSRF = '{{ csrf_token() }}';
let selectedCustomer = null;   // {id, email, salutations, favorite, ...}
let searchTimer = null;

/* ---------------- Kundensuche ---------------- */
const scSearch = document.getElementById('sc-search');
scSearch.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => searchCustomers(scSearch.value.trim()), 220);
});
scSearch.addEventListener('focus', () => { if (!scSearch.value.trim()) searchCustomers(''); });

function searchCustomers(q) {
    fetch('{{ route('admin.email.customer_search') }}?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => renderResults(d.customers || []))
        .catch(() => {});
}

function renderResults(customers) {
    const box = document.getElementById('sc-results');
    box.innerHTML = '';
    customers.forEach(c => {
        const row = document.createElement('div');
        row.className = 'sc-result';
        const star = document.createElement('button');
        star.type = 'button'; star.className = 'sc-star';
        star.textContent = c.favorite ? '⭐' : '☆';
        star.title = 'Favorit umschalten';
        star.onclick = (ev) => { ev.stopPropagation(); toggleFavoriteFor(c.id, star); };
        const info = document.createElement('div');
        info.style.cssText = 'flex:1;min-width:0;';
        const sub = [c.number, c.company, c.email].filter(Boolean).join(' · ');
        const extra = c.last_contact ? ' · Kontakt ' + c.last_contact : '';
        info.innerHTML = '<div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>'
            + '<div style="font-size:11.5px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>';
        info.children[0].textContent = c.name + (c.lang === 'AR' ? ' 🌐AR' : '');
        info.children[1].textContent = sub + extra;
        row.appendChild(star); row.appendChild(info);
        row.onclick = () => selectCustomer(c.id);
        box.appendChild(row);
    });
    if (!customers.length) box.innerHTML = '<div style="font-size:12.5px;color:var(--ink-soft);padding:8px 4px;">Keine Treffer.</div>';
}

/* ---------------- Kundenkarte + Kontext ---------------- */
function selectCustomer(id) {
    fetch('{{ url('admin/email/kunden-kontext') }}/' + id, {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(c => {
            selectedCustomer = c;
            document.getElementById('f-customer-id').value = c.id;
            if (c.email) document.getElementById('f-to').value = c.email;
            document.getElementById('sc-results').innerHTML = '';
            scSearch.value = '';
            document.getElementById('anrede-row').style.display = '';
            renderCard(c);
            // Leerer Editor: formelle Anrede direkt vorschlagen
            const body = document.getElementById('f-body');
            if (!body.value.trim()) body.value = c.salutations.formell + '\n\n';
        })
        .catch(() => {});
}

function renderCard(c) {
    document.getElementById('sc-card').style.display = '';
    document.getElementById('cc-name').textContent = c.name;
    document.getElementById('cc-sub').textContent = ['Nr. ' + c.number, c.company].filter(Boolean).join(' · ');
    document.getElementById('cc-star').textContent = c.favorite ? '⭐' : '☆';
    const rows = [];
    if (c.email) rows.push(['E-Mail', c.email]);
    if (c.phone) rows.push(['Telefon', c.phone]);
    rows.push(['Sprache', c.lang]);
    if (c.betreuer) rows.push(['Betreuer', c.betreuer]);
    if (c.last_contact) rows.push(['Letzter Kontakt', c.last_contact]);
    document.getElementById('cc-details').innerHTML = rows
        .map(() => '<span></span><strong style="color:var(--ink);font-weight:600;"></strong>').join('');
    const cells = document.getElementById('cc-details').children;
    rows.forEach((r, i) => { cells[i * 2].textContent = r[0]; cells[i * 2 + 1].textContent = r[1]; });
    const hist = document.getElementById('cc-history');
    hist.innerHTML = '';
    (c.history || []).forEach(h => {
        const row = document.createElement('div');
        row.className = 'hist-row';
        row.innerHTML = '<span style="flex:none;"></span><span style="flex:1;min-width:0;"></span><span style="flex:none;color:var(--ink-soft);font-size:11px;"></span>';
        row.children[0].textContent = h.icon;
        row.children[1].textContent = h.text;
        row.children[2].textContent = h.date;
        hist.appendChild(row);
    });
    document.getElementById('cc-history-wrap').style.display = c.history && c.history.length ? '' : 'none';
}

function clearCustomer() {
    selectedCustomer = null;
    document.getElementById('f-customer-id').value = '';
    document.getElementById('sc-card').style.display = 'none';
    document.getElementById('anrede-row').style.display = 'none';
}

function toggleFavorite() { if (selectedCustomer) toggleFavoriteFor(selectedCustomer.id, document.getElementById('cc-star')); }
function toggleFavoriteFor(id, el) {
    fetch('{{ url('admin/email/favorit') }}/' + id, {method: 'POST', headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => { el.textContent = d.favorite ? '⭐' : '☆'; })
        .catch(() => {});
}

/* Empfaenger-Feld: E-Mail-Autovervollstaendigung aus der Kundensuche */
document.getElementById('f-to').addEventListener('input', function () {
    const q = this.value.trim();
    if (q.length < 2 || q.includes('@')) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        fetch('{{ route('admin.email.customer_search') }}?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
            .then(r => r.json())
            .then(d => {
                const dl = document.getElementById('to-suggestions');
                dl.innerHTML = '';
                (d.customers || []).filter(c => c.email).forEach(c => {
                    const o = document.createElement('option');
                    o.value = c.email; o.label = c.name;
                    dl.appendChild(o);
                });
            }).catch(() => {});
    }, 220);
});

/* ---------------- Anrede ---------------- */
function insertSalutation(kind) {
    if (!selectedCustomer) return;
    const body = document.getElementById('f-body');
    const line = selectedCustomer.salutations[kind];
    const lines = body.value.split('\n');
    // Vorhandene Anrede in Zeile 1 ersetzen, sonst oben einfuegen
    if (/^(sehr geehrte|hallo|guten tag|liebe)/i.test((lines[0] || '').trim())) {
        lines[0] = line;
        body.value = lines.join('\n');
    } else {
        body.value = line + '\n\n' + body.value;
    }
    body.focus();
}

/* ---------------- Vorlagen ---------------- */
function renderTemplates(q) {
    const list = document.getElementById('tpl-list');
    const needle = (q || '').toLowerCase();
    const matches = TEMPLATES.filter(t => !needle
        || t.name.toLowerCase().includes(needle)
        || (t.subject || '').toLowerCase().includes(needle));
    list.innerHTML = '';
    ['kunde', 'gesellschaft'].forEach(cat => {
        const group = matches.filter(t => t.category === cat);
        if (!group.length) return;
        const head = document.createElement('div');
        head.style.cssText = 'font-size:11px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.04em;margin:8px 0 3px;';
        head.textContent = cat === 'kunde' ? '👤 Kunden' : '🏢 Gesellschaften';
        list.appendChild(head);
        group.forEach(t => {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'tpl-item';
            b.textContent = t.name;
            b.onclick = () => applyTemplate(t.id);
            list.appendChild(b);
        });
    });
    if (!matches.length) list.innerHTML = '<div style="font-size:12.5px;color:var(--ink-soft);padding:6px 2px;">Keine Vorlage gefunden.</div>';
    return matches;
}
document.getElementById('tpl-search').addEventListener('input', function () { renderTemplates(this.value); });
document.querySelectorAll('#tpl-chips .tpl-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        document.querySelectorAll('#tpl-chips .tpl-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        document.getElementById('tpl-search').value = chip.dataset.q;
        const matches = renderTemplates(chip.dataset.q);
        if (matches.length) applyTemplate(matches[0].id);   // Ein Klick = Vorlage + Betreff
    });
});

function applyTemplate(id) {
    const cid = document.getElementById('f-customer-id').value;
    fetch('{{ url('admin/vorlagen') }}/' + id + '/render' + (cid ? '?customer_id=' + cid : ''), {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => {
            if (d.subject) document.getElementById('f-subject').value = d.subject;
            document.getElementById('f-body').value = d.body || '';
        })
        .catch(() => {});
}

/* ---------------- Smart Compose (Tab-Vervollstaendigung) ---------------- */
const PHRASES = [
    'Vielen Dank für Ihre Anfrage.',
    'Vielen Dank für Ihre Rückmeldung.',
    'Vielen Dank für das freundliche Gespräch.',
    'Vielen Dank für Ihr Vertrauen.',
    'Wir freuen uns auf Ihre Rückmeldung.',
    'Wir freuen uns, Ihnen behilflich sein zu können.',
    'Anbei erhalten Sie unser aktuelles Angebot.',
    'Anbei erhalten Sie die gewünschten Unterlagen.',
    'Für Rückfragen stehen wir Ihnen jederzeit gerne zur Verfügung.',
    'Bei Fragen erreichen Sie uns telefonisch oder per E-Mail.',
    'Bitte lassen Sie uns die fehlenden Unterlagen zukommen.',
    'Bitte senden Sie uns die unterschriebenen Unterlagen zurück.',
    'Bitte bestätigen Sie uns kurz den Erhalt dieser E-Mail.',
    'Gerne unterbreiten wir Ihnen ein unverbindliches Angebot.',
    'Gerne vereinbaren wir einen Termin mit Ihnen.',
    'Wir haben Ihre Unterlagen erhalten und melden uns schnellstmöglich.',
    'Wir prüfen Ihr Anliegen und melden uns umgehend bei Ihnen.',
    'Wir bitten um kurze Rückmeldung bis zum genannten Termin.',
    'Sollten Sie noch Fragen haben, melden Sie sich gerne.',
    'Der Termin wurde wie besprochen eingetragen.',
    'Mit freundlichen Grüßen',
];
const bodyEl = document.getElementById('f-body');
let currentSuggestion = null;

bodyEl.addEventListener('input', updateSuggestion);
bodyEl.addEventListener('click', updateSuggestion);
bodyEl.addEventListener('keydown', function (e) {
    if (e.key === 'Tab' && currentSuggestion) {
        e.preventDefault();
        const pos = bodyEl.selectionStart;
        bodyEl.value = bodyEl.value.slice(0, pos) + currentSuggestion.completion + bodyEl.value.slice(pos);
        bodyEl.selectionStart = bodyEl.selectionEnd = pos + currentSuggestion.completion.length;
        hideSuggestion();
    } else if (e.key === 'Escape') {
        hideSuggestion();
    }
});

function updateSuggestion() {
    const pos = bodyEl.selectionStart;
    const before = bodyEl.value.slice(0, pos);
    // Aktuelles Satzfragment: nach letztem Satzende/Zeilenumbruch
    // (bewusst ohne Regex-Lookbehind - aeltere Safari-Versionen wuerfen
    // sonst beim Parsen des gesamten Scripts einen Syntaxfehler)
    let idx = -1, skip = 0;
    [['\n', 1], ['. ', 2], ['! ', 2], ['? ', 2]].forEach(function (sep) {
        const i = before.lastIndexOf(sep[0]);
        if (i > idx) { idx = i; skip = sep[1]; }
    });
    const frag = (idx >= 0 ? before.slice(idx + skip) : before).trimStart();
    if (frag.length < 3) return hideSuggestion();
    const lower = frag.toLowerCase();
    const hit = PHRASES.find(p => p.toLowerCase().startsWith(lower) && p.length > frag.length);
    if (!hit) return hideSuggestion();
    currentSuggestion = {completion: hit.slice(frag.length)};
    document.getElementById('sc-suggest-text').textContent = hit;
    document.getElementById('sc-suggest').style.display = 'block';
}
function hideSuggestion() {
    currentSuggestion = null;
    document.getElementById('sc-suggest').style.display = 'none';
}

/* ---------------- KI-Entwurf ---------------- */
function toggleAiPanel() {
    const p = document.getElementById('ai-panel');
    p.style.display = p.style.display === 'none' ? '' : 'none';
    if (p.style.display !== 'none') document.getElementById('ai-goal').focus();
}
function requestAiDraft() {
    const goal = document.getElementById('ai-goal').value.trim();
    if (!goal) { document.getElementById('ai-goal').focus(); return; }
    if (document.getElementById('f-body').value.trim()
        && !confirm('Der aktuelle Text wird durch den KI-Entwurf ersetzt. Fortfahren?')) return;
    const btn = document.getElementById('ai-go'), status = document.getElementById('ai-status');
    btn.disabled = true; status.textContent = '✨ Entwurf wird erstellt…';
    fetch('{{ route('admin.email.ai_draft') }}', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json'},
        body: JSON.stringify({
            goal: goal,
            customer_id: document.getElementById('f-customer-id').value || null,
            category: 'kunde',
        }),
    })
        .then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || 'Fehler'); return d; })
        .then(d => {
            if (d.subject && !document.getElementById('f-subject').value.trim()) document.getElementById('f-subject').value = d.subject;
            document.getElementById('f-body').value = d.body || '';
            status.textContent = '✓ Entwurf eingefügt – bitte prüfen.';
        })
        .catch(e => { status.textContent = '⚠ ' + e.message; })
        .finally(() => { btn.disabled = false; });
}

/* ---------------- Platzhalter, Vorschau, Versand-Checks ---------------- */
function insertPlaceholder(key) {
    const el = document.getElementById('f-body');
    const token = '{' + '{' + key + '}' + '}';
    const start = el.selectionStart ?? el.value.length;
    el.value = el.value.slice(0, start) + token + el.value.slice(el.selectionEnd ?? start);
    el.focus();
    el.selectionStart = el.selectionEnd = start + token.length;
}

function openPreview() {
    document.getElementById('pv-to').textContent = document.getElementById('f-to').value || '—';
    document.getElementById('pv-subject').textContent = document.getElementById('f-subject').value || '—';
    const files = document.getElementById('f-attachments').files;
    document.getElementById('pv-att').textContent = files.length ? '· 📎 ' + files.length + ' Anhang/Anhänge' : '';
    const body = document.getElementById('f-body').value;
    const pv = document.getElementById('pv-body');
    pv.textContent = body; pv.innerHTML = pv.innerHTML.replace(/\n/g, '<br>');
    document.getElementById('preview-modal').style.display = 'flex';
}
document.getElementById('preview-modal').addEventListener('click', function (e) { if (e.target === this) this.style.display = 'none'; });

document.getElementById('compose-form').addEventListener('submit', function (e) {
    const body = document.getElementById('f-body').value;
    const files = document.getElementById('f-attachments').files;
    // Pruefung vor dem Senden: Anhang erwaehnt, aber keiner beigefuegt?
    if (!files.length && /anbei|im anhang|beigef(ü|ue)gt|angeh(ä|ae)ngt|anhang/i.test(body)) {
        if (!confirm('Sie erwähnen einen Anhang, es ist aber keine Datei beigefügt. Trotzdem senden?')) {
            e.preventDefault();
            return;
        }
    }
    // Platzhalter uebrig geblieben?
    if (/\{\{\s*[a-z]+\s*\}\}/i.test(body) && !confirm('Im Text stehen noch unausgefüllte Platzhalter. Trotzdem senden?')) {
        e.preventDefault();
    }
});

/* ---------------- Initialisierung ---------------- */
renderTemplates('');
if (INITIAL_CUSTOMER) selectCustomer(INITIAL_CUSTOMER);
</script>

@endsection
