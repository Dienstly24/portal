{{--
    Herkunfts-Kennzeichen eines Vertrags (28.09.2026). Eigenvertraege tragen
    KEIN Abzeichen - sie sind der Normalfall; ein Abzeichen an jeder Zeile
    waere Ziergrafik und wuerde die zwei Faelle verstecken, um die es geht.
--}}
@if(isset($contract) && $contract)
@if($contract->isExternal())
<span class="origin-badge origin-badge-fremd" title="Fremdvertrag – nur zur Dokumentation erfasst. Nicht über uns vermittelt, kein Mandat, keine Courtage. Zuständig: {{ $contract->responsibleParty() }}.">📁 Fremdvertrag</span>
@elseif($contract->isTransferred())
<span class="origin-badge origin-badge-uebernommen" title="Ursprünglich fremd, per Maklervollmacht/Courtagezusage übernommen{{ $contract->previous_broker ? ' (vorher: '.$contract->previous_broker.')' : '' }}.">🔄 Übernommen{{ $contract->transfer_date ? ' am '.$contract->transfer_date->format('d.m.Y') : '' }}</span>
@endif
@once
<style>
.origin-badge{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.01em;border-radius:6px;padding:1px 7px;white-space:nowrap;vertical-align:middle;cursor:help;}
.origin-badge-fremd{color:#4B5250;background:#ECEAE4;border:1px solid #CFCBC0;}
.origin-badge-uebernommen{color:#0E7A41;background:#E7F6EE;border:1px solid #BFE6D2;}
/* Fremdvertraege sehen nie wie eigene aus: gestrichelte Kante links, gedaempft. */
tr.contract-row-fremd > td{opacity:.78;}
tr.contract-row-fremd > td:first-child{box-shadow:inset 3px 0 0 0 transparent;border-left:3px dashed #B8B2A4;}
</style>
@endonce
@endif
