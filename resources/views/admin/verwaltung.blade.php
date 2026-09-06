@extends('layouts.admin')
@section('content')
{{-- Die Einstellungen-Ansicht fuer alle, die NICHT admin sind.

     `admin.settings` ist role:admin - ein Manager, Support oder Mitarbeiter
     bekaeme dort 403. Damit der eine Punkt aus der Seitenleiste trotzdem nie
     in eine verbotene Seite fuehrt, zeigt diese Seite dieselben Untermenues
     ohne das Konfigurations-Formular. Welche Punkte darin stehen, entscheidet
     unveraendert AdminNavigation anhand der Rolle. --}}
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Einstellungen</span></div>
    <div class="page-title">Einstellungen</div>
    <div class="page-sub">Vertrieb, Marketing und Verwaltung</div>
</div>

@include('admin.partials.settings_nav')

@if(\App\Support\Navigation\AdminNavigation::for(auth()->user())->settingsGroups() === [])
<div class="card" style="max-width:900px;">
    <div style="font-size:13.5px;color:var(--ink-soft);">
        Für Ihre Rolle sind hier keine Bereiche freigeschaltet.
    </div>
</div>
@endif
@endsection
