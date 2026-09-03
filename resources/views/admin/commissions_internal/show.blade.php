@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.commissions_internal.index') }}">Interne Provisionen</a><span class="breadcrumb-sep">›</span>
        <span>{{ $commission->internal_contract_number ?: 'Provision' }}</span>
    </div>
    <div class="page-title">Provision {{ $commission->amountLabel() }}</div>
    <div class="page-sub">
        {{ $commission->commission_type ?: 'Provision' }}
        @if($commission->product_name) · {{ $commission->product_name }}@endif
        @if($commission->company) · {{ $commission->company }}@endif
    </div>
</div>

@include('admin.commissions_internal._flash')

<div class="card" style="max-width:1000px;">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div style="font-weight:700;font-size:14px;">Übersicht</div>
        <span class="badge badge-{{ $commission->statusBadge() }}">{{ $commission->statusIcon() }} {{ $commission->statusLabel() }}</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;font-size:13px;">
        @foreach([
            ['Interne Vertragsnummer', $commission->internal_contract_number],
            ['Vertragsnummer der Gesellschaft', $commission->external_contract_number],
            ['Referenz-Nr.', $commission->reference_number],
            ['Vermittler-Id', $commission->vermittler_id],
            ['Auftr.-Nr.', $commission->order_number],
            ['Datensatz-Nr. der Quelle', $commission->external_id],
            ['Provisionsempfänger', $commission->recipient_name],
            ['Vermittlernummer', $commission->recipient_number],
            ['Sparte', $commission->sparte],
            ['Provisionsdatum', $commission->commission_date?->format('d.m.Y')],
            ['Fälligkeitsdatum', $commission->due_date?->format('d.m.Y')],
            ['Zahlungsdatum', $commission->payment_date?->format('d.m.Y')],
            ['USt.-Betrag', $commission->vat_amount !== null ? number_format((float) $commission->vat_amount, 2, ',', '.') . ' €' : null],
            ['Stornoreserve', $commission->reserve_amount !== null ? number_format((float) $commission->reserve_amount, 2, ',', '.') . ' €' : null],
            ['Bereits gezahlt', $commission->paid_amount !== null ? number_format((float) $commission->paid_amount, 2, ',', '.') . ' €' : null],
            ['Quelle / Import-Datei', $commission->source_file],
        ] as [$label, $value])
        <div>
            <div style="font-size:11.5px;color:var(--ink-soft);">{{ $label }}</div>
            <div style="font-weight:600;">{{ $value ?: '—' }}</div>
        </div>
        @endforeach
    </div>

    @if($commission->storno_reason)
    <div style="margin-top:14px;background:#F9E3E3;border:1px solid #F0A0A0;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>Stornogrund:</b> {{ $commission->storno_reason }}
    </div>
    @endif
</div>

{{-- Zuordnung: der Kern des Ganzen. Fehlt sie, steht hier die Suche - nicht
     eine Fehlermeldung, mit der niemand weiterkommt. --}}
<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Vertrag &amp; Kunde</div>
    @if($commission->contract)
        <div style="font-size:13px;">
            <a href="{{ route('admin.contract.edit', $commission->contract->id) }}">
                {{ $commission->contract->typeIcon() }} {{ $commission->contract->typeLabel() }}
                @if($commission->contract->contract_number) · {{ $commission->contract->contract_number }}@endif
            </a>
            <div style="color:var(--ink-soft);margin-top:4px;">
                Kunde:
                @if($commission->contract->customer)
                <a href="{{ route('admin.customer', $commission->contract->customer_id) }}">{{ $commission->customer_label ?: '—' }}</a>
                @else {{ $commission->customer_label ?: '—' }} @endif
                @if($commission->match_reason) · zugeordnet über {{ $commission->match_reason }}@endif
            </div>
        </div>
        <form method="POST" action="{{ route('admin.commissions_internal.unlink', $commission->id) }}" style="margin-top:12px;"
              data-h-submit="82c8889ff5">
            @csrf
            <button type="submit" class="btn">Zuordnung lösen</button>
        </form>
    @else
        <div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:10px 12px;font-size:12.5px;margin-bottom:14px;">
            ⚠ <b>Kein Vertrag zugeordnet.</b>
            @if($commission->match_reason){{ $commission->match_reason }}@endif
            Es wurde bewusst nichts geraten – bitte den Vertrag suchen und zuordnen.
            @if($commission->customer_label)<div style="margin-top:4px;">Name aus der Datei: <b>{{ $commission->customer_label }}</b> (wird nie zur Zuordnung benutzt).</div>@endif
        </div>
        <form method="POST" action="{{ route('admin.commissions_internal.link', $commission->id) }}" style="max-width:520px;">
            @csrf
            <input type="hidden" name="contract_id" id="pc-contract-id">
            <div class="field" style="margin:0;">
                <label>Vertrag suchen</label>
                <input type="text" id="pc-search" autocomplete="off" placeholder="Kunde, Vertragsnummer, interne Nummer, Referenz…">
            </div>
            <div id="pc-results" style="font-size:12px;margin-top:6px;"></div>
            <button type="submit" class="btn btn-primary" id="pc-submit" style="margin-top:10px;" disabled>Zuordnen</button>
        </form>
    @endif
</div>

<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Status &amp; Zahlung</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">
        <form method="POST" action="{{ route('admin.commissions_internal.status', $commission->id) }}">
            @csrf
            <div class="field"><label>Status setzen</label>
                <select name="status">
                    @foreach($statuses as $key => $meta)
                    <option value="{{ $key }}" @selected($commission->status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Grund (optional, steht im Protokoll)</label>
                <input type="text" name="grund" maxlength="255">
            </div>
            <button type="submit" class="btn">Status speichern</button>
        </form>

        <form method="POST" action="{{ route('admin.commissions_internal.pay', $commission->id) }}">
            @csrf
            <div class="field"><label>Gezahlter Betrag *</label>
                <input type="number" step="0.01" min="0" name="betrag"
                       value="{{ $commission->paid_amount ?? $commission->amount }}" required>
            </div>
            <div class="field"><label>Zahlungsdatum *</label>
                <input type="date" name="zahlungsdatum" value="{{ $commission->payment_date?->format('Y-m-d') ?? now()->format('Y-m-d') }}" required>
            </div>
            <div class="field"><label>Rechnungsnummer (optional)</label>
                <input type="text" name="rechnungsnummer" maxlength="60" value="{{ $commission->invoice_number }}">
            </div>
            <button type="submit" class="btn btn-primary">Zahlung erfassen</button>
            <div style="font-size:11.5px;color:var(--ink-soft);margin-top:6px;">
                Ein Teilbetrag setzt den Status auf „Teilweise bezahlt“, der volle Betrag auf „Bezahlt“.
            </div>
        </form>
    </div>
</div>

<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Rechnung</div>
    @if($commission->invoice_number)
    <div style="font-size:13px;margin-bottom:12px;">
        <b>{{ $commission->invoice_number }}</b>
        @if($commission->invoice_date) · vom {{ $commission->invoice_date->format('d.m.Y') }}@endif
        @if($commission->invoice_amount !== null) · {{ number_format((float) $commission->invoice_amount, 2, ',', '.') }} €@endif
        @if($commission->invoice_linked_at)
        <div style="color:var(--ink-soft);font-size:11.5px;">verknüpft am {{ $commission->invoice_linked_at->lokal()->format('d.m.Y H:i') }}</div>
        @endif
    </div>
    <form method="POST" action="{{ route('admin.commissions_internal.invoice_unlink', $commission->id) }}">
        @csrf<button type="submit" class="btn">Verknüpfung lösen</button>
    </form>
    @else
    <form method="POST" action="{{ route('admin.commissions_internal.invoice_link', $commission->id) }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
        @csrf
        <div class="field" style="margin:0;"><label>Rechnungsnummer *</label><input type="text" name="invoice_number" maxlength="60" required></div>
        <div class="field" style="margin:0;"><label>Rechnungsdatum</label><input type="date" name="invoice_date"></div>
        <div class="field" style="margin:0;"><label>Rechnungsbetrag</label><input type="number" step="0.01" name="invoice_amount"></div>
        <button type="submit" class="btn">Rechnung verknüpfen</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink-soft);margin-top:8px;">
        Eine Rechnung belegt eine Forderung, keinen Geldeingang – die Zahlung bleibt eine eigene Bestätigung.
    </div>
    @endif
</div>

<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Interne Angaben</div>
    <form method="POST" action="{{ route('admin.commissions_internal.update', $commission->id) }}">
        @csrf @method('PUT')
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
            <div class="field" style="margin:0;"><label>Interne Vertragsnummer</label>
                <input type="text" name="internal_contract_number" maxlength="60" value="{{ $commission->internal_contract_number }}"></div>
            <div class="field" style="margin:0;"><label>Provisionsempfänger</label>
                <input type="text" name="recipient_name" maxlength="190" value="{{ $commission->recipient_name }}"></div>
            <div class="field" style="margin:0;"><label>Fälligkeitsdatum</label>
                <input type="date" name="due_date" value="{{ $commission->due_date?->format('Y-m-d') }}"></div>
        </div>
        <div class="field"><label>Interne Notiz</label><textarea name="notes" rows="3" maxlength="5000">{{ $commission->notes }}</textarea></div>
        <button type="submit" class="btn">Speichern</button>
    </form>
</div>

{{-- Protokoll: rein lesend, ohne jeden Loeschweg. --}}
<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Protokoll dieser Provision</div>
    @if($commission->auditLogs->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Noch keine Einträge.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Wann</th><th style="padding:8px;">Wer</th><th style="padding:8px;">Vorgang</th>
                <th style="padding:8px;">Feld</th><th style="padding:8px;">Vorher</th><th style="padding:8px;">Nachher</th>
            </tr></thead>
            <tbody>
            @foreach($commission->auditLogs as $entry)
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px;white-space:nowrap;">{{ $entry->created_at?->lokal()->format('d.m.Y H:i') }}</td>
                <td style="padding:8px;">{{ $entry->user?->name ?? $entry->user_label ?? 'System' }}</td>
                <td style="padding:8px;">{{ $entry->actionLabel() }}</td>
                <td style="padding:8px;color:var(--ink-soft);">{{ $entry->field ?: '—' }}</td>
                <td style="padding:8px;color:var(--ink-soft);">{{ $entry->old_value ?: '—' }}</td>
                <td style="padding:8px;">{{ $entry->new_value ?: '—' }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

@if(!$commission->contract)
<script @cspNonce>
// Sofort-Suche nach dem Vertrag - derselbe Weg wie im Aufgaben- und
// Vertragsformular. Treffer werden per textContent gebaut, NIE als
// HTML-String: Kundennamen sind Fremddaten.
(function () {
    var input = document.getElementById('pc-search');
    var results = document.getElementById('pc-results');
    var hidden = document.getElementById('pc-contract-id');
    var submit = document.getElementById('pc-submit');
    if (!input) { return; }
    var timer = null;

    input.addEventListener('input', function () {
        hidden.value = '';
        submit.disabled = true;
        clearTimeout(timer);
        var term = input.value.trim();
        if (term.length < 2) { results.textContent = ''; return; }
        timer = setTimeout(function () {
            fetch('{{ route('admin.commissions_internal.contract_search') }}?q=' + encodeURIComponent(term), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (items) {
                results.textContent = '';
                if (!items.length) { results.textContent = 'Kein Treffer.'; return; }
                items.forEach(function (item) {
                    var row = document.createElement('button');
                    row.type = 'button';
                    row.style.cssText = 'display:block;width:100%;text-align:left;padding:6px 8px;border:1px solid var(--line);border-radius:6px;margin-bottom:4px;background:#fff;cursor:pointer;';
                    row.textContent = item.label + ' · ' + item.number + ' · ' + item.customer;
                    row.addEventListener('click', function () {
                        hidden.value = item.id;
                        input.value = item.number + ' – ' + item.customer;
                        results.textContent = '';
                        submit.disabled = false;
                    });
                    results.appendChild(row);
                });
            }).catch(function () { results.textContent = 'Suche nicht erreichbar.'; });
        }, 250);
    });
})();
</script>
@endif
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["82c8889ff5"] = function (event) { return confirm('Zuordnung wirklich lösen?'); };
</script>
@endPushOnce
