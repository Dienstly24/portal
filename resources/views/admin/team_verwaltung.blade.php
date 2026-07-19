@extends('layouts.admin')
@section('content')
<div class="page-title">Team-Verwaltung</div>
<div class="page-sub">Bestandsuebertragung und Vertretungen verwalten.</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <div class="card">
        <div class="card-title">Bestandsuebertragung</div>
        <p style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">Alle Kunden eines Mitarbeiters dauerhaft an einen anderen uebertragen (z.B. bei Ausscheiden).</p>
        <form method="POST" action="{{ route('admin.team.transfer') }}" onsubmit="return confirm('Wirklich ALLE Kunden uebertragen? Der bisherige Betreuer verliert den Zugriff.');">
            @csrf
            <div class="field">
                <label>Von Mitarbeiter</label>
                <select name="from_employee" required>
                    <option value="">&mdash; waehlen &mdash;</option>
                    @foreach($employees as $e)
                    <option value="{{ $e->id }}">{{ $e->name }} ({{ $e->assigned_customers_count }} Kunden){{ !$e->is_active ? ' - deaktiviert' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>An Mitarbeiter</label>
                <select name="to_employee" required>
                    <option value="">&mdash; waehlen &mdash;</option>
                    @foreach($employees->where('is_active', true) as $e)
                    <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Grund (Pflicht)</label><input type="text" name="reason" required placeholder="z.B. Mitarbeiter ausgeschieden"></div>
            <button type="submit" class="btn btn-primary">Bestand uebertragen</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title">Vertretung (Urlaub / Krankheit)</div>
        <p style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">Der Vertreter sieht die Kunden des Abwesenden nur im gewaehlten Zeitraum. Endet automatisch.</p>
        <form method="POST" action="{{ route('admin.team.substitution.store') }}">
            @csrf
            <div class="field">
                <label>Abwesender Mitarbeiter</label>
                <select name="absent_user_id" required>
                    <option value="">&mdash; waehlen &mdash;</option>
                    @foreach($employees as $e)
                    <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Vertreten durch</label>
                <select name="substitute_user_id" required>
                    <option value="">&mdash; waehlen &mdash;</option>
                    @foreach($employees->where('is_active', true) as $e)
                    <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="field"><label>Von</label><input type="date" name="from_date" required></div>
                <div class="field"><label>Bis</label><input type="date" name="to_date" required></div>
            </div>
            <div class="field"><label>Grund (optional)</label><input type="text" name="reason" placeholder="z.B. Urlaub"></div>
            <button type="submit" class="btn btn-primary">Vertretung einrichten</button>
        </form>

        @if($substitutions->count())
        <div style="margin-top:20px;border-top:1px solid var(--line);padding-top:14px;">
            <div style="font-size:12px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;margin-bottom:10px;">Aktuelle &amp; letzte Vertretungen</div>
            @foreach($substitutions as $sub)
            @php $isActive = $sub->from_date->lte(now()) && $sub->to_date->gte(now()); @endphp
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px;">
                <div>
                    <strong>{{ $sub->absentUser?->name }}</strong> &rarr; {{ $sub->substituteUser?->name }}<br>
                    <span style="color:var(--ink-soft);font-size:12px;">{{ $sub->from_date->format('d.m.Y') }} &ndash; {{ $sub->to_date->format('d.m.Y') }}{{ $sub->reason ? ' - ' . $sub->reason : '' }}</span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    @if($isActive)<span style="background:#D9F4E6;color:#17A65B;border-radius:10px;padding:2px 10px;font-size:11px;">Aktiv</span>@endif
                    <form method="POST" action="{{ route('admin.team.substitution.destroy', $sub->id) }}" onsubmit="return confirm('Vertretung beenden?');" style="margin:0;">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;color:#A32D2D;cursor:pointer;font-size:13px;">&#10005;</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
