{{-- Sub-Navigation der internen Provisionen. $active: liste | import | rechnung | protokoll --}}
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="{{ route('admin.commissions_internal.index') }}" class="rep-tab {{ ($active ?? '') === 'liste' ? 'rep-tab-active' : '' }}">Provisionen</a>
    <a href="{{ route('admin.commissions_internal.import') }}" class="rep-tab {{ ($active ?? '') === 'import' ? 'rep-tab-active' : '' }}">CSV / Excel importieren</a>
    <a href="{{ route('admin.commissions_internal.invoice') }}" class="rep-tab {{ ($active ?? '') === 'rechnung' ? 'rep-tab-active' : '' }}">Rechnungsabgleich</a>
    <a href="{{ route('admin.commissions_internal.audit') }}" class="rep-tab {{ ($active ?? '') === 'protokoll' ? 'rep-tab-active' : '' }}">Protokoll</a>
    <a href="{{ route('admin.vermittler.index') }}" class="rep-tab" style="margin-left:auto;">Vermittler-Abrechnung →</a>
</div>
