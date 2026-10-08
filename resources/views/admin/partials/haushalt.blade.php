{{--
    Haushalt (PR-5b): Mitglieder, Hauptansprechpartner, Beitragszahler und
    die laufenden Vertraege des Haushalts.
    Erwartet: $customer, $haushalt (HaushaltService::uebersicht() oder null).
    Mitglieder ausserhalb des eigenen Portfolios stehen nur als Anzahl da.
--}}
@php
    $eurH = fn($v) => number_format((float) $v, 2, ',', '.') . ' €';
@endphp
<div class="card" id="haushalt">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:10px;">
        <div class="card-title" style="margin-bottom:0;">🏠 Haushalt @if($haushalt && $haushalt['haushalt']->name)– {{ $haushalt['haushalt']->name }}@endif</div>
    </div>
    <p style="font-size:12px;color:var(--ink-soft);margin:0 0 14px;">Personen, die zusammen wohnen. Jede Akte bleibt eigenständig – nichts wird zusammengeführt oder kopiert. Austragen beendet nur die Mitgliedschaft.</p>

    @if(! $haushalt)
        <div class="muted-sm" style="margin-bottom:10px;">Diese Akte gehört zu keinem Haushalt.</div>
        <form method="POST" action="{{ route('admin.customer.haushalt.gruenden', $customer->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;margin:0;">
            @csrf
            <input type="text" name="name" maxlength="120" class="eingabe" placeholder="Name (optional), z. B. Familie Abboud" aria-label="Name des Haushalts" style="flex:1;min-width:200px;font-size:13px;">
            <button type="submit" class="btn btn-primary" style="padding:8px 14px;">Haushalt anlegen</button>
        </form>
    @else
        @foreach($haushalt['warnungen'] as $warnung)
        <div style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:8px 12px;font-size:12.5px;margin-bottom:8px;">⚠ {{ $warnung }}</div>
        @endforeach

        @foreach($haushalt['mitglieder'] as $m)
        @php $person = $m->customer; @endphp
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;{{ !$loop->first ? 'border-top:1px solid var(--line);' : '' }}flex-wrap:wrap;">
            <div style="min-width:0;flex:1;">
                <a href="{{ route('admin.customer', $person->id) }}" style="font-size:13.5px;font-weight:600;color:var(--ink);text-decoration:none;">{{ $person->user?->name ?? 'Unbekannt' }}</a>
                <span class="muted-xs"> · {{ $person->customer_number ?? 'ohne Kundennummer (Kind)' }}</span>
                @if((string) $person->id === (string) $customer->id)<span class="muted-xs"> · diese Akte</span>@endif
                @if($m->hauptansprechpartner)
                <span style="font-size:11px;background:#E7F6EE;color:#0F6B3A;border-radius:999px;padding:2px 8px;margin-left:4px;">Hauptansprechpartner</span>
                @endif
                @if($m->beitragszahler)
                <span style="font-size:11px;background:#EAF2FB;color:#185FA5;border-radius:999px;padding:2px 8px;margin-left:4px;">Beitragszahler</span>
                @endif
                @if($m->valid_from)<span class="muted-2xs"> · seit {{ $m->valid_from->format('d.m.Y') }}</span>@endif
            </div>
            <div style="display:flex;gap:6px;flex:none;flex-wrap:wrap;">
                @unless($m->hauptansprechpartner)
                <form method="POST" action="{{ route('admin.haushalt.hauptansprechpartner', $m->id) }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">Als Hauptansprechpartner</button>
                </form>
                @endunless
                <form method="POST" action="{{ route('admin.haushalt.beitragszahler', $m->id) }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">{{ $m->beitragszahler ? 'Kein Beitragszahler' : 'Beitragszahler' }}</button>
                </form>
                <form method="POST" action="{{ route('admin.haushalt.austragen', $m->id) }}" style="margin:0;"
                      data-confirm="{{ $person->user?->name ?? 'Kunde' }} aus dem Haushalt austragen? Die Kundenakte bleibt mit allen Verträgen unverändert; die Mitgliedschaft endet heute.">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">Austragen</button>
                </form>
            </div>
        </div>
        @endforeach
        @if($haushalt['verborgen'] > 0)
        <div class="muted-xs" style="padding:6px 0;">+ {{ $haushalt['verborgen'] }} weitere(s) Mitglied(er) außerhalb Ihres Kundenbestands.</div>
        @endif

        <details style="margin-top:10px;border-top:1px solid var(--line);padding-top:10px;">
            <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--ink);">➕ Person aufnehmen</summary>
            <form method="POST" action="{{ route('admin.haushalt.aufnehmen', $haushalt['haushalt']->id) }}" data-verknuepfen-form style="margin-top:10px;">
                @csrf
                <input type="hidden" name="von_kunde" value="{{ $customer->id }}">
                <input type="hidden" name="customer_id" value="" data-verknuepfen-id>
                <input type="search" class="eingabe" placeholder="Kunde suchen (Name oder Kundennummer) …" aria-label="Kunde für den Haushalt suchen" autocomplete="off"
                       data-verknuepfen-suche data-url="{{ route('admin.customers.search') }}" data-exclude="{{ $customer->id }}" style="width:100%;font-size:13px;">
                <div data-verknuepfen-treffer style="margin-top:6px;"></div>
                <div data-verknuepfen-auswahl class="muted-sm" style="margin:6px 0;" hidden></div>
                <button type="submit" class="btn btn-primary" style="margin-top:6px;padding:8px 14px;">Aufnehmen</button>
            </form>
        </details>

        <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:10px;">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft);margin-bottom:6px;">
                Laufende Verträge des Haushalts ({{ $haushalt['vertraege']->count() }}) · {{ $eurH($haushalt['monatsbeitrag']) }}/Monat
            </div>
            @forelse($haushalt['vertraege'] as $v)
            <div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;padding:3px 0;">
                <span>{{ $v->typeIcon() }} {{ $v->typeLabel() }} · {{ $v->insurer ?: '—' }} <span class="muted-xs">({{ $v->customer?->user?->name ?? 'Kunde' }})</span></span>
                <span class="muted-xs">{{ $eurH($v->monthlyPremium()) }}/Mon.</span>
            </div>
            @empty
            <div class="muted-sm">Keine laufenden Verträge.</div>
            @endforelse
            @if($haushalt['fremdvertraege'] > 0)
            <div class="muted-xs" style="margin-top:4px;">zzgl. {{ $haushalt['fremdvertraege'] }} {{ $haushalt['fremdvertraege'] === 1 ? 'Fremdvertrag' : 'Fremdverträge' }} (nicht über uns)</div>
            @endif
        </div>
    @endif
</div>

@include('admin.partials.kundensuche_script')
