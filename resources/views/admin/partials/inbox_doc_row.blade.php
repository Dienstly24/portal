{{-- Eine Zeile im Dokumenten-Eingang (einzeln oder innerhalb eines Vorgangs).
     Erwartet: $doc, $aiEnabled, $providerEnabled --}}
@php
    $extracted = $doc->ai_extracted ?? []; $match = $extracted['match'] ?? null;
    // Mehrere Personen auf EINER Aufnahme (z.B. die Gesundheitskarten einer
    // Familie): dann kann je Person ein Kunde angelegt werden.
    $personCount = (int) (!empty($extracted['person']) ? 1 : 0) + count($extracted['personen'] ?? []);
    // Duplikat: dieselbe Datei wurde schon einmal hochgeladen. Kundenname nur,
    // wenn der Betrachter den betreffenden Kunden ohnehin sehen darf.
    $dupOrig = $doc->duplicate_of ? $doc->duplicateOriginal : null;
    $dupCustomerName = null;
    if ($dupOrig && $dupOrig->customer_id && auth()->user()->canAccessCustomer($dupOrig->customer_id)) {
        $dupCustomerName = $dupOrig->customer?->user?->name ?? $dupOrig->customer?->customer_number;
    }
@endphp
<div style="padding:16px 20px;border-bottom:1px solid var(--line);" data-doc-row="{{ $doc->id }}" data-doc-status="{{ $doc->ai_status }}">
    <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start;">
        <div style="min-width:260px;flex:1;display:flex;gap:10px;align-items:flex-start;">
            <input type="checkbox" class="inbox-select" value="{{ $doc->id }}" data-doc-name="{{ $doc->file_name }}" title="Auswaehlen, um mehrere Dokumente zu EINEM Kunden zusammenzufuehren (z.B. Ausweis-Vorderseite + Rueckseite + Antrag)." style="margin-top:3px;cursor:pointer;">
            <div style="flex:1;">
                <div style="font-weight:600;font-size:14px;">
                    📄 <a href="{{ route('admin.documents.download', $doc->id) }}?view=1" target="_blank" title="Ueberfahren zeigt eine Schnellvorschau, Klick oeffnet"
                        data-preview-url="{{ route('admin.documents.download', $doc->id) }}?view=1" data-preview-name="{{ $doc->file_name }}">{{ $doc->file_name }}</a>
                    @if($doc->page_count)<span style="font-weight:400;color:var(--ink-soft);font-size:12.5px;"> · {{ $doc->page_count }} Seiten</span>@endif
                </div>
                <div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;">
                    Hochgeladen {{ $doc->created_at->lokal()->format('d.m.Y H:i') }}@if($doc->uploader) von {{ $doc->uploader->name }}@endif
                </div>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    @if($doc->aiInProgress())
                        <span class="badge" style="background:#FEF3C7;color:#92400E;">⏳ Wird analysiert…</span>
                    @elseif($doc->ai_status === 'done')
                        <span class="badge" style="background:#d9f4e6;color:#128a4b;">✓ {{ $doc->aiTypeLabel() ?? 'Erkannt' }}</span>
                        @if($doc->ai_confidence !== null)<span class="badge" style="background:#EEF0F3;color:var(--ink-soft);">{{ $doc->ai_confidence }}% sicher</span>@endif
                        @if($doc->ai_source === 'ocr')<span class="badge" style="background:#FEF3C7;color:#92400E;" title="Ohne KI-Anbieter erkannt (Tesseract-OCR) - Ergebnis bitte besonders sorgfaeltig pruefen.">OCR, ohne KI</span>@endif
                        @if($doc->ai_source === 'template')<span class="badge" style="background:#E6F1FB;color:#185FA5;" title="Bekanntes Formular per fester Regel gelesen - gratis, ohne KI.">📄 Vorlage, gratis</span>@endif
                    @elseif($doc->ai_status === 'failed')
                        <span class="badge" style="background:#FBE9E9;color:#B3261E;">Analyse fehlgeschlagen</span>
                    @else
                        <span class="badge" style="background:#EEF0F3;color:var(--ink-soft);">Ohne Analyse</span>
                    @endif
                </div>
                @if($dupOrig)
                {{-- Warnung: identische Datei bereits im System vorhanden. --}}
                <div style="margin-top:10px;border:1px solid #E4A11B;background:#FEF6E7;border-radius:10px;padding:10px 12px;font-size:13px;color:#8A5A00;">
                    ⚠ <strong>Bereits vorhanden.</strong>
                    Diese Datei wurde am {{ $dupOrig->created_at->lokal()->format('d.m.Y') }} schon hochgeladen
                    @if($dupCustomerName)
                        und Kunde <strong>{{ $dupCustomerName }}</strong> zugeordnet.
                    @elseif($dupOrig->customer_id)
                        und ist bereits einem Kunden zugeordnet.
                    @else
                        und liegt noch im Eingang.
                    @endif
                    <div style="margin-top:7px;display:flex;gap:8px;flex-wrap:wrap;">
                        <a href="{{ route('admin.documents.download', $dupOrig->id) }}?view=1" target="_blank" class="btn btn-ghost btn-sm">👁 Original anzeigen</a>
                        <form method="POST" action="{{ route('admin.documents.destroy', $doc->id) }}" style="margin:0;"
                            onsubmit="return confirm('Dieses doppelte Dokument „{{ addslashes($doc->file_name) }}“ wirklich löschen?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;">🗑 Duplikat löschen</button>
                        </form>
                    </div>
                </div>
                @endif
                @if($doc->ai_summary)<div style="font-size:13px;margin-top:8px;">{{ $doc->ai_summary }}</div>@endif
                @if($doc->ai_error)<div style="font-size:12.5px;color:#B3261E;margin-top:6px;">{{ $doc->ai_error }}</div>@endif

                @if($match && ($match['out_of_portfolio'] ?? false))
                {{-- Name/Kundennummer bewusst nicht angezeigt (ausserhalb des Portfolios). --}}
                <div style="margin-top:10px;border:1px solid var(--line);background:#F7F5EF;border-radius:10px;padding:10px 12px;font-size:13px;color:var(--ink-soft);">
                    👤 Möglicher Kunde erkannt (Übereinstimmung {{ $match['score'] }}%) – liegt außerhalb Ihres Portfolios. Bitte an Admin/Manager übergeben.
                </div>
                @elseif($match)
                <div style="margin-top:10px;border:1px solid {{ $match['tier'] === 'auto' ? '#17A65B' : 'var(--line)' }};background:{{ $match['tier'] === 'auto' ? '#d9f4e6' : '#F7F5EF' }};border-radius:10px;padding:10px 12px;font-size:13px;">
                    👤 Kunde gefunden: <strong>{{ $match['name'] ?? '—' }}</strong>
                    ({{ $match['customer_number'] ?? '—' }}) · Übereinstimmung {{ $match['score'] }}%
                    <button type="button" class="btn btn-gold btn-sm" style="margin-inline-start:10px;"
                        onclick="docReview.open(@js($doc->id), 'assign', @js($match['customer_id']), @js(($match['name'] ?? '') . ' (' . ($match['customer_number'] ?? '') . ')'))">
                        Diesem Kunden zuordnen
                    </button>
                </div>
                @elseif($doc->type === 'vermittler_vorgangsliste')
                {{-- Liste mehrerer Vorgaenge: gehoert strukturell nicht in den
                     Eingang (ein Dokument = ein Kunde). Statt "Kein Kunde
                     gefunden" steht hier, was das Dokument IST und was ein
                     Klick damit macht - erledigt wird es an Ort und Stelle. --}}
                <div style="margin-top:10px;background:#E6F1FB;border:1px solid #B7D4EE;border-radius:8px;padding:10px 12px;font-size:12.5px;">
                    <b>Liste mehrerer Vorgänge – kein Kundendokument.</b>
                    Sie gehört zu keinem einzelnen Kunden und wird deshalb auch keinem zugeordnet.
                    @if(in_array(auth()->user()?->role, ['admin','manager'], true))
                    Ein Klick auf <b>„Vorgangsliste einlesen"</b> verbindet für <b>jeden</b> Vorgang
                    die Referenz-Nr. mit der Vermittler-ID – damit findet jede spätere Abrechnung ihren Vertrag.
                    Es entsteht dabei keine Provision und kein Storno.
                    @else
                    Sie wird unter „Vermittler-Abrechnung" eingelesen – das übernimmt die Geschäftsführung.
                    @endif
                </div>
                @elseif($doc->ai_status === 'done')
                <div style="margin-top:10px;font-size:13px;color:var(--ink-soft);">Kein Kunde gefunden.</div>
                @endif
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
            {{-- „Anzeigen" ist immer verfuegbar - auch bei fehlgeschlagener oder
                 laufender Analyse, damit der Mitarbeiter sofort sieht, um welches
                 Dokument es sich handelt. --}}
            <a href="{{ route('admin.documents.download', $doc->id) }}?view=1" target="_blank" class="btn btn-ghost btn-sm" title="Ueberfahren zeigt eine Schnellvorschau, Klick oeffnet"
                data-preview-url="{{ route('admin.documents.download', $doc->id) }}?view=1" data-preview-name="{{ $doc->file_name }}">👁 Anzeigen</a>
            @php
                // Der Knopf steht dort, wo die Datei schon liegt. Zusaetzlich
                // bei "Sonstiges" als Rueckfallebene: erkennt die
                // Texterkennung die Tabelle einmal nicht sicher, soll der
                // Eingang trotzdem keine Sackgasse sein.
                $istListe = $doc->type === 'vermittler_vorgangsliste';
                $listeMoeglich = in_array(auth()->user()?->role, ['admin','manager'], true)
                    && !$doc->aiInProgress()
                    && ($istListe || $doc->type === 'sonstiges');
            @endphp
            @if($listeMoeglich)
            <form method="POST" action="{{ route('admin.vermittler.from_document', $doc->id) }}" style="margin:0;"
                @unless($istListe) onsubmit="return confirm('Diese Datei als Vermittler-Vorgangsliste einlesen? Es werden nur Referenz-Nr. und Vermittler-ID verknüpft – am Vertrag selbst ändert sich nichts.');" @endunless>
                @csrf
                <button type="submit" class="btn {{ $istListe ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                    🤝 Vorgangsliste einlesen
                </button>
            </form>
            @endif
            @if(!$doc->aiInProgress())
            <button type="button" class="btn btn-primary btn-sm" onclick="docReview.open(@js($doc->id), 'assign', null, null)">Kunden zuordnen…</button>
            {{-- Immer moeglich: den Namen kann der Mitarbeiter im Modal auch
                 selbst eintragen, falls er nicht (sicher) gelesen wurde. --}}
            <button type="button" class="btn btn-gold btn-sm" onclick="docReview.open(@js($doc->id), 'create', null, null)">Neuen Kunden erstellen</button>
            @if($personCount > 1)
            {{-- Eine Aufnahme, mehrere Personen (z.B. die Gesundheitskarten
                 einer Familie): je Person ein Kunde, in einem Schritt. --}}
            <button type="button" class="btn btn-gold btn-sm"
                onclick="docReview.createFromPersons(@js($doc->id), {{ $personCount }}, this)"
                title="Fuer jede erkannte Person einen eigenen Kunden anlegen (bereits erfasste Personen werden uebersprungen)">
                👪 {{ $personCount }} Kunden anlegen
            </button>
            @endif
            @if($aiEnabled)
            {{-- Laesst die normale kostenlose Kette (Vorlagen/OCR, ggf. KI) neu
                 laufen - auch bei Duplikaten wird die Datei wirklich neu gelesen,
                 damit Parser-Verbesserungen auf Bestandsdokumente wirken. --}}
            <button type="button" class="btn btn-ghost btn-sm" onclick="docReview.reanalyze(@js($doc->id), this, false)" title="Analyse (gratis zuerst) neu ausfuehren">🔄 Neu analysieren</button>
            @endif
            @if(($providerEnabled ?? false) && in_array(auth()->user()->role, ['admin','manager'], true))
            {{-- Erzwingt bewusst die kostenpflichtige KI-Stufe (ueberspringt die
                 kostenlose OCR-Vorstufe). Nur Verwaltung (Kostenbremse) - der
                 Server prueft die Rolle und ein Tageslimit zusaetzlich. --}}
            <button type="button" class="btn btn-ghost btn-sm" onclick="docReview.reanalyze(@js($doc->id), this, true)" title="Kostenpflichtige KI-Analyse (Claude) erzwingen">🤖 Mit KI analysieren</button>
            @endif
            @endif
            {{-- Loeschen ist IMMER moeglich - auch bei laufender oder
                 festgefahrener Analyse (z.B. ausgefallener Queue-Worker),
                 damit ein in „pending"/„processing" haengendes Dokument aus dem
                 Eingang entfernt werden kann. --}}
            <form method="POST" action="{{ route('admin.documents.destroy', $doc->id) }}" style="margin:0;"
                onsubmit="return confirm('Dokument „{{ addslashes($doc->file_name) }}“ wirklich löschen?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;" title="Löschen">🗑</button>
            </form>
        </div>
    </div>
</div>
