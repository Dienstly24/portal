{{-- Hauptnavigation des PROVISIONSMANAGEMENTS (Betreiber-Auftrag 02.09.2026).

     Sie liegt bewusst weiterhin HIER und wird von beiden Controllern
     eingebunden: eine zweite Navigationsdatei liefe beim naechsten neuen
     Punkt auseinander, und dann haetten Import und Auswertung verschiedene
     Menues. $active: dashboard|importe|abrechnungen|buchungen|vertraege|
     fehlende|unklar|auswertungen|einstellungen (dazu import|rechnung|protokoll). --}}
<div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="{{ route('admin.provisionsmanagement.dashboard') }}" class="rep-tab {{ ($active ?? '') === 'dashboard' ? 'rep-tab-active' : '' }}">Dashboard</a>
    <a href="{{ route('admin.provisionsmanagement.imports') }}" class="rep-tab {{ in_array(($active ?? ''), ['importe','import'], true) ? 'rep-tab-active' : '' }}">Importe</a>
    <a href="{{ route('admin.provisionsmanagement.statements') }}" class="rep-tab {{ ($active ?? '') === 'abrechnungen' ? 'rep-tab-active' : '' }}">Abrechnungen</a>
    <a href="{{ route('admin.commissions_internal.index') }}" class="rep-tab {{ in_array(($active ?? ''), ['liste','buchungen'], true) ? 'rep-tab-active' : '' }}">Provisionsbuchungen</a>
    <a href="{{ route('admin.provisionsmanagement.contracts') }}" class="rep-tab {{ ($active ?? '') === 'vertraege' ? 'rep-tab-active' : '' }}">Verträge</a>
    <a href="{{ route('admin.provisionsmanagement.missing') }}" class="rep-tab {{ ($active ?? '') === 'fehlende' ? 'rep-tab-active' : '' }}">Fehlende Provisionen</a>
    <a href="{{ route('admin.provisionsmanagement.unclear') }}" class="rep-tab {{ ($active ?? '') === 'unklar' ? 'rep-tab-active' : '' }}">Unklare Zuordnungen</a>
    <a href="{{ route('admin.provisionsmanagement.analytics') }}" class="rep-tab {{ ($active ?? '') === 'auswertungen' ? 'rep-tab-active' : '' }}">Auswertungen</a>
    <a href="{{ route('admin.provisionsmanagement.settings') }}" class="rep-tab {{ ($active ?? '') === 'einstellungen' ? 'rep-tab-active' : '' }}">Einstellungen</a>
</div>
<div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;font-size:12px;">
    <a href="{{ route('admin.commissions_internal.import') }}" class="rep-tab {{ ($active ?? '') === 'import' ? 'rep-tab-active' : '' }}">＋ Neue Abrechnung importieren</a>
    <a href="{{ route('admin.commissions_internal.invoice') }}" class="rep-tab {{ ($active ?? '') === 'rechnung' ? 'rep-tab-active' : '' }}">Rechnungsabgleich</a>
    <a href="{{ route('admin.commissions_internal.audit') }}" class="rep-tab {{ ($active ?? '') === 'protokoll' ? 'rep-tab-active' : '' }}">Protokoll</a>
    <a href="{{ route('admin.vermittler.index') }}" class="rep-tab" style="margin-left:auto;">TARIFCHECK24-Abgleich →</a>
</div>
