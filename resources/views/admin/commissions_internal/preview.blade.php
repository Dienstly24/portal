@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.commissions_internal.index') }}">Interne Provisionen</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.commissions_internal.import') }}">Import</a><span class="breadcrumb-sep">›</span>
        <span>{{ $import->filename }}</span>
    </div>
    <div class="page-title">Vorschau: {{ $import->filename }}</div>
    <div class="page-sub">
        @if($import->isDraft())
            Schritte 2–4 – <b>es wurde noch nichts übernommen.</b>
        @else
            {{ $import->statusLabel() }}{{ $import->confirmed_at ? ' am ' . $import->confirmed_at->lokal()->format('d.m.Y H:i') : '' }}.
        @endif
    </div>
</div>

@include('admin.commissions_internal._tabs', ['active' => 'import'])
@include('admin.commissions_internal._flash')

{{-- Schritt 2: was hat das System in der Datei erkannt? Diese Angaben sind
     der schnellste Weg, einen falsch gelesenen Export zu bemerken - eine
     Datei mit 1 Spalte hatte das falsche Trennzeichen. --}}
<div class="card" style="max-width:1200px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Schritt 2 · Datei erkannt</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;font-size:13px;">
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Dateiname</div><div style="font-weight:600;">{{ $import->filename }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Format</div><div style="font-weight:600;">{{ strtoupper($import->format) }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Erkannte Quelle</div><div style="font-weight:600;">{{ $import->providerLabel() }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Betriebsart</div><div style="font-weight:600;">{{ $import->modeLabel() }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Zeilen (ohne Kopf)</div><div style="font-weight:600;">{{ $import->rows_total }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Spalten</div><div style="font-weight:600;">{{ count((array) $import->header) }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Trennzeichen</div><div style="font-weight:600;">{{ $import->delimiterLabel() }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Kodierung</div><div style="font-weight:600;">{{ $import->encoding ?: '—' }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Tabellenblatt</div><div style="font-weight:600;">{{ $import->sheet_name ?: '— (keine Excel-Datei)' }}</div></div>
    </div>

    @if($import->providerHint())
    <div style="margin-top:14px;background:#EEF6F1;border:1px solid #9BD9BB;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>{{ $import->providerLabel() }}.</b> {{ $import->providerHint() }}
    </div>
    @else
    <div style="margin-top:14px;background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>Quelle nicht erkannt.</b> Das ist kein Fehler – die Datei lässt sich trotzdem importieren.
        Bitte in Schritt 3 prüfen, ob die Spalten richtig zugeordnet sind; die Zuordnung entscheidet,
        nicht die Erkennung.
    </div>
    @endif

    @if(count((array) $import->sheet_names) > 1)
    <div style="margin-top:14px;background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>Diese Datei hat mehrere Tabellenblätter:</b>
        @foreach((array) $import->sheet_names as $name)
            <span style="display:inline-block;padding:2px 8px;border-radius:12px;background:{{ $name === $import->sheet_name ? '#131A17' : '#fff' }};color:{{ $name === $import->sheet_name ? '#fff' : 'inherit' }};border:1px solid var(--line);margin:2px;">{{ $name }}</span>
        @endforeach
        <div style="margin-top:6px;color:var(--ink-soft);">
            Gelesen wurde <b>{{ $import->sheet_name }}</b>. Ist das nicht das richtige Blatt, die Datei bitte erneut hochladen
            und das Blatt dabei angeben – das erste Blatt ist nicht immer das gemeinte.
        </div>
    </div>
    @endif
</div>

{{-- Schritt 3: Spaltenzuordnung. Der Vorschlag stimmt bei den bekannten
     Formaten; aenderbar bleibt er trotzdem, weil die naechste Gesellschaft
     ihre Spalten anders nennt. --}}
<div class="card" style="max-width:1200px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:6px;">Schritt 3 · Spalten zuordnen</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Links das Feld im Portal, rechts die Spalte aus der Datei. Pflicht sind der <b>Provisionsbetrag</b>
        und mindestens <b>eine Kennung</b> (Interne Vertragsnummer, Referenz-Nr., Id oder Auftr.-Nr.).
    </div>

    @if($mapErrors !== [])
    <div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12.5px;color:#A32D2D;">
        @foreach($mapErrors as $error)<div>• {{ $error }}</div>@endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('admin.commissions_internal.remap', $import->id) }}">
        @csrf
        {{-- Die Betriebsart entscheidet, welche Spalten Pflicht sind. Sie
             gehoert deshalb ueber die Zuordnung, nicht daneben. --}}
        <div class="field" style="max-width:420px;">
            <label>Betriebsart der Datei</label>
            <select name="modus" @disabled(!$import->isDraft())>
                @foreach($modes as $key => $label)
                <option value="{{ $key }}" @selected($import->mode === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">
                Eine <b>Auftrags-/Kundenliste</b> hat keine Beträge – aus ihr entstehen nur Kunden und Verträge,
                nie eine Provision.
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;">
            @foreach($fields as $field => $definition)
            <div class="field" style="margin:0;">
                <label>
                    {{ $definition['label'] }}
                    @if(in_array($field, ['amount'], true))<span style="color:#A32D2D;">*</span>@endif
                </label>
                <select name="spalte[{{ $field }}]" @disabled(!$import->isDraft())>
                    <option value="">— nicht zugeordnet —</option>
                    @foreach((array) $import->header as $index => $label)
                    <option value="{{ $index }}" @selected((string) (($import->column_map[$field] ?? null)) === (string) $index)>
                        {{ $label !== '' ? $label : '(Spalte ' . ($index + 1) . ')' }}
                    </option>
                    @endforeach
                </select>
                @if($definition['hint'] ?? null)
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:3px;">{{ $definition['hint'] }}</div>
                @endif
            </div>
            @endforeach
        </div>
        @if($import->isDraft())
        <button type="submit" class="btn" style="margin-top:16px;">Zuordnung übernehmen und neu prüfen</button>
        @endif
    </form>
</div>

{{-- Schritt 4: Zusammenfassung. Genau diese fuenf Zahlen entscheiden, ob der
     Admin bestaetigt - deshalb stehen sie ueber der Zeilenliste, nicht
     darunter. --}}
@php
    $summary = [
        ['neu', 'neue Datensätze', $import->rows_new, '#128a4b'],
        ['aktualisiert', 'aktualisierte Datensätze', $import->rows_updated, '#1E6FA8'],
        ['duplikat', 'Duplikate (werden übersprungen)', $import->rows_duplicate, 'var(--ink-soft)'],
        ['nicht_zugeordnet', 'ohne passenden Vertrag', $import->rows_unmatched, '#B5651D'],
        ['fehlerhaft', 'fehlerhafte Datensätze', $import->rows_invalid, '#A32D2D'],
    ];
@endphp
<div class="card" style="max-width:1200px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Schritt 4 · Prüfergebnis</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;">
        @foreach($summary as [$key, $label, $value, $color])
        <a href="{{ route('admin.commissions_internal.preview', [$import->id, 'zeigen' => $key]) }}"
           style="text-decoration:none;color:inherit;border:1px solid {{ $filter === $key ? '#131A17' : 'var(--line)' }};border-radius:10px;padding:12px 14px;display:block;">
            <div style="font-size:22px;font-weight:700;color:{{ $color }};">{{ $value }}</div>
            <div style="font-size:11.5px;color:var(--ink-soft);">{{ $label }}</div>
        </a>
        @endforeach
    </div>

    @if(!$import->isDraft() && ($import->contracts_created > 0 || $import->customers_created > 0 || $import->rows_unlinked_kept > 0))
    <div style="margin-top:14px;background:#D9F4E6;border:1px solid #9BD9BB;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>Übernommen.</b>
        @if($import->contracts_created > 0)
        {{ $import->contracts_created }} Verträge
        @if($import->customers_created > 0) und {{ $import->customers_created }} Kundenakten @endif
        wurden neu angelegt (Status „In Bearbeitung“) –
        <a href="{{ route('admin.contracts', ['gruppe' => 'pending']) }}">zur Prüfung</a>.
        @endif
        @if($import->rows_unlinked_kept > 0)
        {{ $import->rows_unlinked_kept }} Provisionen wurden ohne Vertrag aufbewahrt –
        <a href="{{ route('admin.commissions_internal.index', ['zuordnung' => 'offen']) }}">Liste „Nicht zugeordnet“</a>.
        @endif
    </div>
    @endif

    @if($import->rows_unmatched + $import->rows_invalid > 0)
    <div style="margin-top:14px;font-size:12.5px;">
        <a href="{{ route('admin.commissions_internal.errors', $import->id) }}">⬇ Fehlerhafte und nicht zugeordnete Zeilen als CSV herunterladen</a>
        <span style="color:var(--ink-soft);"> – korrigieren und erneut hochladen; bereits übernommene Zeilen entstehen dabei nicht doppelt.</span>
    </div>
    @endif

    @if($import->isDraft())
    <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--line);">
        <div style="font-weight:700;font-size:14px;margin-bottom:6px;">Schritt 5 · Bestätigen</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px;">
            @if($import->isAbrechnung())
            Übernommen werden <b>{{ $import->applicableCount() + $import->rows_unmatched }}</b> Zeilen:
            {{ $import->rows_new }} neu, {{ $import->rows_updated }} aktualisiert und
            <b>{{ $import->rows_unmatched }} ohne Vertrag</b> – auch sie werden gespeichert und stehen danach
            in der Liste „Nicht zugeordnet“, wo sie sich jederzeit von Hand verknüpfen lassen.
            Nur Duplikate und fehlerhafte Zeilen werden übersprungen.
            @else
            Diese Datei enthält keine Beträge – es wird <b>keine Provision</b> gebucht.
            Übernommen werden nur Kunden und Verträge, und nur wenn Sie das unten ausdrücklich anhaken.
            @endif
        </div>

        {{-- Der Haken, der aus fremden Zeilen eigenen Bestand macht. Er ist
             bewusst nicht vorausgewählt und nennt die Anzahl VORHER: ein Lauf
             kann hunderte Akten anlegen. --}}
        <form method="POST" action="{{ route('admin.commissions_internal.confirm', $import->id) }}" style="margin:0;"
              data-h-submit="4b3b78fcbc">
            @csrf
            @if($import->rows_buildable > 0)
            <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;max-width:760px;margin-bottom:14px;
                          background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:12px 14px;">
                <input type="checkbox" name="vertraege_anlegen" value="1" style="margin-top:2px;">
                <span>
                    <b>Fehlende Verträge (und Kunden) anlegen – {{ $import->rows_buildable }} Zeilen.</b>
                    Aus jeder dieser Zeilen entsteht ein Vertrag mit den Angaben der Datei
                    (Gesellschaft, Sparte, Produkt, beide Vertragsnummern). Gibt es den Kunden noch nicht,
                    wird auch eine Kundenakte angelegt – ein <b>vorhandener</b> Kunde wird nie dupliziert.
                    <br>
                    Jeder neue Vertrag bekommt den Status <b>„In Bearbeitung“</b> und zählt damit <b>nicht</b>
                    zum aktiven Bestand: dass eine Provision geflossen ist, belegt, dass es den Vertrag
                    <i>gab</i> – nicht, dass er heute läuft. Bitte anschließend prüfen.
                    @if($import->rows_unmatched > $import->rows_buildable)
                    <br>
                    <span style="color:var(--ink-soft);">
                        Für die übrigen {{ $import->rows_unmatched - $import->rows_buildable }} Zeilen fehlt ein
                        verwertbarer Kundenname – daraus wird bewusst nichts angelegt.
                    </span>
                    @endif
                </span>
            </label>
            @elseif($import->rows_unmatched > 0)
            <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;max-width:760px;">
                Keine der nicht zugeordneten Zeilen trägt einen verwertbaren Kundennamen –
                es lässt sich daraus kein Vertrag anlegen.
            </div>
            @endif

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">Import bestätigen</button>
        </form>
                <form method="POST" action="{{ route('admin.commissions_internal.discard', $import->id) }}" style="margin:0;"
                      data-h-submit="c8a9036d7d">
                    @csrf
                    <button type="submit" class="btn">Entwurf verwerfen</button>
                </form>
            </div>
    </div>
    @endif
</div>

<div class="card" style="max-width:1200px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <div style="font-weight:700;font-size:14px;">Zeilen der Datei</div>
        @if($filter)
        <a href="{{ route('admin.commissions_internal.preview', $import->id) }}" style="font-size:12.5px;">Filter „{{ $filter }}“ aufheben</a>
        @endif
    </div>

    @if($rows->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Keine Zeilen zu dieser Auswahl.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Zeile</th><th style="padding:8px;">Ergebnis</th>
                <th style="padding:8px;">Kennung</th><th style="padding:8px;">Betrag</th>
                <th style="padding:8px;">Datum</th><th style="padding:8px;">Vertrag</th>
                <th style="padding:8px;">Meldung</th>
            </tr></thead>
            <tbody>
            @foreach($rows as $row)
            @php $m = (array) $row->mapped; @endphp
            <tr style="border-top:1px solid var(--line);vertical-align:top;">
                <td style="padding:8px;">{{ $row->row_number }}</td>
                <td style="padding:8px;white-space:nowrap;"><span class="badge badge-{{ $row->resultBadge() }}">{{ $row->resultIcon() }} {{ $row->resultLabel() }}</span></td>
                <td style="padding:8px;">
                    {{ $m['internal_contract_number'] ?? ($m['reference_number'] ?? ($m['vermittler_id'] ?? ($m['order_number'] ?? '—'))) }}
                    @if(($m['customer_name'] ?? null))<div style="color:var(--ink-soft);">{{ $m['customer_name'] }}</div>@endif
                </td>
                <td style="padding:8px;white-space:nowrap;">
                    {{ isset($m['amount']) && $m['amount'] !== null ? number_format((float) $m['amount'], 2, ',', '.') . ' €' : '—' }}
                </td>
                <td style="padding:8px;white-space:nowrap;">{{ $m['commission_date']['__date'] ?? '—' }}</td>
                <td style="padding:8px;">
                    @if($row->contract)
                        <a href="{{ route('admin.contract.edit', $row->contract->id) }}">{{ $row->contract->contract_number ?: $row->contract->internal_contract_number ?: 'Vertrag' }}</a>
                        <div style="color:var(--ink-soft);">{{ $row->contract->customer?->user?->name }}</div>
                        @if($row->match_reason)<div style="color:var(--ink-soft);">über {{ $row->match_reason }}</div>@endif
                    @else — @endif
                </td>
                <td style="padding:8px;color:var(--ink-soft);">{{ $row->message }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px;">{{ $rows->links() }}</div>
    @endif
</div>
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["4b3b78fcbc"] = function (event) { return confirm('Import bestätigen?'); };
window.__h["c8a9036d7d"] = function (event) { return confirm('Entwurf verwerfen? Es wurde nichts übernommen.'); };
</script>
@endPushOnce
