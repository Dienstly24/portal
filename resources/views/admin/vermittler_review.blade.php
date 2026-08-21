@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">Prüfliste der Vermittler-Abrechnung</div>
    <div class="page-sub">Alles, was sich nicht eindeutig zuordnen ließ. Jede Zeile braucht eine Entscheidung – automatisch wird hier nichts verknüpft.</div>
</div>

@include('admin.partials.vermittler_tabs', ['active' => 'pruefung'])

@if(session('success'))
<div style="background:#D9F4E6;border:1px solid #9BD9BB;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:1100px;font-size:13px;">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:1100px;font-size:13px;color:#A32D2D;">{{ session('error') }}</div>
@endif

<div class="card" style="max-width:1100px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Datensätze ohne eindeutigen Vertrag</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Der Vermittler rechnet hier etwas ab, das wir nicht sicher zuordnen können. Suchen Sie den Vertrag und ordnen Sie ihn bewusst zu –
        oder lassen Sie die Zeile offen, bis geklärt ist, worum es geht.
    </div>

    @if($settlements->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">✓ Keine offenen Datensätze.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Id</th><th style="padding:8px;">Datum</th><th style="padding:8px;">Produkt</th>
                <th style="padding:8px;">Referenz-Nr.</th><th style="padding:8px;">Provision</th>
                <th style="padding:8px;">Hinweis</th><th style="padding:8px;width:320px;">Vertrag zuordnen</th>
            </tr></thead>
            <tbody>
            @foreach($settlements as $row)
                <tr style="border-top:1px solid var(--line);vertical-align:top;">
                    <td style="padding:8px;font-weight:600;">{{ $row->vermittler_id }}</td>
                    <td style="padding:8px;">{{ $row->statement_date?->format('d.m.Y') ?: '—' }}</td>
                    <td style="padding:8px;">{{ $row->produkt ?: '—' }}</td>
                    <td style="padding:8px;">{{ $row->reference_number ?: '—' }}</td>
                    <td style="padding:8px;">{{ $row->provision !== null ? number_format((float) $row->provision, 2, ',', '.') . ' €' : '—' }}</td>
                    <td style="padding:8px;">
                        <span class="badge badge-{{ $row->resultBadge() }}">{{ $row->resultLabel() }}</span>
                        @if($row->match_note)<div style="color:var(--ink-soft);font-size:11.5px;margin-top:4px;">{{ $row->match_note }}</div>@endif
                        @if($row->contract)
                            <div style="margin-top:4px;"><a href="{{ route('admin.contract.edit', $row->contract->id) }}">{{ $row->contract_label }}</a></div>
                        @endif
                    </td>
                    <td style="padding:8px;">
                        <form method="POST" action="{{ route('admin.vermittler.link', $row->id) }}" style="margin:0;">
                            @csrf
                            <input type="hidden" name="contract_id" id="cid-{{ $row->id }}">
                            <input type="text" class="vm-search" data-target="{{ $row->id }}" autocomplete="off"
                                placeholder="Kunde, Vertragsnummer, Referenz…" style="width:100%;">
                            <div class="vm-results" id="res-{{ $row->id }}" style="font-size:11.5px;margin-top:4px;"></div>
                            <button type="submit" class="btn btn-primary" style="margin-top:6px;" disabled id="btn-{{ $row->id }}">Zuordnen</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:14px;">{{ $settlements->links() }}</div>
    @endif
</div>

<div class="card" style="max-width:1100px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Verträge ohne Treffer in der Abrechnung</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Diese Verträge sind bei uns erfasst, tauchen aber in der Abrechnung nicht oder nur widersprüchlich auf.
        Sie sind <b>weder storniert noch gelöscht</b> – möglicherweise stehen sie schlicht in der nächsten Abrechnung.
    </div>

    @if($missing->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">✓ Alle erfassten Verträge sind abgeglichen.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Kunde</th><th style="padding:8px;">Vertrag</th>
                <th style="padding:8px;">Referenz-Nr.</th><th style="padding:8px;">Vermittler-ID</th>
                <th style="padding:8px;">Stand</th><th style="padding:8px;">Letzter Abgleich</th>
            </tr></thead>
            <tbody>
            @foreach($missing as $contract)
                <tr style="border-top:1px solid var(--line);">
                    <td style="padding:8px;"><a href="{{ route('admin.customer', $contract->customer_id) }}">{{ $contract->customer?->user?->name ?? '—' }}</a></td>
                    <td style="padding:8px;"><a href="{{ route('admin.contract.edit', $contract->id) }}">{{ $contract->typeIcon() }} {{ $contract->insurer }}</a></td>
                    <td style="padding:8px;">{{ $contract->reference_number ?: '—' }}</td>
                    <td style="padding:8px;">{{ $contract->vermittler_id ?: '—' }}</td>
                    <td style="padding:8px;"><span class="badge badge-{{ $contract->vermittlerStatusBadge() }}">{{ $contract->vermittlerStatusLabel() }}</span></td>
                    <td style="padding:8px;color:var(--ink-soft);">{{ $contract->vermittler_last_imported_at?->lokal()->format('d.m.Y') ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:14px;">{{ $missing->links() }}</div>
    @endif
</div>

<script>
// Sofort-Suche nach dem Vertrag (nie der gesamte Bestand im HTML - gleiche
// Regel wie in den uebrigen Formularen). Treffer werden per textContent
// gebaut: Kunden- und Vertragsnamen sind Fremddaten.
document.querySelectorAll('.vm-search').forEach(function (input) {
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var target = input.dataset.target;
        var box = document.getElementById('res-' + target);
        document.getElementById('cid-' + target).value = '';
        document.getElementById('btn-' + target).disabled = true;
        if (input.value.trim().length < 2) { box.textContent = ''; return; }
        timer = setTimeout(function () {
            fetch('{{ route('admin.vermittler.contract_search') }}?q=' + encodeURIComponent(input.value))
                .then(function (r) { return r.json(); })
                .then(function (items) {
                    box.textContent = '';
                    if (!items.length) { box.textContent = 'Kein Vertrag gefunden.'; return; }
                    items.forEach(function (item) {
                        var row = document.createElement('button');
                        row.type = 'button';
                        row.style.cssText = 'display:block;width:100%;text-align:left;border:1px solid var(--line);background:#fff;border-radius:6px;padding:5px 7px;margin-bottom:4px;cursor:pointer;';
                        row.textContent = item.label + ' · ' + (item.customer || '—')
                            + (item.reference ? ' · Ref. ' + item.reference : '')
                            + (item.vermittler_id ? ' · ID ' + item.vermittler_id : '');
                        row.addEventListener('click', function () {
                            document.getElementById('cid-' + target).value = item.id;
                            document.getElementById('btn-' + target).disabled = false;
                            input.value = item.label + ' · ' + (item.customer || '');
                            box.textContent = '';
                        });
                        box.appendChild(row);
                    });
                });
        }, 250);
    });
});
</script>
@endsection
