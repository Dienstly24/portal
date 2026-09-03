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
@elseif(!($providerEnabled ?? true))
{{-- Die kostenlose Basisebene (Textebene/OCR + Vorlagen-Parser) laeuft, aber
     die KI-Analyse ist AUS. Einfache Dokumente und bekannte Formulare
     (z.B. CHECK24-Beratungsprotokoll) werden weiter erkannt; komplexe/neue
     Vertraege, die die KI braucht, werden NICHT vollstaendig gelesen. Das ist
     genau das Symptom "Vertraege werden nicht mehr erkannt" - der Hinweis
     macht die Ursache sofort sichtbar. --}}
<div style="background:#FEF3C7;color:#92400E;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    ⚠ Die <strong>KI-Analyse ist derzeit ausgeschaltet</strong> – nur die kostenlose Basiserkennung (OCR/Textebene und bekannte Formulare) läuft.
    Komplexe oder neue <strong>Verträge werden dann nicht mehr automatisch vollständig erkannt</strong>.
    Ursache prüfen: gültiger <code>ANTHROPIC_API_KEY</code> und <code>AI_DOCUMENT_PROVIDER=claude</code> in der Server-<code>.env</code>
    (Diagnose: <code>php artisan ocr:check</code>).
</div>
@endif

{{-- Drag&Drop Smart-Upload (ohne Kundenzuordnung -> Eingang) --}}
<div class="card" style="margin-bottom:20px;">
    {{-- Tastaturbedienbar (Audit A11Y-2): role=button + tabindex + Enter/Space --}}
    <div id="inbox-dropzone" role="button" tabindex="0" aria-label="Dateien zum Hochladen auswaehlen oder hierher ziehen"
        data-h-keydown="66dc0af444"
        style="border:2px dashed var(--line);border-radius:12px;padding:30px;text-align:center;cursor:pointer;transition:.15s;">
        <div style="font-size:34px;margin-bottom:6px;" aria-hidden="true">📥</div>
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
        {{-- Fortschritt/Status wird Screenreadern angesagt (Audit A11Y-5) --}}
        <div id="inbox-upload-label" role="status" aria-live="polite" style="font-size:12px;color:var(--ink-soft);margin-top:5px;">0%</div>
    </div>
</div>

{{-- Eingang: nicht zugeordnete Dokumente --}}
@php
    $batchIds = collect($batchGroups ?? [])->flatMap(fn ($g) => $g->pluck('id'))->all();
    $singleDocuments = $inboxDocuments->reject(fn ($d) => in_array($d->id, $batchIds, true));
@endphp
<div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span>Nicht zugeordnet ({{ $inboxDocuments->count() }})</span>
        @if($inboxDocuments->count() > 0)
        <label style="margin-inline-start:auto;font-weight:500;font-size:12.5px;color:var(--ink-soft);display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="inbox-select-all" data-h-change="3cd194d928"> Alle auswaehlen
        </label>
        @endif
    </div>

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
                    <button type="button" class="btn btn-gold btn-sm" data-h-click="stapel-oeffnen" data-batch="{{ $batchId }}">
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
<div id="inbox-selection-bar" style="display:none;position:fixed;left:50%;transform:translateX(-50%);bottom:22px;z-index:150;background:#0F1512;color:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.30);padding:11px 16px;align-items:center;gap:14px;flex-wrap:wrap;max-width:calc(100% - 32px);">
    <span style="font-size:13.5px;"><strong id="inbox-selection-count">0</strong>&nbsp;Dokumente ausgewaehlt</span>
    <button type="button" class="btn btn-gold btn-sm" id="inbox-selection-merge" data-h-click="30707c1762">🗂 Zu EINEM Kunden zusammenfuehren</button>
    <button type="button" class="btn btn-ghost btn-sm" style="color:#fff;border-color:rgba(255,120,120,.55);" data-h-click="654337d4fb">🗑 Loeschen</button>
    <button type="button" class="btn btn-ghost btn-sm" style="color:#fff;border-color:rgba(255,255,255,.35);" data-h-click="e29850ed68">Aufheben</button>
</div>

{{-- Schnellvorschau (Quick-Look): erscheint beim Ueberfahren eines Dokuments,
     ohne eine neue Seite zu oeffnen. Klick auf das Dokument oeffnet es voll. --}}
<div id="doc-quicklook" style="display:none;position:fixed;z-index:300;width:min(560px,46vw);height:70vh;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.28);overflow:hidden;">
    <div style="padding:7px 11px;font-size:12px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;background:var(--canvas);">
        <span id="doc-quicklook-name" style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
        <span style="color:var(--ink-soft);white-space:nowrap;">Klick öffnet vollständig</span>
    </div>
    <iframe id="doc-quicklook-frame" src="about:blank" title="Vorschau" style="width:100%;height:calc(100% - 32px);border:0;background:#F7F5EF;"></iframe>
</div>

{{-- Eingelesene Vermittler-Vorgangslisten: erledigt, aber ohne Kunden.
     Sie stehen hier statt unter "Nicht zugeordnet" - dort waeren sie eine
     Daueraufgabe, die keine ist. Geloescht wird nichts. --}}
@if(isset($vorgangslisten) && $vorgangslisten->isNotEmpty())
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">🤝 Eingelesene Vermittler-Vorgangslisten</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
        <thead>
            <tr style="text-align:start;color:var(--ink-soft);font-size:12px;">
                <th style="text-align:start;padding:10px 20px;">Datei</th>
                <th style="text-align:start;padding:10px 12px;">Vorgänge</th>
                <th style="text-align:start;padding:10px 12px;">Neu verknüpft</th>
                <th style="text-align:start;padding:10px 12px;">Prüfung</th>
                <th style="text-align:start;padding:10px 20px;">Eingelesen</th>
            </tr>
        </thead>
        <tbody>
        @foreach($vorgangslisten as $doc)
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:10px 20px;"><a href="{{ route('admin.documents.download', $doc->id) }}?view=1" target="_blank">{{ $doc->file_name }}</a></td>
                <td style="padding:10px 12px;">{{ $doc->vermittlerImport?->rows_total ?? '—' }}</td>
                <td style="padding:10px 12px;">{{ $doc->vermittlerImport?->rows_new_link ?? '—' }}</td>
                <td style="padding:10px 12px;">{{ $doc->vermittlerImport?->rows_review ?? '—' }}</td>
                <td style="padding:10px 20px;color:var(--ink-soft);">
                    {{-- Zeitpunkt des LAUFS, nicht der letzten Aenderung am
                         Dokument: "Eingelesen" soll auch dann stimmen, wenn
                         das Dokument spaeter noch einmal angefasst wird.
                         Anzeige in Ortszeit (gespeichert wird UTC). --}}
                    {{ ($doc->vermittlerImport?->created_at ?? $doc->updated_at)?->lokal()->format('d.m.Y H:i') }}
                    @if($doc->vermittler_import_id && in_array(auth()->user()?->role, ['admin','manager'], true))
                    · <a href="{{ route('admin.vermittler.show', $doc->vermittler_import_id) }}">Ergebnis ansehen</a>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

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
                <td style="padding:10px 20px;color:var(--ink-soft);">{{ $doc->created_at->lokal()->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="padding:18px 20px;color:var(--ink-soft);">Noch keine analysierten Dokumente.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- Review-/Zuordnungs-Modal --}}
{{-- DIAGNOSE: der TATSAECHLICH erkannte Text (Betreiber-Frage 28.08.2026).
     Fehlt ein Feld, sieht man im BILD die Angabe klar stehen - die Erkennung
     arbeitet aber mit diesem Text, und der kann an genau einer Stelle anders
     aussehen. Ohne ihn ist jede Fehlersuche Raten. Kostenlos, ohne KI, und der
     Text wird NICHT gespeichert. --}}
<div id="ocr-text-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:210;align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--surface);border-radius:14px;max-width:900px;width:100%;max-height:86vh;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:flex-start;gap:12px;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:16px;">🔎 Erkannter Text (das, womit die Erkennung gearbeitet hat)</div>
                <div id="ocr-text-source" style="font-size:12px;color:var(--muted);margin-top:3px;"></div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" data-h-click="466c2de1a6">Kopieren</button>
            <button type="button" class="btn btn-ghost btn-sm" data-h-click="81bc6bbfae">Schließen</button>
        </div>
        <div style="padding:10px 20px 0;font-size:12px;color:var(--muted);" id="ocr-text-note"></div>
        <pre id="ocr-text-body" style="margin:12px 20px 20px;padding:14px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;overflow:auto;flex:1;white-space:pre;font-size:12.5px;line-height:1.55;"></pre>
    </div>
</div>

<div id="doc-review-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:26px;width:100%;max-width:620px;position:relative;max-height:92vh;overflow-y:auto;">
        <button type="button" data-h-click="d10e859b20" style="position:absolute;top:14px;right:14px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:17px;font-weight:700;margin-bottom:4px;" id="review-title">Dokument zuordnen</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;" id="review-doc-name"></div>

        {{-- Unsicherheits-Hinweis: warnt bei niedriger Konfidenz/OCR und listet
             wichtige Angaben, die NICHT sicher gelesen wurden - nichts wird
             geraten, der Mitarbeiter ergaenzt sie bewusst. --}}
        <div id="review-uncertainty" style="display:none;background:#FEF6E7;border:1px solid #E4A11B;border-radius:10px;padding:10px 12px;font-size:13px;color:#8A5A00;margin-bottom:14px;">
            <div id="review-uncertainty-head" style="font-weight:600;margin-bottom:4px;"></div>
            <div id="review-uncertainty-missing"></div>
        </div>

        <div id="review-body">
        {{-- Automatische Zuordnungs-Vorschlaege: die naechstliegenden Kunden
             zu den gelesenen Angaben. Steht bewusst GANZ OBEN und wird beim
             Oeffnen geladen - der Mitarbeiter soll nicht selbst suchen
             muessen. Jeder Vorschlag nennt seinen Grund; ausgewaehlt wird
             immer bewusst per Klick. --}}
        <div id="review-suggestions" style="display:none;border:1px solid var(--line);background:#F7F5EF;border-radius:10px;padding:11px 12px;margin-bottom:12px;">
            <div id="review-suggestions-head" style="font-weight:700;font-size:13.5px;margin-bottom:7px;"></div>
            <div id="review-suggestions-list" style="display:grid;gap:6px;"></div>
            <div id="review-suggestions-note" style="font-size:12px;color:var(--ink-soft);margin-top:7px;"></div>
        </div>

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

        {{-- Name des neuen Kunden: vorbefuellt aus dem Dokument, aber IMMER
             editierbar - so laesst sich ein Kunde auch anlegen, wenn der Name
             nicht (sicher) gelesen wurde (Mitarbeiter tippt ihn aus dem
             Dokument ab). --}}
        <div id="review-name-block" style="display:none;margin-bottom:12px;">
            <div style="font-weight:700;font-size:13.5px;margin-bottom:6px;">Name des Kunden *</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <input type="text" id="review-first-name" placeholder="Vorname"
                    style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <input type="text" id="review-last-name" placeholder="Nachname"
                    style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            </div>
            <div id="review-name-hint" style="display:none;font-size:12px;color:#8A5A00;margin-top:5px;">
                ℹ Der Name wurde nicht automatisch gelesen – bitte aus dem Dokument eintragen (👁 Anzeigen).
            </div>
            @if(in_array(auth()->user()->role, ['admin','manager']))
            {{-- Werber direkt bei der Anlage aus dem Dokumenten-Eingang setzen
                 (Neukunden-Bericht/Provision). Nur Verwaltung; nachtraeglich
                 aenderbar im Neukunden-Bericht. --}}
            <div style="margin-top:10px;">
                <div style="font-weight:700;font-size:13.5px;margin-bottom:6px;">Geworben von <span style="font-weight:400;color:var(--ink-soft);">(optional – für Neukunden-Bericht &amp; Provision)</span></div>
                <select id="review-werber" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">— Kein Werber —</option>
                    <optgroup label="Mitarbeiter">
                        @foreach(\App\Models\User::whereIn('role', ['admin','manager','support','employee'])->orderBy('name')->get() as $e)
                        <option value="u:{{ $e->id }}">{{ $e->name }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Partner">
                        @foreach(\App\Models\Partner::orderBy('name')->get() as $p)
                        <option value="p:{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>
            @endif
        </div>

        {{-- Krankenkassen-Fall (Familie + Wechsel), nur im Vorgang-Modus bei >= 2 Personen --}}
        <div id="review-family-section" style="display:none;border:1.5px solid #17A65B;border-radius:10px;padding:12px;margin-bottom:12px;background:#F6FBF8;">
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
                <div id="family-effective-preview" style="font-size:12.5px;color:#17A65B;font-weight:600;"></div>
            </div>
        </div>

        {{-- Extrahierte Daten --}}
        <div id="review-extract-section" style="display:none;">
            <div style="font-weight:700;font-size:13.5px;margin:6px 0 8px;">Erkannte Daten übernehmen <span style="font-weight:400;color:var(--ink-soft);">(nur leere Felder werden befüllt)</span></div>
            <div id="review-apply-fields" style="display:grid;grid-template-columns:1fr;gap:6px;margin-bottom:12px;"></div>
        </div>

        {{-- Zaehlerfoto: erkannter Stand + Hinweis auf die Verbrauchshistorie --}}
        <div id="review-meter-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:12px;background:#F1EEE5;">
            <div style="font-size:13.5px;font-weight:700;">📊 Zählerstand erkannt</div>
            <div id="review-meter-info" style="color:var(--ink-soft);font-size:12.5px;margin-top:4px;"></div>
            <div style="color:var(--ink-soft);font-size:12px;margin-top:6px;">
                Beim Zuordnen wird der Stand automatisch in die Verbrauchshistorie des passenden Energievertrags übernommen.
            </div>
        </div>

        {{-- Weitere erkannte Identitaetsangabe, die NICHT automatisch
             gespeichert wird (Ausweis-/Dokumentennummer). Bewusst kein
             Dauerfeld (Datenminimierung) - nur zur Kenntnis fuer den
             Mitarbeiter, damit nichts unbemerkt verloren geht. --}}
        <div id="review-identity-note" style="display:none;font-size:12.5px;color:var(--ink-soft);margin-bottom:12px;"></div>

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
            <button type="button" class="btn btn-ghost" data-h-click="d10e859b20">Abbrechen</button>
            <button type="button" class="btn btn-gold" id="review-submit" data-h-click="368883223d">Zuordnen &amp; übernehmen</button>
        </div>
        </div>{{-- /review-body --}}

        {{-- Erfolg: NICHT zwangsweise weiterleiten. Der Mitarbeiter entscheidet,
             ob er zur Kundenakte springt oder im Eingang weiterarbeitet. --}}
        <div id="review-success" style="display:none;text-align:center;padding:10px 4px;">
            <div style="font-size:42px;margin-bottom:8px;">✅</div>
            <div id="review-success-msg" style="font-size:15.5px;font-weight:700;margin-bottom:6px;"></div>
            <div id="review-success-sub" style="font-size:13px;color:var(--ink-soft);margin-bottom:20px;"></div>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-ghost" data-h-click="ead9668286">Im Eingang bleiben</button>
                <a id="review-success-link" href="#" class="btn btn-gold">Zur Kundenakte →</a>
            </div>
        </div>
    </div>
</div>

<script @cspNonce>
// Daten der Eingangs-Dokumente fuer das Review-Modal (nur Anzeige;
// alle Werte werden per textContent gesetzt - kein HTML aus KI-Ausgaben).
@php
    $inboxDocsJson = $inboxDocuments->mapWithKeys(fn($d) => [$d->id => [
        'id' => $d->id,
        'file_name' => $d->file_name,
        'type_label' => $d->aiTypeLabel(),
        // Der Typ steuert im Modal den Zaehlerfoto-Hinweis (Verbrauchshistorie).
        'ai_type' => $d->ai_type,
        'summary' => $d->ai_summary,
        'confidence' => $d->ai_confidence,
        'source' => $d->ai_source,
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
        { key: 'phone', label: 'Telefon / Handy', from: function(x) { return get(x, 'person', 'phone'); } },
        { key: 'nationality', label: 'Staatsangehörigkeit', from: function(x) { return get(x, 'person', 'nationality'); } },
        { key: 'marital_status', label: 'Familienstand', from: function(x) {
            var m = get(x, 'person', 'marital_status');
            return m ? (m.charAt(0).toUpperCase() + m.slice(1)) : null;
        } },
        { key: 'gender', label: 'Geschlecht', from: function(x) {
            var g = get(x, 'person', 'gender');
            return g === 'male' ? 'Männlich' : (g === 'female' ? 'Weiblich' : null);
        } },
        { key: 'email2', label: 'E-Mail (Login / Portal-Zugang)', from: function(x) { return get(x, 'person', 'email'); } },
        { key: 'occupation', label: 'Beruf', from: function(x) { return get(x, 'person', 'occupation'); } },
        { key: 'employer', label: 'Arbeitgeber', from: function(x) {
            var p = x.person || {};
            return [p.employer_name, p.employer_address].filter(Boolean).join(' · ') || null;
        } },
        { key: 'health_insurance', label: 'Krankenkasse / Versichertennummer', from: function(x) {
            var g = x.gesundheit || {};
            var parts = [g.health_insurance_company, g.health_insurance_number];
            if (g.pension_number) parts.push('Renten-Nr. ' + g.pension_number);
            if (g.previous_insurer) parts.push('zuvor: ' + g.previous_insurer);
            return parts.filter(Boolean).join(' · ') || null;
        } },
        { key: 'iban', label: 'IBAN / Kontoinhaber / BIC', from: function(x) {
            var b = x.bank || {};
            return [b.iban, b.account_holder, b.bic ? ('BIC ' + b.bic) : null].filter(Boolean).join(' · ') || null;
        } },
    ];

    // ERKENNUNGSSICHERHEIT JE FELD (Betreiber-Vorgabe 28.08.2026).
    // Die Analyse liefert unter `feldstatus` je Feld einen von vier
    // Zustaenden. Frueher gab es nur EINE Konfidenz fuer das ganze Dokument -
    // der Mitarbeiter musste daraufhin alles kontrollieren, also genau das,
    // was die Automatik ersparen soll.
    var STATUS_STYLE = {
        sicher:      { text: '✓ sicher',            color: '#17A65B', bg: '#E8F7EF' },
        pruefen:     { text: '⚠ bitte prüfen',      color: '#8A5A00', bg: '#FEF6E7' },
        widerspruch: { text: '⚠ widersprüchlich',   color: '#B42318', bg: '#FDECEA' },
        fehlt:       { text: '– nicht erkannt',     color: '#5F6B62', bg: '#F1EEE5' }
    };

    // Ein Anzeigeblock kann mehrere Felder buendeln ("Adresse"). Dann gilt
    // der SCHLECHTESTE Zustand - sonst verdeckt der sauber gelesene Ort die
    // unlesbare Hausnummer daneben.
    var STATUS_FIELDS = {
        birth_date: ['person.birth_date'],
        birth_place: ['person.birth_place'],
        address: ['person.street', 'person.house_number', 'person.zip', 'person.city'],
        phone: ['person.phone'],
        gender: ['person.gender'],
        email2: ['person.email'],
        occupation: ['person.occupation'],
        iban: ['bank.iban']
    };
    var STATUS_RANK = { sicher: 0, fehlt: 1, pruefen: 2, widerspruch: 3 };

    function fieldStatus(extracted, keys) {
        var all = (extracted || {}).feldstatus || {};
        var worst = null, hint = null;
        (keys || []).forEach(function(key) {
            var entry = all[key];
            if (!entry || !STATUS_RANK.hasOwnProperty(entry.status)) return;
            if (worst === null || STATUS_RANK[entry.status] > STATUS_RANK[worst]) {
                worst = entry.status; hint = entry.hinweis || null;
            }
        });
        return worst === null ? null : { status: worst, hinweis: hint };
    }

    // Kleines Kennzeichen hinter dem Wert. Nur wo es etwas sagt: "sicher" an
    // jedem einzelnen Feld waere Ziergrafik und wuerde die zwei Felder
    // verstecken, um die es geht.
    function statusBadge(state) {
        if (!state || state.status === 'sicher') return null;
        var style = STATUS_STYLE[state.status];
        if (!style) return null;
        var badge = document.createElement('span');
        badge.textContent = style.text;
        badge.style.cssText = 'margin-left:8px;padding:1px 7px;border-radius:999px;font-size:11px;'
            + 'font-weight:600;white-space:nowrap;color:' + style.color + ';background:' + style.bg + ';';
        if (state.hinweis) badge.title = state.hinweis;
        return badge;
    }

    function get(x, a, b) { return (x[a] || {})[b] || null; }
    function el(id) { return document.getElementById(id); }

    // Namensfelder fuer die Neuanlage vorbelegen (aus dem Dokument) und immer
    // editierbar zeigen. Fehlt der Name, wird ein Hinweis eingeblendet.
    function fillNameBlock(first, last) {
        el('review-name-block').style.display = '';
        el('review-first-name').value = first || '';
        el('review-last-name').value = last || '';
        el('review-name-hint').style.display = (!first && !last) ? '' : 'none';
    }

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
        if (el('review-werber')) el('review-werber').value = '';
        el('review-assign-block').style.display = 'none';
        el('review-create-block').style.display = '';

        var p = (batch.merged || {}).person || {};
        el('review-create-block').textContent = '🆕 Es wird EIN neuer Kunde angelegt und alle ' + batch.ids.length
            + ' Dokumente werden ihm zugeordnet. Die Daten stammen zusammengefuehrt aus allen Dokumenten (Ausweis hat Vorrang bei Personendaten).';
        fillNameBlock(p.first_name, p.last_name);

        chooseCustomer(null, null);
        renderApplyFields({ extracted: batch.merged || {} });
        // ai_type-Hinweis mitgeben, damit der Zaehlerstand-Block auch im
        // Batch-Modus erscheint, wenn ein Zaehlerfoto im Vorgang ist.
        renderContract({ extracted: batch.merged || {}, ai_type: batch.has_meter_photo ? 'zaehlerfoto' : null });
        renderUncertainty(batch.merged || {}, null, null);
        renderFamily(batch);

        el('review-submit').textContent = 'Kunden anlegen & alle zuordnen';
        el('doc-review-modal').style.display = 'flex';
        // Vorgang: die Vorschlaege dienen als Dubletten-Warnung. Zugeordnet
        // wird ein Vorgang nicht per Klick (dafuer die Dokumente einzeln) -
        // deshalb bewusst ohne Auswahl-Button.
        loadSuggestions(batch.ids[0], 'batch', (batch.ids || []).slice(1));
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
        if (el('review-werber')) el('review-werber').value = '';

        el('review-assign-block').style.display = mode === 'assign' ? '' : 'none';
        el('review-create-block').style.display = mode === 'create' ? '' : 'none';
        el('review-family-section').style.display = 'none';

        if (mode === 'create') {
            var p = (doc.extracted || {}).person || {};
            el('review-create-block').textContent = '🆕 Es wird ein neuer Kunde mit neuer Kundennummer angelegt. '
                + 'Die unten ausgewählten Daten werden in die Kundenakte übernommen.';
            fillNameBlock(p.first_name, p.last_name);
        } else {
            el('review-name-block').style.display = 'none';
        }

        chooseCustomer(customerId || null, customerLabel || null);
        renderApplyFields(doc);
        renderContract(doc);
        renderUncertainty(doc.extracted || {}, doc.confidence, doc.source);

        el('review-submit').textContent = mode === 'create' ? 'Kunden anlegen & Dokument zuordnen' : 'Zuordnen & übernehmen';
        el('doc-review-modal').style.display = 'flex';
        loadSuggestions(docId, mode, null);
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
            var badge = statusBadge(fieldStatus(doc.extracted, STATUS_FIELDS[group.key]));
            if (badge) span.appendChild(badge);
            label.appendChild(cb); label.appendChild(span);
            wrap.appendChild(label);
        });
        el('review-extract-section').style.display = any ? '' : 'none';
    }

    // Klartext je Feldschluessel fuer den Unsicherheits-Hinweis. Die
    // Schluessel spiegeln den Aufbau von `data`; hier bekommen sie den Namen,
    // unter dem der Mitarbeiter das Feld kennt.
    var FIELD_LABELS = {
        'person.first_name': 'Vorname', 'person.last_name': 'Nachname',
        'person.birth_date': 'Geburtsdatum', 'person.gender': 'Geschlecht',
        'person.street': 'Straße', 'person.house_number': 'Hausnummer',
        'person.zip': 'PLZ', 'person.city': 'Ort',
        'person.phone': 'Telefon', 'person.email': 'E-Mail',
        'bank.iban': 'IBAN',
        'versicherung.insurer': 'Anbieter', 'versicherung.tariff': 'Tarif',
        'versicherung.start_date': 'Lieferbeginn / Vertragsbeginn',
        'versicherung.reference_number': 'Auftrags-/Referenznummer',
        'versicherung.sparte': 'Sparte',
        'energie.meter_number': 'Zählernummer', 'energie.malo_id': 'MaLo-ID',
        'energie.consumption_kwh': 'Verbrauch', 'energie.tariff': 'Tarif',
        'energie.working_price': 'Arbeitspreis', 'energie.base_price': 'Grundpreis',
        'energie.grid_operator': 'Netzbetreiber',
        'energie.customer_number': 'Kundennummer beim Anbieter'
    };

    function fieldLabel(key) {
        return FIELD_LABELS[key] || key.split('.').pop();
    }

    // Unsicherheits-Hinweis. Liegt eine feldgenaue Bewertung vor, wird sie
    // benutzt: der Mitarbeiter soll NUR die unsicheren Angaben kontrollieren
    // muessen. Ohne sie (aeltere Analysen, KI-Ergebnisse) bleibt es beim
    // bisherigen Verhalten mit den vier Standardfeldern. Es wird nie etwas
    // geraten - was fehlt, ergaenzt der Mitarbeiter bewusst.
    function renderUncertainty(extracted, confidence, source) {
        var box = el('review-uncertainty');
        var p = (extracted || {}).person || {};
        var status = (extracted || {}).feldstatus || null;
        var lowConf = (source === 'ocr') || (confidence != null && confidence < 60);

        var missing = [], check = [], conflict = [];
        if (status && Object.keys(status).length) {
            Object.keys(status).forEach(function(key) {
                var st = (status[key] || {}).status;
                if (st === 'fehlt') missing.push(fieldLabel(key));
                else if (st === 'pruefen') check.push(fieldLabel(key));
                else if (st === 'widerspruch') conflict.push(fieldLabel(key));
            });
        } else {
            [
                { label: 'Geburtsdatum', ok: !!p.birth_date },
                { label: 'Adresse', ok: !!(p.street || p.zip || p.city) },
                { label: 'Telefon', ok: !!p.phone },
                { label: 'E-Mail', ok: !!p.email }
            ].forEach(function(c) { if (!c.ok) missing.push(c.label); });
        }

        if (!lowConf && !missing.length && !check.length && !conflict.length) {
            box.style.display = 'none';
            return;
        }
        box.style.display = '';
        el('review-uncertainty-head').textContent = conflict.length
            ? '⚠ Widersprüchliche Angaben – bitte diese Felder klären.'
            : (lowConf
                ? '⚠ Unsichere Erkennung – bitte die genannten Felder prüfen.'
                : '⚠ Einige Angaben konnten nicht sicher gelesen werden.');

        // textContent statt HTML: die Werte stammen aus einem fremden Dokument.
        var lines = [];
        if (conflict.length) {
            lines.push('Widersprüchliche Angaben – nichts übernommen, bitte manuell prüfen: '
                + conflict.join(', ') + '.');
        }
        if (check.length) {
            lines.push('Erkannt, aber nicht eindeutig (bitte prüfen): ' + check.join(', ') + '.');
        }
        if (missing.length) {
            lines.push('Nicht automatisch gelesen (bitte manuell ergänzen): ' + missing.join(', ') + '.');
        }
        var wrap = el('review-uncertainty-missing');
        wrap.innerHTML = '';
        lines.forEach(function(line) {
            var div = document.createElement('div');
            div.textContent = line;
            div.style.marginTop = '2px';
            wrap.appendChild(div);
        });
    }

    function renderContract(doc) {
        var ins = (doc.extracted || {}).versicherung || {};
        var kfz = (doc.extracted || {}).kfz || {};
        var energie = (doc.extracted || {}).energie || {};
        var net = (doc.extracted || {}).internet || {};
        var person = (doc.extracted || {}).person || {};
        // Ausweis-/Dokumentennummer sichtbar machen (wird nicht dauerhaft
        // gespeichert - Datenminimierung), damit sie nicht unbemerkt verfaellt.
        var idNote = el('review-identity-note');
        if (idNote) {
            if (person.id_number) {
                idNote.textContent = 'ℹ️ Ausweis-/Dokumentennummer erkannt: ' + person.id_number + ' (wird nicht dauerhaft gespeichert).';
                idNote.style.display = '';
            } else {
                idNote.style.display = 'none';
            }
        }
        // Zaehlerfoto: der erkannte Stand gehoert in die Verbrauchshistorie,
        // nicht in einen neuen Vertrag - daher eigener Hinweisblock.
        var isMeterPhoto = doc.ai_type === 'zaehlerfoto' && (energie.meter_number || energie.meter_reading);
        el('review-meter-section').style.display = isMeterPhoto ? '' : 'none';
        if (isMeterPhoto) {
            var meterParts = [];
            if (energie.meter_number) meterParts.push('Zähler: ' + energie.meter_number);
            if (energie.meter_reading) {
                meterParts.push('Stand: ' + energie.meter_reading + ' ' + (energie.meter_unit || 'kWh'));
            }
            if (energie.meter_register) meterParts.push('Zählwerk: ' + energie.meter_register);
            el('review-meter-info').textContent = meterParts.join(' · ');
        }

        var has = ins.insurer || ins.contract_number || ins.reference_number;
        el('review-contract-section').style.display = has ? '' : 'none';
        el('review-create-contract').checked = false;
        if (has) {
            var parts = [];
            if (ins.insurer) parts.push(ins.insurer);
            // DREI VERSCHIEDENE NUMMERN - sie werden nie vermischt, weil sie
            // Verschiedenes bedeuten und aus verschiedenen Systemen stammen:
            //   Vertrags-Nr.  = die Nummer der Gesellschaft/des Versorgers
            //                   (gibt es beim Auftrag noch gar nicht)
            //   Auftrags-/Ref.= die Kennung des VORGANGS im Vertriebsportal;
            //                   ueber sie findet die spaetere Bestaetigung und
            //                   die Provisionsabrechnung ihren Vertrag wieder
            //   Kundennr.     = die Nummer des Kunden BEIM Anbieter (unten)
            if (ins.contract_number) parts.push('Vertrags-Nr. ' + ins.contract_number);
            if (ins.reference_number) parts.push('Auftrags-/Referenz-Nr. ' + ins.reference_number);
            if (ins.sparte) parts.push('Sparte: ' + ins.sparte);
            // Beitrag MIT Zahlweise (sonst sehen 500 €/Jahr und 500 €/Monat gleich aus).
            if (ins.premium_amount) {
                var iv = { monthly: '/Monat', quarterly: '/Quartal', 'semi-annually': '/Halbjahr', yearly: '/Jahr', 'one-time': ' einmalig', einmalig: ' einmalig' }[ins.premium_interval] || '';
                parts.push(ins.premium_amount + ' €' + iv);
            }
            if (ins.start_date) parts.push('ab ' + ins.start_date);
            // Fahrzeug: die uebernommenen Kerndaten sichtbar machen (nicht nur
            // das Kennzeichen) - der Mitarbeiter bestaetigt sonst Ungesehenes.
            if (kfz.license_plate) parts.push('Kennzeichen: ' + kfz.license_plate);
            if (kfz.manufacturer || kfz.model) parts.push([kfz.manufacturer, kfz.model].filter(Boolean).join(' '));
            if (kfz.vin) parts.push('FIN: ' + kfz.vin);
            if (kfz.first_registration) parts.push('EZ ' + kfz.first_registration);
            if (kfz.power_kw) parts.push(kfz.power_kw + ' kW');
            if (kfz.fuel_type) parts.push(kfz.fuel_type);
            if (kfz.has_vollkasko) parts.push('Vollkasko' + (kfz.vollkasko_deductible != null ? ' (SB ' + kfz.vollkasko_deductible + ' €)' : ''));
            else if (kfz.has_teilkasko) parts.push('Teilkasko' + (kfz.teilkasko_deductible != null ? ' (SB ' + kfz.teilkasko_deductible + ' €)' : ''));
            if (kfz.sf_liability_class) parts.push('SF-Haftpflicht ' + kfz.sf_liability_class);
            if (energie.meter_number) parts.push('Zähler: ' + energie.meter_number);
            if (energie.malo_id) parts.push('MaLo: ' + energie.malo_id);
            if (energie.consumption_kwh) parts.push(energie.consumption_kwh + ' kWh/Jahr');
            if (energie.meter_reading) parts.push('Stand: ' + energie.meter_reading);
            // Energie: Tarif + Preise + Netzbetreiber (bislang unsichtbar, aber
            // in den Vertrag uebernommen).
            if (energie.tariff) parts.push('Tarif: ' + energie.tariff);
            if (energie.working_price) parts.push('Arbeitspreis ' + energie.working_price + ' ct/kWh');
            if (energie.base_price) parts.push('Grundpreis ' + energie.base_price + ' €/Monat');
            if (energie.grid_operator) parts.push('Netz: ' + energie.grid_operator);
            if (energie.customer_number) parts.push('Kundennr. ' + energie.customer_number);
            if (energie.previous_provider) parts.push('Vorversorger: ' + energie.previous_provider);
            // Internet-/DSL-Auftrag: ALLE gelesenen Tarif-Details anzeigen
            // (Betreiber-Vorgabe 10.08.2026), damit der Mitarbeiter die volle
            // Uebernahme vor der Zuordnung prueft.
            if (net.tariff) parts.push('Tarif: ' + net.tariff);
            if (net.speed) parts.push(net.speed + (net.upload_speed ? ' / ' + net.upload_speed : ''));
            if (net.price_initial != null && net.price_initial_months) parts.push('Monat 1–' + net.price_initial_months + ': ' + net.price_initial + ' €');
            if (net.price_regular != null) parts.push('danach ' + net.price_regular + ' €/Monat');
            if (net.setup_fee != null) parts.push('Bereitstellung einmalig ' + net.setup_fee + ' €');
            if (net.shipping_fee != null) parts.push('Versand einmalig ' + net.shipping_fee + ' €');
            if (net.router_name) parts.push('Router: ' + net.router_name + (net.router_price != null ? ' (' + net.router_price + ' €/Monat)' : ''));
            if (net.bonus_amount != null) parts.push('Bonus ' + net.bonus_amount + ' €');
            if (net.voucher_amount != null) parts.push('Gutschrift ' + net.voucher_amount + ' €');
            if (net.min_duration_months) parts.push('Mindestlaufzeit ' + net.min_duration_months + ' Monate');
            // Stufe des Dokuments: ein Auftrag legt den Vertrag an, die
            // spaetere Bestaetigung ERGAENZT genau diesen Vertrag.
            if (ins.document_stage === 'antrag') {
                parts.push('📝 Auftrag/Antrag – Vertragsbestätigung folgt später');
            } else if (ins.document_stage === 'vertrag') {
                parts.push('✅ Vertragsbestätigung – ergänzt einen vorhandenen Auftrag, statt ihn zu verdoppeln');
            }
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

    // ---- Automatische Vorschlaege -------------------------------------
    // Beim Oeffnen des Dialogs werden die naechstliegenden Kunden zum
    // Dokument geladen (Identitaetsmerkmale wie Vertragsnummer/Kennzeichen
    // zuerst, dann Personendaten). Im Zuordnungs-Modus waehlt ein Klick den
    // Kunden aus; im Neuanlage-/Vorgang-Modus warnt die Liste vor einer
    // Dublette - so faellt "Kein Kunde gefunden" nicht mehr auf den
    // Mitarbeiter zurueck.
    var suggestSeq = 0;

    function suggestUrl(docId) {
        return @json(route('admin.documents.customer_suggestions', ['id' => '__ID__'])).replace('__ID__', encodeURIComponent(docId));
    }

    function loadSuggestions(docId, mode, extraIds) {
        var seq = ++suggestSeq;
        var box = el('review-suggestions');
        var list = el('review-suggestions-list');
        var note = el('review-suggestions-note');
        list.innerHTML = '';
        note.textContent = '';
        box.style.display = '';
        el('review-suggestions-head').textContent = '🔎 Passende Kunden werden gesucht…';

        var url = suggestUrl(docId);
        (extraIds || []).forEach(function(id, i) { url += (i === 0 ? '?' : '&') + 'ids[]=' + encodeURIComponent(id); });

        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(res) {
                if (seq !== suggestSeq) return; // veraltete Antwort verwerfen
                renderSuggestions(res, mode);
            })
            .catch(function() {
                if (seq !== suggestSeq) return;
                box.style.display = 'none';
            });
    }

    function renderSuggestions(res, mode) {
        var box = el('review-suggestions');
        var list = el('review-suggestions-list');
        var note = el('review-suggestions-note');
        var items = (res && res.suggestions) || [];
        var hidden = (res && res.hidden) || 0;

        if (!items.length) {
            if (!hidden) { box.style.display = 'none'; return; }
            el('review-suggestions-head').textContent = '👤 Möglicher Kunde erkannt';
            note.textContent = hidden + ' möglicher Kunde liegt außerhalb Ihres Portfolios – bitte an Admin/Manager übergeben.';
            return;
        }

        el('review-suggestions-head').textContent = mode === 'assign'
            ? '🔎 Vorschläge aus dem Dokument – bitte prüfen und auswählen'
            : '⚠ Ähnliche Kunden gefunden – vielleicht ist es einer davon?';

        items.forEach(function(s) {
            list.appendChild(suggestionRow(s, mode));
        });

        note.textContent = hidden
            ? 'Weitere ' + hidden + ' mögliche Kunden liegen außerhalb Ihres Portfolios.'
            : (mode === 'assign' ? '' : 'Trifft keiner zu, unten wie geplant einen neuen Kunden anlegen.');
    }

    // Eine Vorschlagszeile. Alle Werte per textContent - nichts aus der
    // Analyse wird als HTML interpretiert.
    function suggestionRow(s, mode) {
        var clickable = mode === 'assign' || mode === 'create';
        var row = document.createElement(clickable ? 'button' : 'div');
        row.style.cssText = 'display:block;width:100%;text-align:start;border:1px solid ' + (s.score > 90 ? '#17A65B' : 'var(--line)')
            + ';background:#fff;border-radius:8px;padding:9px 11px;font-size:13px;'
            + (clickable ? 'cursor:pointer;' : '');
        if (clickable) row.type = 'button';

        var head = document.createElement('div');
        head.style.cssText = 'display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;';
        var name = document.createElement('strong');
        name.textContent = (s.name || '—') + (s.customer_number ? ' · ' + s.customer_number : '');
        var score = document.createElement('span');
        score.style.cssText = 'font-size:12px;color:' + (s.score > 90 ? '#128a4b' : 'var(--ink-soft)') + ';';
        score.textContent = 'Übereinstimmung ' + s.score + '%';
        head.appendChild(name); head.appendChild(score);
        row.appendChild(head);

        (s.reasons || []).forEach(function(reason) {
            var line = document.createElement('div');
            line.style.cssText = 'font-size:12px;color:var(--ink-soft);margin-top:2px;';
            line.textContent = '• ' + reason;
            row.appendChild(line);
        });

        if (clickable) {
            var hint = document.createElement('div');
            hint.style.cssText = 'font-size:12px;color:#128a4b;font-weight:600;margin-top:5px;';
            hint.textContent = 'Diesem Kunden zuordnen →';
            row.appendChild(hint);
            row.onclick = function() { pickSuggestion(s); };
        }
        return row;
    }

    // Vorschlag uebernehmen. Aus der Neuanlage heraus wird dabei bewusst in
    // den Zuordnungs-Modus gewechselt (statt eine Dublette anzulegen).
    function pickSuggestion(s) {
        var label = (s.name || '—') + ' (' + (s.customer_number || '') + ')';
        if (current && current.mode === 'create') {
            current.mode = 'assign';
            el('review-title').textContent = 'Dokument zuordnen';
            el('review-assign-block').style.display = '';
            el('review-create-block').style.display = 'none';
            el('review-name-block').style.display = 'none';
            el('review-submit').textContent = 'Zuordnen & übernehmen';
        }
        chooseCustomer(s.customer_id, label);
        el('review-error').style.display = 'none';
        el('review-suggestions').scrollIntoView({ block: 'nearest' });
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

        // Neuanlage: der (ggf. manuell eingetippte) Name ist Pflicht - so laesst
        // sich ein Kunde auch anlegen, wenn der Name nicht gelesen wurde.
        if (isCreate) {
            var first = el('review-first-name').value.trim();
            var last = el('review-last-name').value.trim();
            if (!first && !last) {
                showError('Bitte den Namen des Kunden eintragen (Vorname und/oder Nachname).');
                el('review-first-name').focus();
                return;
            }
            payload.first_name = first;
            payload.last_name = last;
            // Werber (Neukunden-Bericht/Provision) - Select existiert nur
            // fuer admin/manager.
            if (el('review-werber') && el('review-werber').value) {
                payload.werber = el('review-werber').value;
            }
        }

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
        // Wurde durch den Vertrag automatisch eine Portal-Einladung versendet,
        // dem Mitarbeiter Rueckmeldung geben (er muss sie nicht mehr manuell ausloesen).
        if (data.invited) {
            sub += ' 📧 Einladung zum Portal wurde automatisch an den Kunden versendet.';
        }
        el('review-success-msg').textContent = '✅ ' + msg;
        el('review-success-sub').textContent = sub;
        el('review-success-link').href = data.customer_url || '#';
        el('review-body').style.display = 'none';
        el('review-uncertainty').style.display = 'none';
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

    function reanalyze(docId, btn, forceAi) {
        btn.disabled = true;
        fetch(@json(route('admin.documents.reanalyze', ['id' => '__ID__'])).replace('__ID__', docId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
            },
            body: JSON.stringify({ force_ai: forceAi ? 1 : 0 }),
        }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
        .then(function(res) {
            if (res.ok) { window.location.reload(); return; }
            btn.disabled = false;
            alert(res.json.message || 'Analyse konnte nicht gestartet werden.');
        })
        .catch(function() { btn.disabled = false; });
    }

    /**
     * Zeigt den TATSAECHLICH erkannten Text eines Dokuments.
     *
     * Fehlt ein Feld, sieht man im Bild die Angabe klar stehen - die
     * Erkennung arbeitet aber mit diesem Text, und der kann an genau einer
     * Stelle anders aussehen ("Maii" statt "Mail", "©" statt "@"). Ohne ihn
     * ist jede Fehlersuche Raten. Kostenlos, ohne KI; der Text wird nicht
     * gespeichert, sondern bei jedem Aufruf neu aus der Datei gelesen.
     */
    function showOcrText(docId, btn) {
        btn.disabled = true;
        fetch(@json(route('admin.documents.ocr_text', ['id' => '__ID__'])).replace('__ID__', docId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
        .then(function(res) {
            btn.disabled = false;
            if (!res.ok) { alert(res.json.message || 'Der erkannte Text konnte nicht gelesen werden.'); return; }
            el('ocr-text-source').textContent = (res.json.quelle || '') + ' · ' + res.json.zeichen + ' Zeichen';
            el('ocr-text-note').textContent = res.json.hinweis || '';
            // textContent, nie innerHTML: der Text stammt aus einem fremden Dokument.
            el('ocr-text-body').textContent = res.json.text || '';
            el('ocr-text-modal').style.display = 'flex';
        })
        .catch(function() { btn.disabled = false; });
    }

    function copyOcrText(btn) {
        var text = el('ocr-text-body').textContent || '';
        if (!navigator.clipboard) { return; }
        navigator.clipboard.writeText(text).then(function() {
            var alt = btn.textContent;
            btn.textContent = '✓ Kopiert';
            setTimeout(function() { btn.textContent = alt; }, 1500);
        });
    }

    /**
     * Mehrere Personen auf EINER Aufnahme (z.B. die Gesundheitskarten einer
     * Familie): fuer jede erkannte Person einen eigenen Kunden anlegen.
     * Bereits erfasste Personen werden uebersprungen und gemeldet.
     */
    function createFromPersons(docId, count, btn) {
        if (!confirm('Fuer die ' + count + ' erkannten Personen je einen Kunden anlegen?\n\n'
            + 'Bereits erfasste Personen werden uebersprungen.')) return;
        btn.disabled = true;
        fetch(@json(route('admin.documents.create_customers_persons', ['id' => '__ID__'])).replace('__ID__', docId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': @json(csrf_token()),
            },
            body: JSON.stringify({}),
        }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
        .then(function(res) {
            if (!res.ok) {
                btn.disabled = false;
                alert(res.json.message || 'Kunden konnten nicht angelegt werden.');
                return;
            }
            var msg = (res.json.created || []).length + ' Kunden angelegt:\n'
                + (res.json.created || []).map(function(c) { return '· ' + c.name + ' (' + c.customer_number + ')'; }).join('\n');
            if ((res.json.skipped || []).length) {
                msg += '\n\nUebersprungen (bereits erfasst):\n'
                    + res.json.skipped.map(function(s) { return '· ' + s.name; }).join('\n');
            }
            alert(msg);
            window.location.reload();
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
        var all = el('inbox-select-all'); if (all) all.checked = false;
        updateSelectionBar();
    }
    // Alle Eingangs-Dokumente auf einmal aus-/abwaehlen.
    function selectAll(checked) {
        document.querySelectorAll('.inbox-select').forEach(function(cb) { cb.checked = checked; });
        updateSelectionBar();
    }
    // Ausgewaehlte Dokumente gemeinsam loeschen (Bulk-Delete).
    function bulkDelete() {
        var ids = selectedIds();
        if (!ids.length) return;
        if (!confirm(ids.length + ' Dokument(e) wirklich loeschen? Das kann nicht rueckgaengig gemacht werden.')) return;
        fetch(@json(route('admin.documents.bulk_delete')), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
            body: JSON.stringify({ document_ids: ids }),
        }).then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
        .then(function(res) {
            if (res.ok && res.json.ok) { window.location.reload(); }
            else { alert(res.json.message || 'Loeschen fehlgeschlagen.'); }
        }).catch(function() { alert('Netzwerkfehler.'); });
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
        selectAll: selectAll,
        bulkDelete: bulkDelete,
        stay: stay,
        close: function() {
            el('doc-review-modal').style.display = 'none';
            current = null;
            // Laufende Vorschlags-Antwort verwerfen, sonst blendet sie sich
            // in den naechsten (anderen) Dialog hinein.
            suggestSeq++;
            el('review-suggestions').style.display = 'none';
        },
        submit: submit,
        reanalyze: reanalyze,
        showOcrText: showOcrText,
        copyOcrText: copyOcrText,
        closeOcrText: function() { el('ocr-text-modal').style.display = 'none'; },
        createFromPersons: createFromPersons,
    };
})();

// Schnellvorschau (Quick-Look): beim Ueberfahren eines Dokuments erscheint nach
// kurzer Verzoegerung eine Vorschau (PDF/Bild im iframe) neben der Zeile - ohne
// eine neue Seite zu oeffnen. Ein Klick oeffnet das Dokument weiterhin voll.
(function() {
    var ql = document.getElementById('doc-quicklook');
    var frame = document.getElementById('doc-quicklook-frame');
    var nameEl = document.getElementById('doc-quicklook-name');
    if (!ql || !frame) return;
    var showTimer = null, hideTimer = null;

    function place(rect) {
        var w = ql.offsetWidth, h = ql.offsetHeight;
        var top = Math.min(Math.max(10, rect.top), window.innerHeight - h - 10);
        var left = rect.right + 14;
        if (left + w > window.innerWidth - 10) { left = Math.max(10, rect.left - w - 14); }
        ql.style.top = top + 'px';
        ql.style.left = left + 'px';
    }
    function show(target) {
        var url = target.getAttribute('data-preview-url');
        var name = target.getAttribute('data-preview-name') || '';
        nameEl.textContent = name;
        if (frame.getAttribute('data-url') !== url) { frame.src = url; frame.setAttribute('data-url', url); }
        ql.style.display = 'block';
        place(target.getBoundingClientRect());
    }
    function hide() { ql.style.display = 'none'; }

    document.addEventListener('mouseover', function(e) {
        var t = e.target.closest ? e.target.closest('[data-preview-url]') : null;
        if (!t) return;
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        showTimer = setTimeout(function() { show(t); }, 650);
    });
    document.addEventListener('mouseout', function(e) {
        var t = e.target.closest ? e.target.closest('[data-preview-url]') : null;
        if (!t) return;
        clearTimeout(showTimer);
        hideTimer = setTimeout(hide, 250);
    });
    ql.addEventListener('mouseenter', function() { clearTimeout(hideTimer); });
    ql.addEventListener('mouseleave', hide);
    window.addEventListener('scroll', hide, true);
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

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["66dc0af444"] = function (event) { if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('inbox-files').click();} };
window.__h["3cd194d928"] = function (event) { docReview.selectAll(this.checked) };
window.__h["stapel-oeffnen"] = function (event) { docReview.openBatch(this.dataset.batch) };
window.__h["30707c1762"] = function (event) { docReview.openSelection() };
window.__h["654337d4fb"] = function (event) { docReview.bulkDelete() };
window.__h["e29850ed68"] = function (event) { docReview.clearSelection() };
window.__h["466c2de1a6"] = function (event) { docReview.copyOcrText(this) };
window.__h["81bc6bbfae"] = function (event) { docReview.closeOcrText() };
window.__h["d10e859b20"] = function (event) { docReview.close() };
window.__h["368883223d"] = function (event) { docReview.submit() };
window.__h["ead9668286"] = function (event) { docReview.stay() };
</script>
@endPushOnce
