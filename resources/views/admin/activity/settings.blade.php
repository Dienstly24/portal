@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.activity.index') }}">Aktivität &amp; Arbeitszeiten</a><span class="breadcrumb-sep">›</span><span>Einstellungen</span></div>
    <div class="page-title">Einstellungen: Aktivitätserfassung</div>
    <div class="page-sub">Schwellwerte und Punkte-Gewichte — Änderungen wirken sofort, ohne Code-Anpassung. Nur für Administratoren.</div>
</div>

@php
    $categoryLabels = [
        'create' => '➕ Anlegen',
        'update' => '✏️ Bearbeiten',
        'upload' => '📎 Datei-Uploads',
        'delete' => '🗑 Löschen',
        'approve' => '✅ Freigaben & Entscheidungen',
        'communication' => '💬 Kommunikation',
        'other' => '⚙️ Sonstiges',
    ];
    $order = array_keys($categoryLabels);
@endphp

@if($errors->any())
<div class="alert alert-error">
    {{ $errors->first() }}
</div>
@endif

<form method="POST" action="{{ route('admin.activity.settings.update') }}">
@csrf @method('PUT')

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1000px;">

    <div class="card" style="grid-column:1 / -1;">
        <div class="card-title" style="margin-bottom:8px;">⏱ Zeiterfassung</div>
        <div style="font-size:13px;color:var(--ink-soft);margin-bottom:20px;">
            Aktive Arbeitszeit entsteht nur durch produktive Aktionen (Anlegen, Bearbeiten, Hochladen …).
            Zwischen zwei produktiven Aktionen wird höchstens der Leerlauf-Schwellwert gutgeschrieben —
            längere Pausen zählen nicht als Arbeitszeit.
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <div class="field">
                <label>Leerlauf-Schwellwert (Minuten)</label>
                <input type="number" name="idle_threshold" min="1" max="240" value="{{ old('idle_threshold', $idleThreshold) }}">
                <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">Max. anrechenbare Lücke zwischen zwei produktiven Aktionen. Standard: 5</div>
            </div>
            <div class="field">
                <label>Sitzungs-Timeout (Minuten)</label>
                <input type="number" name="session_timeout" min="5" max="480" value="{{ old('session_timeout', $sessionTimeout) }}">
                <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">Ohne jeden Request gilt die Sitzung danach als beendet. Standard: 30</div>
            </div>
        </div>
    </div>

    @foreach($order as $category)
        @if(isset($actions[$category]))
        <div class="card">
            <div class="card-title" style="margin-bottom:16px;">{{ $categoryLabels[$category] }}</div>
            @foreach($actions[$category]->sortBy('label') as $action)
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid var(--line);">
                <div>
                    <div style="font-size:13px;font-weight:600;">{{ $action->label }}</div>
                    <div style="font-size:11px;color:var(--ink-soft);">Standard: {{ $action->default }} {{ $action->default === 1 ? 'Punkt' : 'Punkte' }}</div>
                </div>
                <input type="number" name="points[{{ $action->key }}]" min="0" max="100"
                       value="{{ old('points.' . $action->key, $action->current) }}"
                       style="width:80px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;text-align:right;background:#F4F5F7;">
            </div>
            @endforeach
        </div>
        @endif
    @endforeach

</div>

<div style="margin-top:24px;display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
    <a href="{{ route('admin.activity.index') }}" class="btn btn-ghost">Abbrechen</a>
</div>
</form>
@endsection
