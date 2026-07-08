@extends('layouts.admin')
@section('content')

@php
$typeConfig = [
    'kfz'              => ['icon'=>'🚗','label'=>'KFZ','color'=>'#185FA5','bg'=>'#E6F1FB'],
    'krankenversicherung' => ['icon'=>'🏥','label'=>'Kranken','color'=>'#3B7A57','bg'=>'#E4F0E7'],
    'haftpflicht'      => ['icon'=>'🛡️','label'=>'Haftpflicht','color'=>'#6D28D9','bg'=>'#F0E6FB'],
    'rechtsschutz'     => ['icon'=>'⚖️','label'=>'Rechtsschutz','color'=>'#92400E','bg'=>'#FEF3C7'],
    'hausrat'          => ['icon'=>'🏠','label'=>'Hausrat','color'=>'#3B7A57','bg'=>'#E4F0E7'],
    'escooter'         => ['icon'=>'🛴','label'=>'E-Scooter','color'=>'#185FA5','bg'=>'#E6F1FB'],
    'leben'            => ['icon'=>'❤️','label'=>'Leben','color'=>'#993556','bg'=>'#FBEAF0'],
    'unfall'           => ['icon'=>'🚑','label'=>'Unfall','color'=>'#A32D2D','bg'=>'#F9E3E3'],
    'internet'         => ['icon'=>'📶','label'=>'Internet','color'=>'#6D28D9','bg'=>'#EDE9FE'],
    'strom_gas'        => ['icon'=>'⚡','label'=>'Strom/Gas','color'=>'#92400E','bg'=>'#FEF3C7'],
    'andere'           => ['icon'=>'📋','label'=>'Andere','color'=>'#5F5E5A','bg'=>'#F1EFE8'],
];
$activeTypes = $customer->contracts->where('status','active')->pluck('type')->unique()->toArray();
@endphp

<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <span>{{ $customer->user?->name }}</span>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">{{ $customer->user?->name }}</div>
            <div style="font-size:14px;color:var(--ink-soft);">{{ $customer->customer_number }} · {{ $customer->user?->email }}</div>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="{{ route('admin.customer.edit', $customer->id) }}" class="btn btn-ghost">✏️ Bearbeiten</a>
            <button onclick="document.getElementById('add-contract-modal').style.display='flex'" class="btn btn-gold">+ Vertrag hinzufügen</button>
        </div>
    </div>
</div>

{{-- Vertragsstruktur Icons --}}
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:16px;">Vertragsstruktur</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        @foreach($typeConfig as $key => $cfg)
        @php $isActive = in_array($key, $activeTypes); @endphp
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;"
            onclick="document.getElementById('filter-type').value='{{ $key }}';filterContracts()">
            <div style="width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;
                background:{{ $isActive ? $cfg['bg'] : '#F1EFE8' }};
                border:2px solid {{ $isActive ? $cfg['color'] : '#E4E0D4' }};
                opacity:{{ $isActive ? '1' : '0.4' }};
                transition:.2s;">
                {{ $cfg['icon'] }}
            </div>
            <span style="font-size:11px;color:{{ $isActive ? $cfg['color'] : 'var(--ink-soft)' }};font-weight:{{ $isActive ? '600' : '400' }};">
                {{ $cfg['label'] }}
            </span>
        </div>
        @endforeach
    </div>
</div>

<div class="grid-2">

{{-- Persönliche Daten --}}
<div class="card">
    <div class="card-title" style="margin-bottom:16px;">Persönliche Daten</div>
    <table style="width:100%;font-size:14px;">
        <tr><td style="color:var(--ink-soft);padding:8px 0;width:130px;">Telefon</td><td>{{ $customer->phone ?? '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Adresse</td><td style="border-top:1px solid var(--line);">{{ $customer->address ?? '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">IBAN</td><td style="border-top:1px solid var(--line);">{{ $customer->iban ? '••••' . substr($customer->iban,-4) : '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Geburtsdatum</td><td style="border-top:1px solid var(--line);">{{ $customer->birth_date ? \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y') : '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Familienstand</td><td style="border-top:1px solid var(--line);">{{ $customer->marital_status ?? '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Sprache</td><td style="border-top:1px solid var(--line);">{{ strtoupper($customer->preferred_lang) }}</td></tr>
        @if($customer->company_name)
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Firma</td><td style="border-top:1px solid var(--line);">{{ $customer->company_name }} @if($customer->company_type)<span style="font-size:12px;color:var(--ink-soft);">({{ $customer->company_type }})</span>@endif</td></tr>
        @endif
    </table>
</div>

{{-- Notizen & Aufgaben --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="card-title">Notizen & Aufgaben</div>
        <button onclick="document.getElementById('add-note-modal').style.display='flex'" style="width:28px;height:28px;border-radius:50%;border:none;background:var(--petrol);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
    </div>
    @php $notes = \App\Models\CustomerNote::where('customer_id',$customer->id)->with('createdBy')->latest()->get(); @endphp
    @forelse($notes as $n)
    <div style="padding:10px 0;border-bottom:1px solid var(--line);display:flex;align-items:flex-start;gap:10px;">
        <span style="font-size:16px;">{{ $n->type === 'task' ? '✅' : '📝' }}</span>
        <div style="flex:1;">
            <div style="font-size:13px;line-height:1.5;">{{ $n->note }}</div>
            <div style="font-size:11px;color:var(--ink-soft);margin-top:3px;">
                {{ $n->createdBy?->name }} · {{ $n->created_at->format('d.m.Y H:i') }}
                @if($n->due_date) · Fällig: {{ \Carbon\Carbon::parse($n->due_date)->format('d.m.Y') }} @endif
            </div>
        </div>
        @if($n->type === 'task')
        <form method="POST" action="{{ route('admin.customer.note.done', $n->id) }}">
            @csrf @method('PUT')
            <button type="submit" style="border:none;background:none;cursor:pointer;font-size:14px;color:{{ $n->is_done ? '#3B7A57' : 'var(--ink-soft)' }};">
                {{ $n->is_done ? '✓' : '○' }}
            </button>
        </form>
        @endif
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Notizen.</p>
    @endforelse
</div>

</div>

{{-- Verträge --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="card-title">Verträge</div>
        <select id="filter-type" onchange="filterContracts()" style="padding:6px 12px;border:1px solid var(--line);border-radius:6px;font-size:13px;">
            <option value="">Alle Typen</option>
            @foreach($typeConfig as $key => $cfg)
            <option value="{{ $key }}">{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
            @endforeach
        </select>
    </div>
    <table id="contracts-table" style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#FAFAF8;">
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Typ</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Versicherer</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Vertragsnr.</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Beginn</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Status</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Hinzugefügt von</th>
        </tr></thead>
        <tbody>
        @forelse($customer->contracts as $c)
        @php $cfg = $typeConfig[$c->type] ?? $typeConfig['andere']; @endphp
        <tr class="contract-row" data-type="{{ $c->type }}" style="border-bottom:1px solid var(--line);">
            <td style="padding:12px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;border-radius:8px;background:{{ $cfg['bg'] }};display:flex;align-items:center;justify-content:center;font-size:16px;">{{ $cfg['icon'] }}</span>
                    <span style="font-weight:600;">{{ $cfg['label'] }}</span>
                </div>
            </td>
            <td style="padding:12px;">{{ $c->insurer }}</td>
            <td style="padding:12px;font-family:monospace;font-size:13px;">{{ $c->contract_number }}</td>
            <td style="padding:12px;color:var(--ink-soft);font-size:13px;">{{ $c->start_date ? \Carbon\Carbon::parse($c->start_date)->format('d.m.Y') : '—' }}</td>
            <td style="padding:12px;">
                <span class="badge badge-{{ $c->status === 'active' ? 'active' : ($c->status === 'cancelled' ? 'rejected' : 'pending') }}">
                    {{ ['active'=>'Aktiv','pending'=>'In Bearb.','cancelled'=>'Gekündigt','expired'=>'Abgelaufen'][$c->status] ?? $c->status }}
                </span>
            </td>
            <td style="padding:12px;font-size:12px;color:var(--ink-soft);">{{ $c->added_by ?? 'System' }}</td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Verträge.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Dokumente --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="card-title">Dokumente</div>
        <button onclick="document.getElementById('add-doc-modal').style.display='flex'" style="width:28px;height:28px;border-radius:50%;border:none;background:var(--petrol);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
    </div>
    @php $docs = $customer->documents; @endphp
    @forelse($docs as $d)
    @php $dotColor = ['red'=>'#E24B4A','yellow'=>'#F0A500','green'=>'#3B7A57'][$d->color ?? 'green']; @endphp
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);">
        <div style="width:10px;height:10px;border-radius:50%;background:{{ $dotColor }};flex:none;"></div>
        <div style="flex:1;">
            <div style="font-size:14px;font-weight:600;">{{ $d->file_name }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">{{ ucfirst($d->category) }} · {{ $d->created_at->format('d.m.Y') }}</div>
        </div>
        <a href="{{ Storage::url($d->file_path) }}" target="_blank" class="btn btn-ghost btn-sm">⬇</a>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Keine Dokumente.</p>
    @endforelse
</div>

{{-- Anträge --}}
<div class="card">
    <div class="card-title" style="margin-bottom:16px;">Anträge</div>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#FAFAF8;">
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Betreff</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Typ</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Datum</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Status</th>
            <th style="border-bottom:1px solid var(--line);"></th>
        </tr></thead>
        <tbody>
        @forelse($customer->tickets as $t)
        <tr>
            <td style="padding:12px;font-weight:600;">{{ $t->subject }}</td>
            <td style="padding:12px;color:var(--ink-soft);">{{ ucfirst(str_replace('_',' ',$t->type)) }}</td>
            <td style="padding:12px;color:var(--ink-soft);font-size:13px;">{{ $t->created_at->format('d.m.Y') }}</td>
            <td style="padding:12px;"><span class="badge badge-{{ $t->status === 'open' ? 'open' : 'closed' }}">{{ ['open'=>'Offen','in_progress'=>'In Bearb.','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span></td>
            <td style="padding:12px;"><a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        @empty
        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Anträge.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Add Contract Modal --}}
<div id="add-contract-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:560px;position:relative;max-height:90vh;overflow-y:auto;">
        <button onclick="document.getElementById('add-contract-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Vertrag hinzufügen</div>
        <form method="POST" action="{{ route('admin.contract.store', $customer->id) }}">
            @csrf
            <div class="field"><label>Vertragsart *</label>
                <select name="type" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    @foreach($typeConfig as $key => $cfg)
                    <option value="{{ $key }}">{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Versicherer / Anbieter *</label><input type="text" name="insurer" required placeholder="z.B. Allianz, HUK..."></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Status</label>
                    <select name="status" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="active">Aktiv</option>
                        <option value="pending">In Bearbeitung</option>
                        <option value="cancelled">Gekündigt</option>
                        <option value="expired">Abgelaufen</option>
                    </select>
                </div>
                <div class="field"><label>Vertragsnummer</label><input type="text" name="vsnr" placeholder="Optional"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Beginn</label><input type="date" name="start_date"></div>
                <div class="field"><label>Ablauf</label><input type="date" name="end_date"></div>
            </div>
            <div class="field"><label>Notizen</label><textarea name="notes" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:70px;font-family:inherit;resize:vertical;"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('add-contract-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

{{-- Add Note Modal --}}
<div id="add-note-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="document.getElementById('add-note-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Notiz / Aufgabe hinzufügen</div>
        <form method="POST" action="{{ route('admin.customer.note.store', $customer->id) }}">
            @csrf
            <div class="field"><label>Typ</label>
                <div style="display:flex;gap:12px;margin-bottom:4px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="type" value="note" checked style="width:auto;"> 📝 Notiz</label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="type" value="task" style="width:auto;"> ✅ Aufgabe</label>
                </div>
            </div>
            <div class="field"><label>Text *</label><textarea name="note" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:90px;font-family:inherit;resize:vertical;" placeholder="Notiz oder Aufgabenbeschreibung..."></textarea></div>
            <div class="field"><label>Fälligkeitsdatum (optional)</label><input type="date" name="due_date"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('add-note-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

{{-- Add Document Modal --}}
<div id="add-doc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="document.getElementById('add-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Dokument hochladen</div>
        <form method="POST" action="{{ route('admin.customer.document.store', $customer->id) }}" enctype="multipart/form-data">
            @csrf
            <div class="field"><label>Datei *</label><input type="file" name="document" required></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Kategorie</label>
                    <select name="category" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="contract">Vertrag</option>
                        <option value="invoice">Rechnung</option>
                        <option value="correspondence">Schreiben</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>
                <div class="field"><label>Priorität</label>
                    <select name="color" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="green">🟢 Normal</option>
                        <option value="yellow">🟡 Wichtig</option>
                        <option value="red">🔴 Dringend</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('add-doc-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hochladen</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterContracts() {
    const type = document.getElementById('filter-type').value;
    document.querySelectorAll('.contract-row').forEach(row => {
        row.style.display = !type || row.dataset.type === type ? '' : 'none';
    });
}
</script>

<div style="display:flex;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid var(--line);">
    <a href="{{ route('admin.customer.merge', $customer->id) }}" class="btn btn-ghost">🔀 Mit Duplikat zusammenführen</a>
    <form method="POST" action="{{ route('admin.customers.delete', $customer->id) }}" onsubmit="return confirm('Kunde {{ $customer->user?->name }} wirklich ENDGÜLTIG löschen? Alle Verträge, Tickets und Dokumente gehen verloren!') && confirm('Wirklich sicher? Diese Aktion kann NICHT rückgängig gemacht werden.');" style="margin:0;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost" style="color:#A32D2D;border-color:#A32D2D;">🗑 Kunde löschen</button>
    </form>
</div>

@endsection
