{{--
    Weitere Personen eines Vertrags (PR-6): versicherte Personen,
    abweichender Beitragszahler, Beguenstigte. Der Versicherungsnehmer ist
    der Kunde der Akte und steht hier nur zur Orientierung.
    Erwartet: $contract, $beteiligte (Collection), $sichtbareKunden (?array).
--}}
@php
    $sichtbarSet = $sichtbareKunden === null ? null : array_flip(array_map('strval', $sichtbareKunden));
    $darfSehen = fn($id) => $id && ($sichtbarSet === null || isset($sichtbarSet[(string) $id]));
    $gruppen = $beteiligte->groupBy('rolle');
@endphp
<div class="card" id="beteiligte" style="max-width:980px;">
    <div class="card-title" style="margin-bottom:4px;">👥 Personen am Vertrag</div>
    <p style="font-size:12px;color:var(--ink-soft);margin:0 0 12px;">
        Versicherungsnehmer: <strong>{{ $contract->customer?->user?->name ?? 'Kunde' }}</strong> ({{ $contract->customer?->customer_number ?? '—' }}).
        Ohne Eintrag zahlt der Versicherungsnehmer selbst. Eine versicherte Person braucht keine eigene Kundenakte.
    </p>

    @foreach(\App\Models\VertragBeteiligter::ROLLEN as $rolle => $label)
    <div style="margin-bottom:10px;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft);margin-bottom:4px;">{{ $label }}</div>
        @forelse($gruppen->get($rolle, collect()) as $b)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 0;flex-wrap:wrap;">
            <div style="font-size:13px;min-width:0;">
                @if($darfSehen($b->customer_id))
                <a href="{{ route('admin.customer', $b->customer_id) }}" style="font-weight:600;color:var(--ink);text-decoration:none;">{{ $b->anzeigeName() }}</a>
                @else
                <span style="font-weight:600;">{{ $b->anzeigeName() }}</span>
                @endif
                <span class="muted-xs">
                    @if(! $b->customer_id) · ohne Kundenakte @elseif(! $darfSehen($b->customer_id)) · Kundenakte außerhalb Ihres Bestands @elseif($b->customer?->customer_number) · {{ $b->customer->customer_number }}@endif
                    @if((string) $b->customer_id === (string) $contract->customer_id) · Versicherungsnehmer @endif
                    @if($b->geburtsdatum) · geb. {{ $b->geburtsdatum->format('d.m.Y') }}@endif
                    @if($b->anteil_prozent !== null) · {{ rtrim(rtrim(number_format((float) $b->anteil_prozent, 2, ',', ''), '0'), ',') }} %@endif
                    @if($b->notiz) · {{ $b->notiz }}@endif
                </span>
            </div>
            <form method="POST" action="{{ route('admin.contract.beteiligte.destroy', $b->id) }}" style="margin:0;"
                  data-confirm="{{ $b->rolleLabel() }} „{{ $b->anzeigeName() }}“ vom Vertrag entfernen? Die Kundenakte bleibt unverändert, die Angabe bleibt im Änderungsverlauf lesbar.">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">Entfernen</button>
            </form>
        </div>
        @empty
        <div class="muted-sm">{{ $rolle === \App\Models\VertragBeteiligter::ROLLE_BEITRAGSZAHLER ? 'Keiner – der Versicherungsnehmer zahlt.' : 'Niemand eingetragen.' }}</div>
        @endforelse
    </div>
    @endforeach

    <details style="margin-top:10px;border-top:1px solid var(--line);padding-top:10px;">
        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--ink);">➕ Person hinzufügen</summary>
        <form method="POST" action="{{ route('admin.contract.beteiligte.store', $contract->id) }}" data-verknuepfen-form data-verknuepfen-optional style="margin-top:10px;display:grid;gap:8px;">
            @csrf
            <select name="rolle" class="eingabe" aria-label="Rolle am Vertrag" style="font-size:13px;">
                @foreach(\App\Models\VertragBeteiligter::ROLLEN as $rolle => $label)
                <option value="{{ $rolle }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="hidden" name="customer_id" value="" data-verknuepfen-id>
            <input type="search" class="eingabe" placeholder="Bestehenden Kunden suchen (Name oder Kundennummer) …" aria-label="Kunde für den Vertrag suchen" autocomplete="off"
                   data-verknuepfen-suche data-url="{{ route('admin.customers.search') }}" data-exclude="" style="font-size:13px;">
            <div data-verknuepfen-treffer></div>
            <div data-verknuepfen-auswahl class="muted-sm" hidden></div>
            <div class="muted-xs">… oder Person ohne eigene Kundenakte:</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" name="name" maxlength="160" class="eingabe" placeholder="Name" aria-label="Name der Person ohne Kundenakte" style="flex:2;min-width:180px;font-size:13px;">
                <input type="date" name="geburtsdatum" class="eingabe" aria-label="Geburtsdatum der Person ohne Kundenakte" style="flex:1;min-width:140px;font-size:13px;">
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="number" name="anteil_prozent" min="0.01" max="100" step="0.01" class="eingabe" placeholder="Anteil in % (nur Begünstigte)" aria-label="Anteil in Prozent, nur für Begünstigte" style="flex:1;min-width:160px;font-size:13px;">
                <input type="text" name="notiz" maxlength="255" class="eingabe" placeholder="Notiz (optional), z. B. „Tochter, familienversichert“" aria-label="Notiz zur Person" style="flex:2;min-width:180px;font-size:13px;">
            </div>
            <div><button type="submit" class="btn btn-primary" style="padding:8px 14px;">Hinzufügen</button></div>
        </form>
    </details>
</div>

@include('admin.partials.kundensuche_script')
