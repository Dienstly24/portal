@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">👨‍👩‍👦 Meine Familie</div>
        <div class="page-sub" style="margin-bottom:0;">Familienmitglieder hinzufügen oder Änderungen beantragen – jede Angabe wird von unserem Team geprüft.</div>
    </div>
    <button onclick="document.getElementById('add-family-modal').style.display='flex'" class="btn btn-gold">+ Familienmitglied hinzufügen</button>
</div>

@php
$relationIcons = ['hauptversicherter'=>'👨','ehepartner'=>'👩','kind'=>'👦','andere'=>'👤'];
$relationLabels = ['hauptversicherter'=>'Hauptversicherter','ehepartner'=>'Ehepartner','kind'=>'Kind','andere'=>'Weitere Person'];
$pendingCreates = $requests->where('status','pending')->filter(fn($r)=>empty($r->new_data['id']));
$pendingChangeIds = $requests->where('status','pending')->pluck('new_data.id')->filter()->all();
$rejected = $requests->where('status','rejected');
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:24px;">
    {{-- Hauptversicherter --}}
    <div class="card" style="margin-bottom:0;text-align:center;">
        <div style="font-size:40px;margin-bottom:8px;">👨</div>
        <div style="font-weight:700;font-size:15px;">{{ auth()->user()->name }}</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin:4px 0 10px;">{{ $customer->birth_date ? \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y') : '—' }}</div>
        <span class="badge badge-open">Hauptversicherter</span>
        <div style="margin-top:10px;"><span class="badge badge-active">Aktiv</span></div>
    </div>

    {{-- Bestätigte Familienmitglieder --}}
    @foreach($members as $m)
    <div class="card" style="margin-bottom:0;text-align:center;">
        <div style="font-size:40px;margin-bottom:8px;">{{ $relationIcons[$m->relation] ?? '👤' }}</div>
        <div style="font-weight:700;font-size:15px;">{{ $m->name }}</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin:4px 0 10px;">{{ $m->birth_date ? \Carbon\Carbon::parse($m->birth_date)->format('d.m.Y') : '—' }}</div>
        <span class="badge badge-open">{{ $relationLabels[$m->relation] ?? ucfirst($m->relation) }}</span>
        <div style="margin-top:10px;">
            @if(in_array($m->id, $pendingChangeIds))
            <span class="badge badge-pending">Prüfung ausstehend</span>
            @else
            <span class="badge badge-active">Aktiv</span>
            @endif
        </div>
        <button onclick='openFamilyChange(@json($m->only(["id","name","relation","birth_date"])))' class="btn btn-ghost" style="margin-top:12px;font-size:12.5px;padding:7px 14px;">✏️ Änderung beantragen</button>
    </div>
    @endforeach

    {{-- Beantragte, noch ungeprüfte Mitglieder --}}
    @foreach($pendingCreates as $r)
    <div class="card" style="margin-bottom:0;text-align:center;border-style:dashed;background:#FFFDF7;">
        <div style="font-size:40px;margin-bottom:8px;">{{ $relationIcons[$r->new_data['relation'] ?? 'andere'] ?? '👤' }}</div>
        <div style="font-weight:700;font-size:15px;">{{ $r->new_data['name'] ?? '—' }}</div>
        <div style="font-size:12.5px;color:var(--ink-soft);margin:4px 0 10px;">{{ !empty($r->new_data['birth_date']) ? \Carbon\Carbon::parse($r->new_data['birth_date'])->format('d.m.Y') : '—' }}</div>
        <span class="badge badge-open">{{ $relationLabels[$r->new_data['relation'] ?? 'andere'] ?? 'Person' }}</span>
        <div style="margin-top:10px;"><span class="badge badge-pending">Prüfung ausstehend</span></div>
    </div>
    @endforeach
</div>

@if($rejected->count())
<div class="card">
    <div class="card-title">Abgelehnte Anfragen</div>
    @foreach($rejected as $r)
    <div class="item-row">
        <div>
            <div style="font-size:14px;font-weight:600;">{{ $r->new_data['name'] ?? 'Familienmitglied' }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">{{ $r->created_at->format('d.m.Y') }} @if($r->notes) · Grund: {{ $r->notes }} @endif</div>
        </div>
        <span class="badge" style="background:#F9E3E3;color:#A32D2D;">Abgelehnt</span>
    </div>
    @endforeach
</div>
@endif

{{-- Modal: Hinzufügen --}}
<div id="add-family-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:440px;position:relative;">
        <button onclick="document.getElementById('add-family-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Familienmitglied hinzufügen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">Die Angaben werden erst nach Prüfung durch unser Team übernommen.</p>
        <form method="POST" action="{{ route('portal.family.store') }}">
            @csrf
            <div class="field"><label>Name *</label><input type="text" name="name" required maxlength="255"></div>
            <div class="grid-2">
                <div class="field"><label>Beziehung *</label>
                    <select name="relation" required>
                        <option value="ehepartner">Ehepartner</option>
                        <option value="kind">Kind</option>
                        <option value="andere">Weitere Person</option>
                    </select>
                </div>
                <div class="field"><label>Geburtsdatum</label><input type="date" name="birth_date" max="{{ now()->toDateString() }}"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Zur Prüfung einreichen</button>
        </form>
    </div>
</div>

{{-- Modal: Änderung beantragen --}}
<div id="change-family-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:440px;position:relative;">
        <button onclick="document.getElementById('change-family-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Änderung beantragen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">Die Änderung wird erst nach Prüfung wirksam.</p>
        <form method="POST" id="change-family-form" action="">
            @csrf
            <div class="field"><label>Name *</label><input type="text" name="name" id="cf-name" required maxlength="255"></div>
            <div class="grid-2">
                <div class="field"><label>Beziehung *</label>
                    <select name="relation" id="cf-relation" required>
                        <option value="ehepartner">Ehepartner</option>
                        <option value="kind">Kind</option>
                        <option value="andere">Weitere Person</option>
                    </select>
                </div>
                <div class="field"><label>Geburtsdatum</label><input type="date" name="birth_date" id="cf-birth" max="{{ now()->toDateString() }}"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Änderung einreichen</button>
        </form>
    </div>
</div>

<script>
function openFamilyChange(m) {
    document.getElementById('change-family-form').action = '{{ url('portal/family') }}/' + m.id + '/change';
    document.getElementById('cf-name').value = m.name || '';
    document.getElementById('cf-relation').value = ['ehepartner','kind','andere'].includes(m.relation) ? m.relation : 'andere';
    document.getElementById('cf-birth').value = m.birth_date ? m.birth_date.substring(0,10) : '';
    document.getElementById('change-family-modal').style.display = 'flex';
}
</script>
@endsection
