@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Dokumenten-Eingang</span></div>
    <div>
        <div class="page-title">⚡ Dokumenten-Eingang (KI)</div>
        <div class="page-sub">Dokumente hochladen oder hierher ziehen – die KI erkennt den Typ, liest die Daten und schlägt den passenden Kunden vor.</div>
    </div>
</div>

@if(!$aiEnabled)
<div style="background:#FEF3C7;color:#92400E;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    ⚠ KI-Analyse ist nicht konfiguriert (<code>ANTHROPIC_API_KEY</code> fehlt). Uploads werden gespeichert, aber nicht automatisch analysiert.
</div>
@endif

{{-- Drag&Drop Smart-Upload (ohne Kundenzuordnung -> Eingang) --}}
<div class="card" style="margin-bottom:20px;">
    <div id="inbox-dropzone" style="border:2px dashed var(--line);border-radius:12px;padding:30px;text-align:center;cursor:pointer;transition:.15s;">
        <div style="font-size:34px;margin-bottom:6px;">📥</div>
        <div style="font-size:14px;color:var(--ink-soft);">Dateien hierher ziehen oder <span style="color:var(--gold);font-weight:600;">durchsuchen</span></div>
        <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">PDF, JPG, PNG, WEBP · max. 10 MB pro Datei · mehrere Bilder werden zu EINEM Dokument gebündelt</div>
        <input type="file" id="inbox-files" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;">
    </div>
    <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--ink-soft);margin-top:10px;cursor:pointer;">
        <input type="checkbox" id="inbox-bundle" checked>
        Mehrere Bilder zu EINEM mehrseitigen Dokument bündeln (abwählen = jedes Bild wird ein eigenes Dokument)
    </label>
    <div id="inbox-upload-progress" style="display:none;margin-top:12px;">
        <div style="height:8px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;">
            <div id="inbox-upload-bar" style="height:100%;width:0;background:var(--gold);transition:width .2s;"></div>
        </div>
        <div id="inbox-upload-label" style="font-size:12px;color:var(--ink-soft);margin-top:5px;">0%</div>
    </div>
</div>

{{-- Eingang: nicht zugeordnete Dokumente --}}
@php
    $batchIds = collect($batchGroups ?? [])->flatMap(fn ($g) => $g->pluck('id'))->all();
    $singleDocuments = $inboxDocuments->reject(fn ($d) => in_array($d->id, $batchIds, true));
@endphp
<div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Nicht zugeordnet ({{ $inboxDocuments->count() }})</div>

    {{-- Vorgaenge: gemeinsam hochgeladene Dateien gehoeren zu EINEM Kunden --}}
    @foreach(($batchGroups ?? []) as $batchId => $groupDocs)
    @php $meta = $batchData[$batchId] ?? null; @endphp
    <div style="margin:14px 16px;border:1.5px solid #185FA5;border-radius:12px;overflow:hidden;" data-batch="{{ $batchId }}">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 16px;background:#E6F1FB;">
            <div style="font-weight:700;font-size:13.5px;color:#185FA5;">
                🗂 Ein Vorgang · {{ $groupDocs->count() }} Dokumente (gemeinsam hochgeladen)
            </div>
            <div>
                @if($meta && !empty($meta['conflicts']))
                    <span class="badge" style="background:#FBE9E9;color:#B3261E;">⚠ {{ implode(' ', $meta['conflicts']) }}</span>
                @elseif($meta && $meta['ready'] && $meta['has_name'])
                    <button type="button" class="btn btn-gold btn-sm" onclick="docReview.openBatch(@js($batchId))">
                        Neuen Kunden aus allen {{ $groupDocs->count() }} anlegen
                    </button>
                @elseif($meta && !$meta['ready'])
                    <span class="badge" style="background:#FEF3C7;color:#92400E;">⏳ Analyse läuft noch…</span>
                @endif
            </div>
        </div>
        @foreach($groupDocs as $doc)
            @include('admin.partials.inbox_doc_row', ['doc' => $doc])
        @endforeach
    </div>
    @endforeach

    @forelse($singleDocuments as $doc)
        @include('admin.partials.inbox_doc_row', ['doc' => $doc])
    @empty
        @if(($batchGroups ?? collect())->isEmpty())
        <div style="padding:22px 20px;color:var(--ink-soft);font-size:13.5px;">📭 Keine unzugeordneten Dokumente – alles erledigt.</div>
        @endif
    @endforelse
</div>

{{-- Aktionsleiste fuer manuelle Mehrfachauswahl: beliebige Eingangs-Dokumente
     zu EINEM Kunden buendeln (z.B. getrennt hochgeladene Ausweis-Vorder- und
     -Rueckseite oder Ausweis + Antrag). Erscheint, sobald >= 1 Dokument per
     Checkbox markiert ist. --}}
<div id="inbox-selection-bar" style="display:none;position:fixed;left:50%;transform:translateX(-50%);bottom:22px;z-index:150;background:#101216;color:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.30);padding:11px 16px;align-items:center;gap:14px;flex-wrap:wrap;max-width:calc(100% - 32px);">
    <span style="font-size:13.5px;"><strong id="inbox-selection-count">0</strong>&nbsp;Dokumente ausgewaehlt</span>
    <button type="button" class="btn btn-gold btn-sm" id="inbox-selection-merge" onclick="docReview.openSelection()">🗂 Zu EINEM Kunden zusammenfuehren</button>
    <button type="button" class="btn btn-ghost btn-sm" style="color:#fff;border-color:rgba(255,255,255,.35);" onclick="docReview.clearSelection()">Aufheben</button>
</div>

{{-- Zuletzt analysierte, bereits zugeordnete Dokumente --}}
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Zuletzt analysiert &amp; zugeordnet</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
        <thead>
            <tr style="text-align:start;color:var(--ink-soft);font-size:12px;">
                <th style="text-align:start;padding:10px 20px;">Dokument</th>
                <th style="text-align:start;padding:10px 12px;">Kunde</th>
                <th style="text-align:start;padding:10px 12px;">Erkannt als</th>
                <th style="text-align:start;padding:10px 12px;">Status</th>
                <th style="text-align:start;padding:10px 20px;">Datum</th>
            </tr>
        </thead>
        <tbody>
        @forelse($recentDocuments as $doc)
            <tr style="border-top:1px solid var(--line);" @if($doc->aiInProgress()) data-doc-row="{{ $doc->id }}" data-doc-status="{{ $doc->ai_status }}" @endif>
                <td style="padding:10px 20px;"><a href="{{ route('admin.documents.download', $doc->id) }}">{{ $doc->file_name }}</a></td>
                <td style="padding:10px 12px;">
                    @if($doc->customer)<a href="{{ route('admin.customer', $doc->customer_id) }}#tab-dokumente">{{ $doc->customer->user?->name ?? $doc->customer->customer_number }}</a>@else — @endif
                </td>
                <td style="padding:10px 12px;">{{ $doc->aiTypeLabel() ?? '—' }}</td>
                <td style="padding:10px 12px;">
                    @if($doc->aiInProgress())<span class="badge" style="background:#FEF3C7;color:#92400E;">⏳ läuft</span>
                    @elseif($doc->ai_status === 'done')<span class="badge" style="background:#d9f4e6;color:#128a4b;">✓ analysiert</span>
                    @elseif($doc->ai_status === 'failed')<span class="badge" style="background:#FBE9E9;color:#B3261E;">Fehler</span>
                    @endif
                </td>
                <td style="padding:10px 20px;color:var(--ink-soft);">{{ $doc->created_at->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="padding:18px 20px;color:var(--ink-soft);">Noch keine analysierten Dokumente.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- Review-/Zuordnungs-Modal --}}
<div id="doc-review-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:26px;width:100%;max-width:620px;position:relative;max-height:92vh;overflow-y:auto;">
        <button type="button" onclick="docReview.close()" style="position:absolute;top:14px;right:14px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:17px;font-weight:700;margin-bottom:4px;" id="review-title">Dokument zuordnen</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;" id="review-doc-name"></div>

        <div id="review-body">
        {{-- Kundensuche (Modus: zuordnen) --}}
        <div id="review-assign-block">
            <div class="field" style="margin-bottom:6px;">
                <label>Kunde suchen (Name, Kundennummer, E-Mail, Telefon)</label>
                <input type="text" id="review-customer-q" autocomplete="off" placeholder="Mind. 2 Zeichen…"
                    style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            </div>
            <div id="review-customer-results" style="margin-bottom:10px;"></div>
            <div id="review-customer-chosen" style="display:none;background:#d9f4e6;border:1px solid #17A65B;border-radius:8px;padding:9px 12px;font-size:13.5px;margin-bottom:12px;"></div>
        </div>

        {{-- Neuanlage-Hinweis (Modus: neuer Kunde) --}}
        <div id="review-create-block" style="display:none;background:#E6F1FB;border:1px solid #185FA5;border-radius:8px;padding:10px 12px;font-size:13.5px;margin-bottom:12px;"></div>

        {{-- Krankenkassen-Fall (Familie + Wechsel), nur im Vorgang-Modus bei >= 2 Personen --}}
        <div id="review-family-section" style="display:none;border:1.5px solid #3B7A57;border-radius:10px;padding:12px;margin-bottom:12px;background:#F6FBF8;">
            <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px;cursor:pointer;font-weight:700;">
                <input type="checkbox" id="family-enabled" style="margin-top:2px;">
                <span>🏥 Krankenkassen-Fall einrichten (Familie + Wechsel)</span>
            </label>
            <div id="family-body" style="display:none;margin-top:10px;">
                <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:6px;">Wer ist <strong>hauptversichert</strong>? (meist der Vater – bitte pruefen)</div>
                <div id="family-persons" style="display:grid;gap:6px;margin-bottom:10px;"></div>

                <div style="font-weight:700;font-size:13px;margin-bottom:6px;">Wechsel-Fall</div>
                <div id="family-reasons" style="display:grid;gap:5px;margin-bottom:8px;font-size:13px;">
                    <label style="display:flex;gap:8px;cursor:pointer;"><input type="radio" name="family-reason" value="wechsel" checked> Regulaerer Wechsel (Statusaenderung) – wirksam am 1. des Monats +3</label>
                    <label style="display:flex;gap:8px;cursor:pointer;"><input type="radio" name="family-reason" value="sonder"> Sonderkuendigungsrecht – gleicher Stichtag, als Sonderfall markiert</label>
                    <label style="display:flex;gap:8px;cursor:pointer;"><input type="radio" name="family-reason" value="new_job"> Neue Beschaeftigung – sofort ab Arbeitsbeginn</label>
                </div>
                <div id="family-jobstart-wrap" style="display:none;margin-bottom:8px;">
                    <label style="font-size:12.5px;">Arbeitsbeginn</label>
                    <input type="date" id="family-jobstart" style="width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <div>
                        <label style="font-size:12.5px;">Bisherige Kasse</label>
                        <input type="text" id="family-old-insurer" placeholder="z.B. AOK" style="width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    </div>
                    <div>
                        <label style="font-size:12.5px;">Neue Kasse *</label>
                        <input type="text" id="family-new-insurer" placeholder="z.B. TK" style="width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    </div>
                </div>
                <div id="family-effective-preview" style="font-size:12.5px;color:#3B7A57;font-weight:600;"></div>
            </div>
        </div>

        {{-- Extrahierte Daten --}}
        <div id="review-extract-section" style="display:none;">
            <div style="font-weight:700;font-size:13.5px;margin:6px 0 8px;">Erkannte Daten übernehmen <span style="font-weight:400;color:var(--ink-soft);">(nur leere Felder werden befüllt)</span></div>
            <div id="review-apply-fields" style="display:grid;grid-template-columns:1fr;gap:6px;margin-bottom:12px;"></div>
        </div>

        {{-- Vertrag --}}
        <div id="review-contract-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:12px;">
            <label style="display:flex;gap:9px;align-items:flex-start;font-size:13.5px;cursor:pointer;">
                <input type="checkbox" id="review-create-contract" style="margin-top:2px;">
                <span><strong>Vertrag anlegen/verknüpfen</strong><br><span id="review-contract-info" style="color:var(--ink-soft);font-size:12.5px;"></span></span>
            </label>
        </div>

        <div class="field">
            <label>Sichtbarkeit des Dokuments</label>
            <select id="review-visibility" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="internal">🔒 Nur intern</option>
                <option value="customer">👤 Kundensichtbar</option>
            </select>
        </div>

        <div id="review-error" style="display:none;background:#FBE9E9;color:#B3261E;padding:9px 12px;border-radius:8px;font-size:13px;margin-bottom:12px;"></div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" class="btn btn-ghost" onclick="docReview.close()">Abbrechen</button>
            <button type="button" class="btn btn-gold" id="review-submit" onclick="docReview.submit()">Zuordnen &amp; übernehmen</button>
        </div>
        </div>{{-- /review-body --}}

        {{-- Erfolg: NICHT zwangsweise weiterleiten. Der Mitarbeiter entscheidet,
             ob er zur Kundenakte springt oder im Eingang weiterarbeitet. --}}
        <div id="review-success" style="display:none;text-align:center;padding:10px 4px;">
            <div style="font-size:42px;margin-bottom:8px;">✅</div>
            <div id="review-success-msg" style="font-size:15.5px;font-weight:700;margin-bottom:6px;"></div>
            <div id="review-success-sub" style="font-size:13px;color:var(--ink-soft);margin-bottom:20px;"></div>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-ghost" onclick="docReview.stay()">Im Eingang bleiben</button>
                <a id="review-success-link" href="#" class="btn btn-gold">Zur Kundenakte →</a>
            </div>
        </div>
    </div>
</div>

<script>
// Daten der Eingangs-Dokumente fuer das Review-Modal (nur Anzeige;
// alle Werte werden per textContent gesetzt - kein HTML aus KI-Ausgaben).
@php
    $inboxDocsJson = $inboxDocuments->mapWithKeys(fn($d) => [$d->id => [
        'id' => $d->id,
        'file_name' => $d->file_name,
        'type_label' => $d->aiTypeLabel(),
        'summary' => $d->ai_summary,
        'extracted' => $d->ai_extracted ?: new stdClass(),
    ]]);
@endphp
window.INBOX_DOCS = @json($inboxDocsJson);
// Vorgaenge (gemeinsam hochgeladene Dateien): serverseitig zusammengefuehrte
// Extraktion (gleiche Logik wie beim Anlegen) fuer die Batch-Vorschau.
window.INBOX_BATCHES = @json((object) ($batchData ?? []));

window.docReview = (function() {
    var current = null;   // {docId, mode, customerId, customerLabel}
    var searchTimer = null;

    var APPLY_GROUPS = [
        { key: 'birth_date', label: 'Geburtsdatum', from: function(x) { return get(x, 'person', 'birth_date'); } },
        { key: 'birth_place', label: 'Geburtsort', from: function(x) { return get(x, 'person', 'birth_place'); } },
        { key: 'address', label: 'Adresse', from: function(x) {
            var p = x.person || {};
            var s = [(p.street || '') + ' ' + (p.house_number || ''), [(p.zip || ''), (p.city || '')].join(' ').trim()]
                .map(function(v) { return v.trim(); }).filter(Boolean).join(', ');
            return s || null;
        } },
        { key: 'phone', label: 'Telefon', from: function(x) { return get(x, 'person', 'phone'); } },
        { key: 'nationality', label: 'Staatsangehörigkeit', from: function(x) { return get(x, 'person', 'nationality'); } },
        { key: 'marital_status', label: 'Familienstand', from: function(x) {
            var m = get(x, 'person', 'marital_status');
            return m ? (m.charAt(0).toUpperCase() + m.slice(1)) : null;
        } },
        { key: 'gender', label: 'Geschlecht', from: function(x) {
            var g = get(x, 'person', 'gender');
            return g === 'male' ? 'Männlich' : (g === 'female' ? 'Weiblich' : null);
        } },
        { key: 'email2', label: 'E-Mail (Zweitadresse)', from: function(x) { return get(x, 'person', 'email'); } },
        { key: 'health_insurance', label: 'Krankenkasse / Versichertennummer', from: function(x) {
            var g = x.gesundheit || {};
            var parts = [g.health_insurance_company, g.health_insurance_number];
            if (g.pension_number) parts.push('Renten-Nr. ' + g.pension_number);
            if (g.previous_insurer) parts.push('zuvor: ' + g.previous_insurer);
            return parts.filter(Boolean).join(' · ') || null;
        } },
        { key: 'iban', label: 'IBAN / Kontoinhaber', from: function(x) {
            var b = x.bank || {};
            return [b.iban, b.account_holder].filter(Boolean).join(' · ') || null;
        } },
    ];

    function get(x, a, b) { return (x[a] || {})[b] || null; }
    function el(id) { return document.getElementById(id); }

    // Vorgang-Modus: EIN neuer Kunde aus allen Dokumenten des Batches.
    function openBatch(batchId) {
        openBatchMeta(window.INBOX_BATCHES[batchId]);
    }

    // Kern des Vorgang-/Auswahl-Modus: nimmt die (server-berechneten)
    // Batch-Metadaten direkt entgegen - so nutzen automatisch gruppierte
    // Vorgaenge UND die manuelle Mehrfachauswahl exakt dieselbe Ansicht.
    function openBatchMeta(batch) {
        if (!batch) return;
        if (batch.conflicts && batch.conflicts.length) {
            alert('⚠ ' + batch.conflicts.join(' ') + '\n\nBitte die Dokumente einzeln pruefen.');
            return;
        }
        current = { mode: 'batch', customerId: null, ids: batch.ids || [] };

        el('review-title').textContent = 'Neuen Kunden aus ' + batch.ids.length + ' Dokumenten erstellen';
        el('review-doc-name').textContent = '🗂 ' + batch.file_names.join(' · ');
        showBody();
        el('review-error').style.display = 'none';
        el('review-customer-q').value = '';
        el('review-customer-results').innerHTML = '';
        el('review-visibility').value = 'internal';
        el('review-assign-block').style.display = 'none';
        el('review-create-block').style.display = '';

        var p = (batch.merged || {}).person || {};
        var name = [(p.first_name || ''), (p.last_name || '')].join(' ').trim() || 'Unbekannt';
        el('review-create-block').textContent = '🆕 Es wird EIN neuer Kunde angelegt: ' + name
            + ' – alle ' + batch.ids.length + ' Dokumente werden ihm zugeordnet. Die Daten stammen zusammengefuehrt aus allen Dokumenten (Ausweis hat Vorrang bei Personendaten).';

        chooseCustomer(null, null);
        renderApplyFields({ extracted: batch.merged || {} });
        renderContract({ extracted: batch.merged || {} });
        renderFamily(batch);

        el('review-submit').textContent = 'Kunden anlegen & alle zuordnen';
        el('doc-review-modal').style.display = 'flex';
    }

    // Manuelle Mehrfachauswahl -> Batch-Vorschau vom Server holen (gleiche
    // Zusammenfuehrung + Familien-Erkennung wie ein Vorgang) und oeffnen.
    function openSelection() {
        var ids = selectedIds();
        if (ids.length < 2) { alert('Bitte mindestens zwei Dokumente auswaehlen.'); return; }
        var btn = el('inbox-selection-merge');
        if (btn) { btn.disabled = true; btn.textContent = 'Wird zusammengefuehrt…'; }
        fetch(@json(route('admin.documents.batch_preview')), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
            },
            body: JSON.stringify({ document_ids: ids }),
        }).then(readJsonOrStatus)
        .then(function(res) {
            if (btn) { btn.disabled = false; btn.textContent = '🗂 Zu EINEM Kunden zusammenfuehren'; }
            if (res.ok && res.json) { openBatchMeta(res.json); }
            else { alert('⚠ ' + friendlyError(res)); }
        }).catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = '🗂 Zu EINEM Kunden zusammenfuehren'; }
            alert('⚠ Keine Verbindung zum Server. Bitte erneut versuchen.');
        });
    }

    // Krankenkassen-Fall: Personenliste (Haupt-Frage + Status je Person),
    // Wechsel-Grund und Stichtag-Vorschau. Nur bei >= 2 erkannten Personen.
    function renderFamily(batch) {
        var section = el('review-family-section');
        var persons = batch.persons || [];
        if (persons.length < 2) { section.style.display = 'none'; return; }
        section.style.display = '';

        // Bei Gesundheitskarten im Vorgang direkt aktivieren, sonst opt-in.
        el('family-enabled').checked = !!batch.has_health_cards;
        el('family-body').style.display = el('family-enabled').checked ? '' : 'none';

        var wrap = el('family-persons');
        wrap.innerHTML = '';
        persons.forEach(function(p, i) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;border:1px solid var(--line);border-radius:8px;padding:8px 10px;background:#fff;font-size:13px;';
            var radio = document.createElement('input');
            radio.type = 'radio'; radio.name = 'family-haupt'; radio.value = i;
            radio.checked = (i === (batch.haupt_suggest || 0));
            radio.addEventListener('change', function() { updateMemberSelects(); });
            var name = document.createElement('strong');
            name.textContent = [(p.first_name || ''), (p.last_name || '')].join(' ').trim() || ('Person ' + (i + 1));
            var meta = document.createElement('span');
            meta.style.cssText = 'color:var(--ink-soft);font-size:12px;';
            meta.textContent = [p.birth_date, p.gender === 'male' ? '♂' : (p.gender === 'female' ? '♀' : null)].filter(Boolean).join(' · ');
            var status = document.createElement('select');
            status.className = 'family-status'; status.dataset.index = i;
            status.style.cssText = 'margin-inline-start:auto;padding:6px 9px;border:1px solid var(--line);border-radius:7px;font-size:12.5px;';
            [['familienversichert', 'Familienversichert'], ['mitglied', 'Eigenes Mitglied'], ['skip', 'Nicht anlegen']].forEach(function(opt) {
                var o = document.createElement('option'); o.value = opt[0]; o.textContent = opt[1]; status.appendChild(o);
            });
            var rel = document.createElement('select');
            rel.className = 'family-relation'; rel.dataset.index = i;
            rel.style.cssText = 'padding:6px 9px;border:1px solid var(--line);border-radius:7px;font-size:12.5px;';
            ['Ehepartner', 'Kind', 'Sonstig'].forEach(function(r) {
                var o = document.createElement('option'); o.value = r; o.textContent = r; rel.appendChild(o);
            });
            // Heuristik nur fuer die VORAUSWAHL der Beziehung (Mitarbeiter prueft).
            if (p.birth_date && (new Date().getFullYear() - parseInt(p.birth_date.substring(0, 4), 10)) < 18) rel.value = 'Kind';
            row.appendChild(radio); row.appendChild(name); row.appendChild(meta); row.appendChild(status); row.appendChild(rel);
            wrap.appendChild(row);
        });

        // Bisherige Kasse aus den Karten vorbelegen.
        var known = persons.map(function(p) { return p.company; }).filter(Boolean);
        el('family-old-insurer').value = known.length ? known[0] : '';
        el('family-new-insurer').value = '';
        el('family-jobstart').value = '';
        document.querySelector('input[name="family-reason"][value="wechsel"]').checked = true;
        updateMemberSelects();
        updateEffectivePreview();
    }

    // Haupt-Person braucht weder Status- noch Beziehungs-Auswahl.
    function updateMemberSelects() {
        var haupt = getHauptIndex();
        document.querySelectorAll('.family-status, .family-relation').forEach(function(sel) {
            sel.style.visibility = parseInt(sel.dataset.index, 10) === haupt ? 'hidden' : 'visible';
        });
    }

    function getHauptIndex() {
        var checked = document.querySelector('input[name="family-haupt"]:checked');
        return checked ? parseInt(checked.value, 10) : 0;
    }

    // Stichtag-VORSCHAU (der Server rechnet verbindlich mit derselben Regel).
    function updateEffectivePreview() {
        var reason = (document.querySelector('input[name="family-reason"]:checked') || {}).value || 'wechsel';
        el('family-jobstart-wrap').style.display = reason === 'new_job' ? '' : 'none';
        var text;
        if (reason === 'new_job') {
            text = 'Wirksam sofort ab Arbeitsbeginn' + (el('family-jobstart').value ? ' (' + el('family-jobstart').value + ')' : '') + '.';
        } else {
            var d = new Date();
            d.setMonth(d.getMonth() + 3, 1);
            text = 'Voraussichtlich wirksam ab ' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear()
                + ' (1. des Monats; Einreichungsmonat zaehlt nicht + 2 volle Monate)'
                + (reason === 'sonder' ? ' – als Sonderkuendigungsrecht markiert.' : '.');
        }
        el('family-effective-preview').textContent = '📅 ' + text;
    }

    function open(docId, mode, customerId, customerLabel) {
        var doc = window.INBOX_DOCS[docId];
        if (!doc) return;
        current = { docId: docId, mode: mode, customerId: customerId || null };

        el('review-title').textContent = mode === 'create' ? 'Neuen Kunden erstellen' : 'Dokument zuordnen';
        el('review-doc-name').textContent = '📄 ' + doc.file_name + (doc.type_label ? ' · ' + doc.type_label : '');
        showBody();
        el('review-error').style.display = 'none';
        el('review-customer-q').value = '';
        el('review-customer-results').innerHTML = '';
        el('review-visibility').value = 'internal';

        el('review-assign-block').style.display = mode === 'assign' ? '' : 'none';
        el('review-create-block').style.display = mode === 'create' ? '' : 'none';
        el('review-family-section').style.display = 'none';

        if (mode === 'create') {
            var p = (doc.extracted || {}).person || {};
            var name = [(p.first_name || ''), (p.last_name || '')].join(' ').trim() || 'Unbekannt';
            el('review-create-block').textContent = '🆕 Es wird ein neuer Kunde angelegt: ' + name
                + ' – mit neuer Kundennummer. Die unten ausgewählten Daten werden in die Kundenakte übernommen.';
        }

        chooseCustomer(customerId || null, customerLabel || null);
        renderApplyFields(doc);
        renderContract(doc);

        el('review-submit').textContent = mode === 'create' ? 'Kunden anlegen & Dokument zuordnen' : 'Zuordnen & übernehmen';
        el('doc-review-modal').style.display = 'flex';
    }

    function renderApplyFields(doc) {
        var wrap = el('review-apply-fields');
        wrap.innerHTML = '';
        var any = false;
        APPLY_GROUPS.forEach(function(group) {
            var value = group.from(doc.extracted || {});
            if (!value) return;
            any = true;
            var label = document.createElement('label');
            label.style.cssText = 'display:flex;gap:9px;align-items:flex-start;font-size:13px;border:1px solid var(--line);border-radius:8px;padding:8px 10px;cursor:pointer;';
            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.checked = true; cb.value = group.key; cb.className = 'review-apply-cb';
            cb.style.marginTop = '2px';
            var span = document.createElement('span');
            var strong = document.createElement('strong');
            strong.textContent = group.label + ': ';
            span.appendChild(strong);
            span.appendChild(document.createTextNode(value));
            label.appendChild(cb); label.appendChild(span);
            wrap.appendChild(label);
        });
        el('review-extract-section').style.display = any ? '' : 'none';
    }

    function renderContract(doc) {
        var ins = (doc.extracted || {}).versicherung || {};
        var kfz = (doc.extracted || {}).kfz || {};
        var energie = (doc.extracted || {}).energie || {};
        var has = ins.insurer || ins.contract_number;
        el('review-contract-section').style.display = has ? '' : 'none';
        el('review-create-contract').checked = false;
        if (has) {
            var parts = [];
            if (ins.insurer) parts.push(ins.insurer);
            if (ins.contract_number) parts.push('Nr. ' + ins.contract_number);
            if (ins.sparte) parts.push('Sparte: ' + ins.sparte);
            if (ins.premium_amount) parts.push(ins.premium_amount + ' €');
            if (kfz.license_plate) parts.push('Kennzeichen: ' + kfz.license_plate);
            if (energie.meter_number) parts.push('Zähler: ' + energie.meter_number);
            if (energie.malo_id) parts.push('MaLo: ' + energie.malo_id);
            if (energie.consumption_kwh) parts.push(energie.consumption_kwh + ' kWh/Jahr');
            if (energie.meter_reading) parts.push('Stand: ' + energie.meter_reading);
            el('review-contract-info').textContent = parts.join(' · ');
        }
    }

    function chooseCustomer(id, label) {
        current.customerId = id;
        var chosen = el('review-customer-chosen');
        if (id && label) {
            chosen.style.display = '';
            chosen.textContent = '✓ Ausgewählt: ' + label;
        } else {
            chosen.style.display = 'none';
            chosen.textContent = '';
        }
    }

    var searchSeq = 0;
    function search(q) {
        var seq = ++searchSeq; // verspaetete Antworten aelterer Suchen verwerfen
        fetch(@json(route('admin.documents.customer_search')) + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
        }).then(function(r) { return r.json(); }).then(function(list) {
            if (seq !== searchSeq) return;
            var wrap = el('review-customer-results');
            wrap.innerHTML = '';
            list.forEach(function(c) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.style.cssText = 'display:block;width:100%;text-align:start;border:1px solid var(--line);background:#fff;border-radius:8px;padding:8px 11px;font-size:13px;margin-bottom:5px;cursor:pointer;';
                btn.textContent = (c.name || '—') + ' · ' + (c.number || '') + (c.email ? ' · ' + c.email : '');
                btn.onclick = function() {
                    chooseCustomer(c.id, (c.name || '—') + ' (' + (c.number || '') + ')');
                    wrap.innerHTML = '';
                    el('review-customer-q').value = '';
                };
                wrap.appendChild(btn);
            });
            if (!list.length) {
                var none = document.createElement('div');
                none.style.cssText = 'font-size:12.5px;color:var(--ink-soft);padding:4px 2px;';
                none.textContent = 'Keine Treffer.';
                wrap.appendChild(none);
            }
        });
    }

    function submit() {
        if (!current) return;
        var isBatch = current.mode === 'batch';
        var isCreate = current.mode === 'create' || isBatch;
        if (!isCreate && !current.customerId) {
            showError('Bitte zuerst einen Kunden auswählen.');
            return;
        }
        var fields = Array.from(document.querySelectorAll('.review-apply-cb'))
            .filter(function(cb) { return cb.checked; }).map(function(cb) { return cb.value; });

        var payload = {
            apply_fields: fields,
            create_contract: el('review-create-contract').checked ? 1 : 0,
            visibility: el('review-visibility').value,
        };
        if (!isCreate) payload.customer_id = current.customerId;

        var url;
        if (isBatch) {
            payload.document_ids = current.ids || [];
            url = @json(route('admin.documents.create_customer_batch'));
            // Krankenkassen-Fall mitschicken, wenn aktiviert.
            var famSection = el('review-family-section');
            if (famSection.style.display !== 'none' && el('family-enabled').checked) {
                var newInsurer = el('family-new-insurer').value.trim();
                if (!newInsurer) { showError('Bitte die neue Krankenkasse angeben.'); return; }
                var haupt = getHauptIndex();
                var members = [];
                document.querySelectorAll('.family-status').forEach(function(sel) {
                    var idx = parseInt(sel.dataset.index, 10);
                    if (idx === haupt || sel.value === 'skip') return;
                    var rel = document.querySelector('.family-relation[data-index="' + idx + '"]');
                    members.push({ index: idx, status: sel.value, relation: rel ? rel.value : 'Sonstig' });
                });
                payload.family = {
                    haupt_index: haupt,
                    members: members,
                    switch_reason: (document.querySelector('input[name="family-reason"]:checked') || {}).value || 'wechsel',
                    job_start: el('family-jobstart').value || null,
                    old_insurer: el('family-old-insurer').value.trim() || null,
                    new_insurer: newInsurer,
                };
            }
        } else {
            url = isCreate
                ? @json(route('admin.documents.create_customer', ['id' => '__ID__']))
                : @json(route('admin.documents.assign', ['id' => '__ID__']));
            url = url.replace('__ID__', current.docId);
        }

        el('review-submit').disabled = true;
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
            },
            body: JSON.stringify(payload),
        }).then(readJsonOrStatus)
        .then(function(res) {
            el('review-submit').disabled = false;
            if (res.ok && res.json && res.json.ok) {
                showSuccess(res.json, isBatch ? 'batch' : current.mode);
            } else {
                showError(friendlyError(res)); // echte Ursache statt "Netzwerkfehler"
            }
        }).catch(function() {
            el('review-submit').disabled = false;
            showError('Keine Verbindung zum Server. Bitte die Internetverbindung pruefen und erneut versuchen.');
        });
    }

    // Antwort robust lesen: JSON, wenn moeglich; sonst Status + ob umgeleitet
    // wurde. So wird ein Server-Fehler (500/504), eine abgelaufene Sitzung
    // (Umleitung zum Login) oder ein CSRF-Fehler (419) NICHT faelschlich als
    // "Netzwerkfehler" angezeigt, sondern mit klarer, umsetzbarer Ursache.
    function readJsonOrStatus(r) {
        return r.text().then(function(text) {
            var json = null;
            try { json = JSON.parse(text); } catch (e) {}
            return { ok: r.ok, status: r.status, json: json, redirected: r.redirected, url: r.url || '' };
        });
    }

    // Klartext-Meldung fuer einen fehlgeschlagenen Request.
    function friendlyError(res) {
        if (res.json && res.json.message) return res.json.message; // echte Server-Meldung
        // Sitzung abgelaufen -> der POST wurde zur Login-Seite umgeleitet (HTML).
        if (res.redirected || res.url.indexOf('/login') !== -1) {
            return 'Ihre Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut anmelden.';
        }
        return httpErrorHint(res.status);
    }

    function httpErrorHint(status) {
        if (status === 419) return 'Ihre Sitzung ist abgelaufen. Bitte die Seite neu laden (F5) und erneut versuchen.';
        if (status === 401 || status === 403) return 'Nicht mehr angemeldet oder keine Berechtigung. Bitte neu anmelden.';
        if (status === 413) return 'Die Anfrage ist zu gross.';
        if (status === 429) return 'Zu viele Anfragen in kurzer Zeit. Bitte kurz warten und erneut versuchen.';
        if (status === 502 || status === 504) return 'Der Server hat zu lange gebraucht (Zeitueberschreitung). Bitte erneut versuchen.';
        if (status >= 500) return 'Server-Fehler (' + status + '). Bitte erneut versuchen; bleibt es bestehen, bitte an die Technik melden.';
        return 'Aktion fehlgeschlagen (Status ' + (status || '?') + ').';
    }

    // Nach erfolgreicher Aktion NICHT hart weiterleiten (der Mitarbeiter
    // verliert sonst den Eingang), sondern einen Erfolg mit Wahl anzeigen:
    // zur Kundenakte springen ODER im Eingang weiterarbeiten.
    function showSuccess(data, mode) {
        // Manuelle Auswahl abschliessen (die Dokumente sind jetzt zugeordnet).
        clearSelection();
        var name = data.customer_name || 'Kunde';
        var number = data.customer_number ? ' (' + data.customer_number + ')' : '';
        var msg, sub;
        if (mode === 'assign') {
            msg = 'Dokument zugeordnet';
            sub = 'Zugeordnet zu ' + name + number + '.';
        } else if (mode === 'batch') {
            msg = 'Kunde angelegt · ' + (data.documents || 0) + ' Dokumente zugeordnet';
            sub = name + number + ' wurde angelegt.'
                + (data.health ? ' Krankenkassen-Fall eingerichtet.' : '');
        } else {
            msg = 'Neuer Kunde angelegt';
            sub = name + number + ' wurde angelegt und das Dokument zugeordnet.';
        }
        el('review-success-msg').textContent = '✅ ' + msg;
        el('review-success-sub').textContent = sub;
        el('review-success-link').href = data.customer_url || '#';
        el('review-body').style.display = 'none';
        el('review-success').style.display = '';
    }

    // Modal-Arbeitsbereich zeigen, Erfolgsansicht ausblenden.
    function showBody() {
        el('review-body').style.display = '';
        el('review-success').style.display = 'none';
    }

    // "Im Eingang bleiben": Seite neu laden, damit die jetzt zugeordneten
    // Dokumente aus der Liste verschwinden - der Mitarbeiter arbeitet weiter.
    function stay() {
        window.location.reload();
    }

    function showError(msg) {
        var box = el('review-error');
        box.textContent = '⚠ ' + msg;
        box.style.display = '';
    }

    function reanalyze(docId, btn) {
        btn.disabled = true;
        fetch(@json(route('admin.documents.reanalyze', ['id' => '__ID__'])).replace('__ID__', docId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
        }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
        .then(function(res) {
            if (res.ok) { window.location.reload(); return; }
            btn.disabled = false;
            alert(res.json.message || 'Analyse konnte nicht gestartet werden.');
        })
        .catch(function() { btn.disabled = false; });
    }

    // --- Manuelle Mehrfachauswahl (Checkboxen im Eingang) ---
    function selectedBoxes() {
        return Array.from(document.querySelectorAll('.inbox-select:checked'));
    }
    function selectedIds() {
        return selectedBoxes().map(function(cb) { return cb.value; });
    }
    function updateSelectionBar() {
        var n = selectedBoxes().length;
        var bar = el('inbox-selection-bar');
        if (!bar) return;
        el('inbox-selection-count').textContent = n;
        bar.style.display = n > 0 ? 'flex' : 'none';
        var btn = el('inbox-selection-merge');
        if (btn) btn.disabled = n < 2; // erst ab 2 Dokumenten sinnvoll
    }
    function clearSelection() {
        selectedBoxes().forEach(function(cb) { cb.checked = false; });
        updateSelectionBar();
    }

    document.addEventListener('DOMContentLoaded', function() {
        el('family-enabled').addEventListener('change', function() {
            el('family-body').style.display = this.checked ? '' : 'none';
        });
        document.querySelectorAll('input[name="family-reason"]').forEach(function(r) {
            r.addEventListener('change', updateEffectivePreview);
        });
        el('family-jobstart').addEventListener('input', updateEffectivePreview);
        el('review-customer-q').addEventListener('input', function() {
            var q = this.value.trim();
            if (searchTimer) clearTimeout(searchTimer);
            if (q.length < 2) { el('review-customer-results').innerHTML = ''; return; }
            searchTimer = setTimeout(function() { search(q); }, 300);
        });
        el('doc-review-modal').addEventListener('click', function(e) {
            if (e.target === this) docReview.close();
        });
        // Auswahl-Leiste live aktualisieren (Delegation, damit auch neu
        // gerenderte Zeilen erfasst werden).
        document.addEventListener('change', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('inbox-select')) {
                updateSelectionBar();
            }
        });
        updateSelectionBar();
    });

    return {
        open: open,
        openBatch: openBatch,
        openSelection: openSelection,
        clearSelection: clearSelection,
        stay: stay,
        close: function() { el('doc-review-modal').style.display = 'none'; current = null; },
        submit: submit,
        reanalyze: reanalyze,
    };
})();

// Drag&Drop-Upload in den Eingang + Status-Polling laufender Analysen
(function() {
    var dz = document.getElementById('inbox-dropzone');
    var input = document.getElementById('inbox-files');
    var uploadActive = false; // Auto-Reload pausieren, solange ein Upload laeuft

    function uploadFiles(files) {
        if (!files.length || uploadActive) return; // kein Doppel-Upload waehrend ein anderer laeuft
        uploadActive = true;
        dz.style.opacity = '.6';
        dz.style.pointerEvents = 'none';
        var data = new FormData();
        data.append('_token', @json(csrf_token()));
        var bundle = document.getElementById('inbox-bundle');
        data.append('bundle_images', bundle && bundle.checked ? 1 : 0);
        Array.from(files).forEach(function(f) { data.append('files[]', f, f.name); });

        var wrap = document.getElementById('inbox-upload-progress');
        var bar = document.getElementById('inbox-upload-bar');
        var label = document.getElementById('inbox-upload-label');
        wrap.style.display = '';
        bar.style.background = 'var(--gold)';

        var xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', function(e) {
            if (!e.lengthComputable) return;
            var pct = Math.round(e.loaded / e.total * 100);
            bar.style.width = pct + '%';
            label.textContent = pct + '%';
        });
        function unlockDropzone() {
            uploadActive = false;
            dz.style.opacity = '';
            dz.style.pointerEvents = '';
        }
        xhr.addEventListener('load', function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                var dupN = 0;
                try { var r = JSON.parse(xhr.responseText); dupN = (r.duplicates || []).length; } catch (e) {}
                // Sofortiger Hinweis, wenn eine Datei bereits im System liegt;
                // Details (wann/welcher Kunde) zeigt die Warnung an der Zeile.
                label.textContent = dupN > 0
                    ? '⚠ ' + dupN + ' Datei(en) bereits vorhanden – siehe Hinweis unten'
                    : '✓ Hochgeladen – Analyse gestartet';
                if (dupN > 0) bar.style.background = '#E4A11B';
                setTimeout(function() { window.location.reload(); }, dupN > 0 ? 1400 : 700);
            } else {
                unlockDropzone();
                var msg = 'Fehler beim Upload.';
                try { var j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch (e) {}
                label.textContent = '⚠ ' + msg;
                bar.style.background = '#A32D2D';
            }
        });
        xhr.addEventListener('error', function() {
            unlockDropzone();
            label.textContent = '⚠ Netzwerkfehler beim Upload.';
            bar.style.background = '#A32D2D';
        });
        xhr.open('POST', @json(route('admin.documents.smart_upload')));
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(data);
    }

    dz.addEventListener('click', function() { input.click(); });
    input.addEventListener('change', function() { uploadFiles(this.files); this.value = ''; });
    ['dragover', 'dragenter'].forEach(function(ev) {
        dz.addEventListener(ev, function(e) { e.preventDefault(); dz.style.borderColor = 'var(--gold)'; dz.style.background = 'var(--surface)'; });
    });
    ['dragleave', 'drop'].forEach(function(ev) {
        dz.addEventListener(ev, function(e) { e.preventDefault(); dz.style.borderColor = 'var(--line)'; dz.style.background = 'transparent'; });
    });
    dz.addEventListener('drop', function(e) { e.preventDefault(); uploadFiles(e.dataTransfer.files); });

    // Laufende Analysen beobachten; bei Abschluss Seite aktualisieren
    // (nur wenn gerade kein Modal offen ist).
    var pendingIds = Array.from(document.querySelectorAll('[data-doc-row]'))
        .filter(function(row) { return ['pending', 'processing'].indexOf(row.getAttribute('data-doc-status')) !== -1; })
        .map(function(row) { return row.getAttribute('data-doc-row'); });
    if (!pendingIds.length) return;

    var statusUrl = @json(route('admin.documents.analyse_status', ['id' => '__ID__']));
    function check() {
        Promise.all(pendingIds.map(function(id) {
            return fetch(statusUrl.replace('__ID__', id), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function(r) { return r.json(); }).catch(function() { return null; });
        })).then(function(results) {
            var finished = results.some(function(s) { return s && ['done', 'failed', 'none'].indexOf(s.status) !== -1; });
            var modalOpen = document.getElementById('doc-review-modal').style.display === 'flex';
            if (finished && !modalOpen && !uploadActive) { window.location.reload(); return; }
            setTimeout(check, 5000);
        });
    }
    setTimeout(check, 5000);
})();
</script>
@endsection
