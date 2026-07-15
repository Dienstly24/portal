@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="page-title">🔄 Kundenänderungen</div>
    <div class="page-sub">Self-Service-Anfragen prüfen, genehmigen oder ablehnen. Daten werden erst nach Genehmigung übernommen.</div>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;">
    @foreach(['pending' => '⏳ Offen', 'approved' => '✓ Genehmigt', 'rejected' => '✗ Abgelehnt'] as $key => $label)
    <a href="{{ route('admin.change_requests', ['status' => $key]) }}" class="btn {{ $status === $key ? 'btn-primary' : 'btn-ghost' }}" style="font-size:13px;">
        {{ $label }} ({{ $counts[$key] }})
    </a>
    @endforeach
</div>

@php
$fieldLabels = [
    'name'=>'Name','relation'=>'Beziehung','birth_date'=>'Geburtsdatum','type'=>'Typ','street'=>'Straße',
    'zip'=>'PLZ','city'=>'Stadt','country'=>'Land','label'=>'Bezeichnung','value'=>'Wert','iban'=>'IBAN',
    'account_holder'=>'Kontoinhaber','insurer'=>'Gesellschaft','contract_number'=>'Vertragsnummer',
    'start_date'=>'Startdatum','end_date'=>'Enddatum','cancellation_date'=>'Kündigungsdatum','notes'=>'Anmerkung',
    'gender'=>'Geschlecht','marital_status'=>'Familienstand','document_name'=>'Dokument','id'=>null,'document_path'=>null,
    'document_disk'=>null,
];
$valueLabels = [
    'ehepartner'=>'Ehepartner','kind'=>'Kind','andere'=>'Andere','main'=>'Hauptadresse','billing'=>'Rechnungsadresse',
    'postal'=>'Postadresse','other'=>'Andere Adresse','privat'=>'Privat','geschaeftlich'=>'Geschäftlich',
    'sonstige'=>'Sonstige','male'=>'Männlich','female'=>'Weiblich','diverse'=>'Divers','email'=>'E-Mail','phone'=>'Telefon',
];
// Vertrags-Sparten (kfz, krankenversicherung, ...) lesbar machen, ohne die
// bestehenden Zuordnungen zu ueberschreiben.
foreach (\App\Models\Contract::TYPES as $ck => $cfg) { $valueLabels[$ck] ??= $cfg['label']; }
$fmt = fn($v) => $valueLabels[$v] ?? $v;
@endphp

@forelse($requests as $r)
<div class="card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:280px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <span style="font-size:15px;font-weight:700;">{{ $r->typeLabel() }}</span>
                @if($r->status === 'pending')<span class="badge badge-pending">Offen</span>
                @elseif($r->status === 'approved')<span class="badge badge-active">Genehmigt</span>
                @else<span class="badge" style="background:#F9E3E3;color:#A32D2D;">Abgelehnt</span>@endif
            </div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:12px;">
                Kunde: <a href="{{ route('admin.customer', $r->customer_id) }}" style="color:var(--petrol);font-weight:600;">{{ $r->customer?->user?->name ?? '—' }}</a>
                · Eingereicht: {{ $r->created_at->format('d.m.Y H:i') }}
                @if($r->reviewer) · Bearbeitet von {{ $r->reviewer->name }} am {{ $r->reviewed_at?->format('d.m.Y H:i') }} @endif
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:12px 14px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">Alt</div>
                    @if($r->old_data)
                        @foreach($r->old_data as $k => $v)
                            @if(($fieldLabels[$k] ?? '') !== null && !is_null($v) && $v !== '')
                            <div style="font-size:13px;padding:2px 0;"><span style="color:var(--ink-soft);">{{ $fieldLabels[$k] ?? $k }}:</span> {{ $fmt($v) }}</div>
                            @endif
                        @endforeach
                    @else
                    <div style="font-size:13px;color:var(--ink-soft);">— Neuanlage —</div>
                    @endif
                </div>
                <div style="background:#F0F7F3;border:1px solid #CDE7D8;border-radius:8px;padding:12px 14px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#3B7A57;margin-bottom:8px;">Neu</div>
                    @foreach($r->new_data as $k => $v)
                        @php $changed = !$r->old_data || ($r->old_data[$k] ?? null) != $v; @endphp
                        @if(($fieldLabels[$k] ?? '') !== null && !is_null($v) && $v !== '')
                        <div style="font-size:13px;padding:2px 0;{{ $changed ? 'background:#FFF3D6;border-radius:4px;padding-left:6px;margin:1px -6px 1px 0;' : '' }}"><span style="color:var(--ink-soft);">{{ $fieldLabels[$k] ?? $k }}:</span> <b>{{ $fmt($v) }}</b>@if($changed)<span style="color:#B5651D;font-size:11px;"> · geändert</span>@endif</div>
                        @endif
                    @endforeach
                    @if(!empty($r->new_data['document_path']))
                    <div style="font-size:13px;padding:4px 0;">📎 <a href="{{ route('admin.change_requests.document', $r->id) }}" style="color:var(--petrol);">{{ $r->new_data['document_name'] ?? 'Dokument öffnen' }}</a></div>
                    @endif
                </div>
            </div>
            @if($r->status !== 'pending' && $r->notes)
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:10px;">📝 Notiz: {{ $r->notes }}</div>
            @endif
        </div>

        @if($r->status === 'pending')
        <form method="POST" action="{{ route('admin.change_requests.action', $r->id) }}" style="min-width:220px;">
            @csrf
            <div class="field" style="margin-bottom:10px;">
                <label style="font-size:12px;">Notiz (optional, bei Ablehnung sichtbar für den Kunden)</label>
                <textarea name="notes" maxlength="1000" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-height:60px;font-family:inherit;resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" name="action" value="approve" class="btn btn-primary" style="flex:1;background:#3B7A57;" onclick="return confirm('Anfrage genehmigen? Die Kundendaten werden sofort aktualisiert.');">✓ Genehmigen</button>
                <button type="submit" name="action" value="reject" class="btn btn-ghost" style="flex:1;color:#A32D2D;border-color:#A32D2D;" onclick="return confirm('Anfrage ablehnen?');">✗ Ablehnen</button>
            </div>
        </form>
        @endif
    </div>
</div>
@empty
<div class="card"><p style="color:var(--ink-soft);font-size:14px;">Keine {{ ['pending'=>'offenen','approved'=>'genehmigten','rejected'=>'abgelehnten'][$status] }} Anfragen.</p></div>
@endforelse

{{ $requests->links() }}
@endsection
