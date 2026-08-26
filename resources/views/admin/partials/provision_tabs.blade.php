{{-- Sub-Navigation des Provisions-Managements (nur admin/manager).
     $active: liste | saetze | bericht | dashboard --}}
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="{{ route('admin.commissions') }}" class="rep-tab">Gutschriften (Eingang)</a>
    <a href="{{ route('admin.provisions') }}" class="rep-tab {{ $active === 'liste' ? 'rep-tab-active' : '' }}">Provisionen (Ausgang)</a>
    <a href="{{ route('admin.provisions.rates') }}" class="rep-tab {{ $active === 'saetze' ? 'rep-tab-active' : '' }}">Sätze</a>
    <a href="{{ route('admin.provisions.report') }}" class="rep-tab {{ $active === 'bericht' ? 'rep-tab-active' : '' }}">Monatsbericht</a>
    <a href="{{ route('admin.provisions.dashboard') }}" class="rep-tab {{ $active === 'dashboard' ? 'rep-tab-active' : '' }}">Dashboard</a>
    <a href="{{ route('admin.vermittler.index') }}" class="rep-tab">Vermittler-Abrechnung</a>
    @can('provisionen-verwalten')
    <a href="{{ route('admin.commissions_internal.index') }}" class="rep-tab">Interne Provisionen</a>
    @endcan
    <a href="{{ route('admin.reports.neukunden') }}" class="rep-tab" style="margin-left:auto;">Zum Neukunden-Bericht →</a>
</div>
