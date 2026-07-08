@extends('layouts.admin')
@section('content')
<div class="page-title">Neuer Vertrag</div>
<div class="page-sub">Für: {{ $customer->user?->name }}</div>
<div class="card">
    <form method="POST" action="{{ route('admin.contract.store', $customer->id) }}">
        @csrf
        <div class="grid-2">
            <div class="field"><label>Versicherungsart</label>
                <select name="type" required>
                    <option value="kfz">Kfz-Versicherung</option>
                    <option value="krankenversicherung">Krankenversicherung</option>
                    <option value="internet">Internet & Mobilfunk</option>
                    <option value="strom_gas">Strom & Gas</option>
                    <option value="andere">Andere</option>
                </select>
            </div>
            <div class="field"><label>Versicherer / Anbieter</label><input type="text" name="insurer" required></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Status</label>
                <select name="status" required>
                    <option value="active">Aktiv</option>
                    <option value="pending">In Bearbeitung</option>
                    <option value="cancelled">Gekündigt</option>
                    <option value="expired">Abgelaufen</option>
                </select>
            </div>
            <div class="field"><label>Startdatum</label><input type="date" name="start_date"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Enddatum</label><input type="date" name="end_date"></div>
            <div class="field"><label>Notizen</label><input type="text" name="notes"></div>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Vertrag speichern</button>
            <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
