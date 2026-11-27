{{-- Eine Zeile der Kundendaten-Karten: Beschriftung, Wert, Kopieren
     (sensible Werte verdeckt mit Auge zum Aufdecken). --}}
<div class="kd-zeile">
    <dt>{{ $f['label'] }}</dt>
    <dd>
        @if($f['kopie'] === '')
            <span class="kd-leer">—</span>
        @else
            <span class="kd-wert{{ $f['sensibel'] ? ' kd-verdeckt' : '' }}" data-kd-wert>
                @if($f['sensibel'])
                <span class="kd-maske">{{ mb_substr($f['kopie'], 0, 2).'•••••• '.mb_substr($f['kopie'], -4) }}</span><span class="kd-klar" hidden>{{ $f['anzeige'] }}</span>
                @else
                {{ $f['anzeige'] }}
                @endif
            </span>
            @if($f['geerbt'])<span class="kd-geerbt">vom Elternteil übernommen</span>@endif
            <span class="kd-knoepfe">
                @if($f['sensibel'])
                <button type="button" class="kd-knopf kd-zeigen" aria-label="{{ $f['label'] }} anzeigen" title="Anzeigen">👁</button>
                @endif
                <button type="button" class="kd-knopf kd-kopieren" data-kopie="{{ $f['kopie'] }}" data-kd-feld="{{ $f['sensibel'] ? $f['key'] : '' }}"
                    aria-label="{{ $f['label'] }} kopieren" title="Kopieren">📋</button>
            </span>
        @endif
    </dd>
</div>
