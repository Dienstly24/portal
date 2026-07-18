{{-- Eine Zeile im Dokumenten-Eingang (einzeln oder innerhalb eines Vorgangs).
     Erwartet: $doc, $aiEnabled, $providerEnabled --}}
@php $extracted = $doc->ai_extracted ?? []; $match = $extracted['match'] ?? null; @endphp
<div style="padding:16px 20px;border-bottom:1px solid var(--line);" data-doc-row="{{ $doc->id }}" data-doc-status="{{ $doc->ai_status }}">
    <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start;">
        <div style="min-width:260px;flex:1;">
            <div style="font-weight:600;font-size:14px;">
                📄 <a href="{{ route('admin.documents.download', $doc->id) }}">{{ $doc->file_name }}</a>
                @if($doc->page_count)<span style="font-weight:400;color:var(--ink-soft);font-size:12.5px;"> · {{ $doc->page_count }} Seiten</span>@endif
            </div>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;">
                Hochgeladen {{ $doc->created_at->format('d.m.Y H:i') }}@if($doc->uploader) von {{ $doc->uploader->name }}@endif
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
            @if($doc->ai_summary)<div style="font-size:13px;margin-top:8px;">{{ $doc->ai_summary }}</div>@endif
            @if($doc->ai_error)<div style="font-size:12.5px;color:#B3261E;margin-top:6px;">{{ $doc->ai_error }}</div>@endif

            @if($match && ($match['out_of_portfolio'] ?? false))
            {{-- Name/Kundennummer bewusst nicht angezeigt (ausserhalb des Portfolios). --}}
            <div style="margin-top:10px;border:1px solid var(--line);background:#F4F5F7;border-radius:10px;padding:10px 12px;font-size:13px;color:var(--ink-soft);">
                👤 Möglicher Kunde erkannt (Übereinstimmung {{ $match['score'] }}%) – liegt außerhalb Ihres Portfolios. Bitte an Admin/Manager übergeben.
            </div>
            @elseif($match)
            <div style="margin-top:10px;border:1px solid {{ $match['tier'] === 'auto' ? '#17A65B' : 'var(--line)' }};background:{{ $match['tier'] === 'auto' ? '#d9f4e6' : '#F4F5F7' }};border-radius:10px;padding:10px 12px;font-size:13px;">
                👤 Kunde gefunden: <strong>{{ $match['name'] ?? '—' }}</strong>
                ({{ $match['customer_number'] ?? '—' }}) · Übereinstimmung {{ $match['score'] }}%
                <button type="button" class="btn btn-gold btn-sm" style="margin-inline-start:10px;"
                    onclick="docReview.open(@js($doc->id), 'assign', @js($match['customer_id']), @js(($match['name'] ?? '') . ' (' . ($match['customer_number'] ?? '') . ')'))">
                    Diesem Kunden zuordnen
                </button>
            </div>
            @elseif($doc->ai_status === 'done')
            <div style="margin-top:10px;font-size:13px;color:var(--ink-soft);">Kein Kunde gefunden.</div>
            @endif
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
            @if(!$doc->aiInProgress())
            <button type="button" class="btn btn-primary btn-sm" onclick="docReview.open(@js($doc->id), 'assign', null, null)">Kunden zuordnen…</button>
            @if(($extracted['person']['first_name'] ?? null) || ($extracted['person']['last_name'] ?? null))
            <button type="button" class="btn btn-gold btn-sm" onclick="docReview.open(@js($doc->id), 'create', null, null)">Neuen Kunden erstellen</button>
            @endif
            @if($providerEnabled ?? false)
            {{-- Erzwingt bewusst die kostenpflichtige KI-Stufe (ueberspringt die kostenlose OCR-Vorstufe). --}}
            <button type="button" class="btn btn-ghost btn-sm" onclick="docReview.reanalyze(@js($doc->id), this)" title="Kostenpflichtige KI-Analyse (Claude) erzwingen">🤖 Mit KI analysieren</button>
            @elseif($aiEnabled)
            <button type="button" class="btn btn-ghost btn-sm" onclick="docReview.reanalyze(@js($doc->id), this)">🔄 Neu analysieren</button>
            @endif
            <form method="POST" action="{{ route('admin.documents.destroy', $doc->id) }}" style="margin:0;"
                onsubmit="return confirm('Dokument „{{ $doc->file_name }}“ wirklich löschen?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;" title="Löschen">🗑</button>
            </form>
            @endif
        </div>
    </div>
</div>
