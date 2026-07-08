@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Termine</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">Termine</div>
            <div class="page-sub">Kundentermine verwalten</div>
        </div>
        <button onclick="document.getElementById('add-appointment-modal').style.display='flex'" class="btn btn-gold">+ Neuer Termin</button>
    </div>
</div>

<div class="card">
    <div class="card-title" style="margin-bottom:16px;">📅 Kommende Termine</div>
    @forelse($appointments as $a)
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line);">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:48px;height:48px;border-radius:10px;background:#E6F1FB;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <div style="font-size:16px;font-weight:700;color:#185FA5;line-height:1;">{{ $a->starts_at->format('d') }}</div>
                <div style="font-size:10px;color:#185FA5;">{{ $a->starts_at->format('M') }}</div>
            </div>
            <div>
                <div style="font-weight:600;font-size:14px;">{{ $a->title }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">
                    {{ $a->customer?->user?->name }} · {{ $a->starts_at->format('H:i') }} – {{ $a->ends_at->format('H:i') }} · {{ $a->assignedTo?->name }}
                </div>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="badge badge-{{ $a->status === 'scheduled' ? 'open' : ($a->status === 'completed' ? 'active' : 'rejected') }}">
                {{ ['scheduled'=>'Geplant','completed'=>'Erledigt','cancelled'=>'Abgesagt'][$a->status] }}
            </span>
            <form method="POST" action="{{ route('admin.appointments.update', $a->id) }}">
                @csrf @method('PUT')
                <select name="status" onchange="this.form.submit()" style="padding:5px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px;">
                    <option value="scheduled" {{ $a->status==='scheduled'?'selected':'' }}>Geplant</option>
                    <option value="completed" {{ $a->status==='completed'?'selected':'' }}>Erledigt</option>
                    <option value="cancelled" {{ $a->status==='cancelled'?'selected':'' }}>Abgesagt</option>
                </select>
            </form>
        </div>
    </div>
    @empty
    <div style="text-align:center;padding:32px;color:var(--ink-soft);">Keine kommenden Termine.</div>
    @endforelse
</div>

@if($past->count() > 0)
<div class="card" style="margin-top:16px;">
    <div class="card-title" style="margin-bottom:16px;">Vergangene Termine</div>
    @foreach($past as $a)
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line);opacity:.7;">
        <div>
            <div style="font-size:13px;font-weight:600;">{{ $a->title }}</div>
            <div style="font-size:11px;color:var(--ink-soft);">{{ $a->customer?->user?->name }} · {{ $a->starts_at->format('d.m.Y H:i') }}</div>
        </div>
        <span class="badge badge-{{ $a->status === 'completed' ? 'active' : 'closed' }}">
            {{ ['scheduled'=>'Geplant','completed'=>'Erledigt','cancelled'=>'Abgesagt'][$a->status] }}
        </span>
    </div>
    @endforeach
</div>
@endif

{{-- Modal --}}
<div id="add-appointment-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:520px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <div style="font-size:17px;font-weight:700;">Neuer Termin</div>
            <button onclick="document.getElementById('add-appointment-modal').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        </div>
        <form method="POST" action="{{ route('admin.appointments.store') }}">
            @csrf
            <div class="field"><label>Titel *</label><input type="text" name="title" required placeholder="Beratungsgespräch, Vertragsabschluss..."></div>
            <div class="field"><label>Kunde *</label>
                <select name="customer_id" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">Kunde auswählen...</option>
                    @foreach(\App\Models\Customer::with('user')->orderBy('created_at','desc')->take(100)->get() as $c)
                    <option value="{{ $c->id }}">{{ $c->user?->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Beginn *</label><input type="datetime-local" name="starts_at" required></div>
                <div class="field"><label>Ende *</label><input type="datetime-local" name="ends_at" required></div>
            </div>
            <div class="field"><label>Mitarbeiter</label>
                <select name="assigned_to" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    @foreach(\App\Models\User::whereIn('role',['admin','employee'])->get() as $u)
                    <option value="{{ $u->id }}" {{ $u->id === auth()->id() ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Notizen</label><textarea name="notes" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:70px;font-family:inherit;resize:vertical;"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('add-appointment-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Termin speichern</button>
            </div>
        </form>
    </div>
</div>
@endsection
