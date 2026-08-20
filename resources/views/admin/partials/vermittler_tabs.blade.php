{{-- Sub-Navigation der Vermittler-Abrechnung. $active: import | pruefung | bericht --}}
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="{{ route('admin.vermittler.index') }}" class="rep-tab {{ ($active ?? '') === 'import' ? 'rep-tab-active' : '' }}">Import &amp; Übersicht</a>
    <a href="{{ route('admin.vermittler.review') }}" class="rep-tab {{ ($active ?? '') === 'pruefung' ? 'rep-tab-active' : '' }}">Prüfliste</a>
    <a href="{{ route('admin.vermittler.report') }}" class="rep-tab {{ ($active ?? '') === 'bericht' ? 'rep-tab-active' : '' }}">Auswertung</a>
    <a href="{{ route('admin.provisions') }}" class="rep-tab" style="margin-left:auto;">Zum Provisions-Management →</a>
</div>
