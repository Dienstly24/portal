{{-- Kundendaten auf einen Blick (Betreiber-Vorlage 27.11.2026, Raster wie
     die Vorlage): Zeile 1 Persoenlich | Adresse | Status, Zeile 2 Bank |
     Versicherung | Schnellaktionen, Zeile 3 Portal-Zugang | Verknuepfte
     Kunden, darunter Fusszeile. Alle Werte direkt aus der Akte
     (App\Support\Kundendatenblatt) - nichts geschaetzt, nichts erfunden.
     Kopiert wird IMMER der Originalwert (data-kopie), nie die Anzeige.
     Sensible Werte sind verdeckt; Kopieren/Aufdecken steht im
     Aktivitaetsprotokoll (nur Feldnamen). KEINE eingebettete Karte: das
     Kartenbild ist eine reine Grafik, Fremddienste werden erst auf Klick
     geoeffnet (Datenschutz, CSP). --}}
@php
    $kdBereiche = \App\Support\Kundendatenblatt::bereiche($customer);
    $kdFeld = fn (string $bereich) => collect($kdBereiche[$bereich]['felder'])->keyBy('key');
    $kdP = $kdFeld('persoenlich');
    $kdA = $kdFeld('adresse');
    $kdB = $kdFeld('bank');
    $kdW = $kdFeld('weitere');
    $kdIcon = ['first_name' => '👤', 'last_name' => '👥', 'birth_date' => '📅', 'email' => '✉️', 'email2' => '✉️', 'phone' => '📞', 'mobile' => '📱'];
    $kdPersSichtbar = collect(['first_name', 'last_name', 'birth_date', 'email', 'phone', 'mobile'])
        ->when($kdP['email2']['kopie'] !== '', fn ($c) => $c->push('email2'))
        ->map(fn ($k) => $kdP[$k]);
    $kdKern = ['health_insurance_type', 'health_insurance_company', 'tax_id'];
    $kdVersSichtbar = $kdW->only($kdKern)->values();
    $kdVersMehr = $kdW->except($kdKern)->values();
    $kdStrasse = trim($kdA['address_street']['kopie'].' '.$kdA['address_house_number']['kopie']);
    $kdOrt = trim($kdA['address_zip']['kopie'].' '.$kdA['address_city']['kopie']);
    $kdAdresse = $kdA['address_full']['kopie'];
    $kdAdressZeilen = ($kdStrasse !== '' || $kdOrt !== '')
        ? array_values(array_filter([$kdStrasse, $kdOrt, $kdA['address_country']['kopie']]))
        : array_values(array_filter(array_map('trim', explode(',', $kdAdresse))));
    $kdAktiv = $aktiveVertraege->count();
    // Verknuepfte Kunden: Familie (mit Rolle) und weitere Beziehungen,
    // jede andere Akte genau einmal.
    $kdVerk = collect();
    foreach (($familie['all'] ?? collect()) as $r) {
        if ($r->relatedCustomer) {
            $kdVerk->put((string) $r->relatedCustomer->id, ['kunde' => $r->relatedCustomer, 'art' => \App\Models\CustomerFamilyRelation::roleLabel($r->relationship_type)]);
        }
    }
    foreach (($verknuepft ?? collect()) as $vk) {
        $anderer = (string) $vk->customer_a_id === (string) $customer->id ? $vk->customerB : $vk->customerA;
        if ($anderer && ! $kdVerk->has((string) $anderer->id)) {
            $kdVerk->put((string) $anderer->id, ['kunde' => $anderer, 'art' => $vk->labelFor((string) $customer->id)]);
        }
    }
    $kdInitialen = fn ($n) => collect(preg_split('/\s+/', trim((string) $n)) ?: [])->filter()->map(fn ($t) => mb_strtoupper(mb_substr($t, 0, 1)))->take(2)->implode('') ?: '?';
@endphp
<div class="kd-raster" id="kundendaten" data-kd-protokoll="{{ route('admin.customer.data_copied', $customer->id) }}">

    {{-- Persoenliche Daten --}}
    <section class="kd-karte kd-pers" aria-label="Persönliche Daten">
        <div class="kd-karte-kopf">
            <h2 class="kd-karte-titel"><span class="kd-karte-icon">👤</span>Persönliche Daten</h2>
            <button type="button" class="kd-kopf-knopf kd-kopieren" data-kopie="{{ \App\Support\Kundendatenblatt::bereichAlsText($kdBereiche['persoenlich']) }}" title="Persönliche Daten kopieren">⧉ Kopieren</button>
        </div>
        <dl class="kd-liste">
            @foreach($kdPersSichtbar as $f)
                @include('admin.partials.kundendaten_zeile', ['f' => $f, 'icon' => $kdIcon[$f['key']] ?? null])
            @endforeach
        </dl>
    </section>

    {{-- Adresse --}}
    <section class="kd-karte kd-adr" aria-label="Adresse">
        <div class="kd-karte-kopf">
            <h2 class="kd-karte-titel"><span class="kd-karte-icon">🏠</span>Adresse</h2>
            @if($kdAdresse !== '')
            <button type="button" class="kd-kopf-knopf kd-kopieren" data-kopie="{{ \App\Support\Kundendatenblatt::bereichAlsText($kdBereiche['adresse']) }}" title="Adresse kopieren">⧉ Kopieren</button>
            @endif
        </div>
        <div class="kd-adr-inhalt">
            <div class="kd-adresse">
                @forelse($kdAdressZeilen as $kdZeile)
                <div>{{ $kdZeile }}</div>
                @empty
                <div class="kd-leer">Keine Adresse hinterlegt</div>
                @endforelse
                @if($kdA['address_full']['geerbt'])<span class="kd-geerbt">vom Elternteil übernommen</span>@endif
            </div>
            @if($kdAdresse !== '')
            {{-- Reine Grafik (kein Kartendienst). Der Link oeffnet die
                 Adresse erst auf Klick bei OpenStreetMap, nie Google. --}}
            <div class="kd-kartenbild" aria-hidden="true">
                <span class="kd-pin">📍</span>
                <a class="kd-karten-knopf" href="https://www.openstreetmap.org/search?query={{ urlencode($kdAdresse) }}" target="_blank" rel="noopener noreferrer" aria-hidden="false">📍 Auf Karte anzeigen</a>
            </div>
            @endif
        </div>
        <details class="kd-mehr">
            <summary>Einzelfelder anzeigen</summary>
            <dl class="kd-liste">
                @foreach($kdA->except('address_full') as $f)
                    @include('admin.partials.kundendaten_zeile', ['f' => $f])
                @endforeach
            </dl>
        </details>
    </section>

    {{-- Status --}}
    <aside class="kd-status">
        <div class="kd-status-zeile">
            <span class="kd-status-schild" aria-hidden="true">✓</span>
            <div style="flex:1;min-width:0;">
                <div class="kd-status-label">Kunde seit</div>
                <div class="kd-status-wert">{{ $customer->created_at?->lokal()->format('d.m.Y') ?? '—' }}</div>
            </div>
            <span class="kd-status-pill">{{ $kdAktiv }} {{ $kdAktiv === 1 ? 'Vertrag' : 'Verträge' }}</span>
        </div>
        <div class="kd-status-trenner"></div>
        <div class="kd-status-label">Aktueller Status</div>
        <div class="kd-status-badge{{ $kdAktiv > 0 ? '' : ' kd-status-aus' }}">{{ $kdAktiv > 0 ? '✓ Aktiv' : 'Kein aktiver Vertrag' }}</div>
        <button type="button" class="kd-alle-knopf kd-alle" data-kopie="{{ \App\Support\Kundendatenblatt::alsText($customer) }}" data-kd-felder="alle">📋 Alle Kundendaten kopieren</button>
    </aside>

    {{-- Bankverbindung --}}
    <section class="kd-karte kd-bank" aria-label="Bankverbindung">
        <div class="kd-karte-kopf">
            <h2 class="kd-karte-titel"><span class="kd-karte-icon">🏦</span>Bankverbindung</h2>
            <span class="kd-sicher">🔒 Sicher</span>
        </div>
        <dl class="kd-liste">
            @foreach(['account_holder', 'iban', 'bic'] as $k)
                @include('admin.partials.kundendaten_zeile', ['f' => $kdB[$k]])
            @endforeach
        </dl>
        <details class="kd-mehr">
            <summary>Details anzeigen</summary>
            <dl class="kd-liste">
                @include('admin.partials.kundendaten_zeile', ['f' => $kdB['iban2']])
            </dl>
        </details>
    </section>

    {{-- Versicherungsdaten --}}
    <section class="kd-karte kd-vers" aria-label="Versicherungsdaten">
        <div class="kd-karte-kopf">
            <h2 class="kd-karte-titel"><span class="kd-karte-icon">🛡️</span>Versicherungsdaten</h2>
        </div>
        <dl class="kd-liste">
            @foreach($kdVersSichtbar as $f)
                @include('admin.partials.kundendaten_zeile', ['f' => $f])
            @endforeach
        </dl>
        @php $kdMehrGefuellt = $kdVersMehr->where('kopie', '!=', '')->count(); @endphp
        <details class="kd-mehr">
            <summary>Alle Daten anzeigen @if($kdMehrGefuellt > 0)<span class="kd-zahl">{{ $kdMehrGefuellt }}</span>@endif</summary>
            <dl class="kd-liste">
                @foreach($kdVersMehr as $f)
                    @include('admin.partials.kundendaten_zeile', ['f' => $f])
                @endforeach
            </dl>
        </details>
    </section>

    {{-- Schnellaktionen --}}
    <section class="kd-schnell" aria-label="Schnellaktionen">
        <h2 class="kd-karte-titel"><span class="kd-blitz">⚡</span>Schnellaktionen</h2>
        @if(! $customer->eigenstaendigkeitsSperre())
        <a href="{{ route('admin.contract.create', $customer->id) }}"><span>📄 Neuen Vertrag erstellen</span><b>›</b></a>
        @endif
        <button type="button" data-kd-tab="tab-dokumente"><span>⬆️ Dokument hochladen</span><b>›</b></button>
        <button type="button" data-kd-tab="tab-nachrichten"><span>✉️ Nachricht senden</span><b>›</b></button>
        <a href="{{ route('admin.tasks', ['customer_id' => $customer->id, 'neu' => 1]) }}"><span>☑️ Aufgabe erstellen</span><b>›</b></a>
    </section>

    {{-- Portal-Zugang --}}
    <div class="kd-portal">
        @include('admin.partials.portal_zugang', ['customer' => $customer])
    </div>

    {{-- Verknuepfte Kunden --}}
    <section class="kd-karte kd-verk" aria-label="Verknüpfte Kunden">
        <div class="kd-karte-kopf">
            <h2 class="kd-karte-titel"><span class="kd-karte-icon">👥</span>Verknüpfte Kunden ({{ $kdVerk->count() }})</h2>
            <a class="kd-alle-link" href="#verknuepfte-kunden">Alle anzeigen →</a>
        </div>
        @forelse($kdVerk->take(4) as $v)
        <a class="kd-verk-zeile" href="{{ route('admin.customer', $v['kunde']->id) }}">
            <span class="kd-verk-av">{{ $kdInitialen($v['kunde']->user?->name) }}</span>
            <span class="kd-verk-name">{{ $v['kunde']->user?->name ?? 'Kunde' }}</span>
            <span class="kd-verk-art">{{ $v['art'] }}</span>
        </a>
        @empty
        <div class="kd-leer" style="font-size:13px;">Keine verknüpften Kunden.</div>
        @endforelse
    </section>

    <div class="kd-meldung" role="status" aria-live="polite" hidden></div>
</div>

{{-- Fusszeile wie in der Vorlage --}}
<div class="kd-fuss">
    <div class="kd-fuss-info">
        <span class="kd-fuss-schutz">🛡️ Datenschutz &amp; Sicherheit</span>
        <span class="kd-fuss-trenner">|</span>
        <span>Zuletzt aktualisiert: {{ $customer->updated_at?->lokal()->format('d.m.Y, H:i') ?? '—' }} Uhr</span>
    </div>
    <div class="kd-fuss-aktionen">
        @if(auth()->user()->role === 'admin')
        <form method="POST" action="{{ route('admin.customers.delete', $customer->id) }}" style="margin:0;"
              data-confirm="Kunde {{ $customer->user?->name }} wirklich ENDGÜLTIG löschen? Alle Verträge, Tickets und Dokumente gehen verloren!" data-confirm-2="Wirklich sicher? Diese Aktion kann NICHT rückgängig gemacht werden.">
            @csrf @method('DELETE')
            <button type="submit" class="kd-fuss-knopf kd-fuss-loeschen">🗑️ Kunde löschen</button>
        </form>
        @endif
        <a href="{{ route('admin.customer.merge', $customer->id) }}" class="kd-fuss-knopf">Duplikat zusammenführen</a>
    </div>
</div>

<style>
.kd-raster{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 360px;grid-template-areas:"pers adr status" "bank vers schnell" "portal portal verk";gap:16px;margin-bottom:14px;align-items:stretch;}
.kd-pers{grid-area:pers}.kd-adr{grid-area:adr}.kd-status{grid-area:status}.kd-bank{grid-area:bank}.kd-vers{grid-area:vers}.kd-schnell{grid-area:schnell}.kd-portal{grid-area:portal;min-width:0}.kd-verk{grid-area:verk}
.kd-karte{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;box-shadow:0 2px 6px rgba(19,26,23,.05);min-width:0;}
.kd-karte-kopf{display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.kd-karte-titel{font-size:17px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px;color:var(--ink);flex:1;min-width:0;}
.kd-karte-icon{font-size:20px;line-height:1;flex:none;}
.kd-kopf-knopf{border:1px solid var(--line);background:#fff;border-radius:10px;padding:6px 12px;font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;white-space:nowrap;box-shadow:0 1px 2px rgba(19,26,23,.06);}
.kd-kopf-knopf:hover{background:var(--canvas);}
.kd-kopf-knopf.kd-ok{background:var(--emerald-soft);border-color:var(--emerald);color:var(--emerald-deep);}
.kd-sicher{font-size:12.5px;font-weight:600;color:var(--emerald-deep);background:var(--emerald-soft);border-radius:999px;padding:4px 12px;white-space:nowrap;}
.kd-liste{margin:0;}
.kd-zeile{display:grid;grid-template-columns:minmax(110px,42%) 1fr;gap:8px;align-items:center;padding:6px 0;border-top:1px solid var(--line);}
.kd-liste .kd-zeile:first-child{border-top:none;}
.kd-zeile dt{font-size:13.5px;color:var(--ink-soft);display:flex;align-items:center;gap:10px;}
.kd-zeile-icon{width:18px;text-align:center;font-size:13px;opacity:.75;flex:none;}
.kd-zeile dd{margin:0;display:flex;align-items:center;gap:6px;min-width:0;font-size:14px;font-weight:700;color:var(--ink);}
.kd-wert{overflow-wrap:anywhere;min-width:0;}
.kd-verdeckt{letter-spacing:.02em;}
.kd-leer{color:var(--ink-soft);font-weight:400;}
.kd-geerbt{font-size:10.5px;background:#FEF3C7;color:#92400E;border-radius:999px;padding:1px 7px;white-space:nowrap;}
.kd-knoepfe{margin-left:auto;display:inline-flex;gap:2px;flex-shrink:0;}
.kd-knopf{border:1px solid transparent;background:none;border-radius:6px;cursor:pointer;font-size:13px;line-height:1;padding:5px 6px;opacity:0;transition:opacity .12s;}
.kd-knopf.kd-zeigen{opacity:.6;}
.kd-zeile:hover .kd-knopf,.kd-knopf:focus-visible{opacity:.9;}
.kd-knopf:hover,.kd-knopf:focus-visible{opacity:1;border-color:var(--line);background:var(--canvas);}
.kd-knopf.kd-ok{opacity:1;color:var(--emerald);}
@media (hover:none){.kd-knopf{opacity:.55;}}
.kd-mehr{margin-top:10px;}
.kd-mehr summary{cursor:pointer;list-style:none;text-align:center;font-size:13px;font-weight:600;color:var(--ink);border:1px solid var(--line);border-radius:10px;padding:7px 16px;width:max-content;margin:6px auto 0;background:#fff;box-shadow:0 1px 2px rgba(19,26,23,.06);}
.kd-mehr summary::-webkit-details-marker{display:none;}
.kd-mehr summary::after{content:'  ⌄';}
.kd-mehr[open] summary::after{content:'  ⌃';}
.kd-mehr[open] summary{margin-bottom:8px;}
.kd-zahl{display:inline-block;margin-left:4px;font-size:11px;background:var(--emerald-soft);color:var(--emerald-deep);border-radius:999px;padding:0 7px;}
.kd-adr-inhalt{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:14px;align-items:stretch;}
.kd-adresse{font-size:16px;font-weight:600;line-height:1.7;color:var(--ink);padding-top:4px;}
.kd-kartenbild{position:relative;min-height:160px;border-radius:12px;overflow:hidden;border:1px solid var(--line);
  background:
    linear-gradient(115deg,transparent 46%,rgba(255,255,255,.9) 46.5%,rgba(255,255,255,.9) 49%,transparent 49.5%),
    linear-gradient(18deg,transparent 60%,rgba(255,255,255,.85) 60.5%,rgba(255,255,255,.85) 62.5%,transparent 63%),
    linear-gradient(160deg,transparent 30%,rgba(255,255,255,.7) 30.5%,rgba(255,255,255,.7) 31.8%,transparent 32.3%),
    radial-gradient(circle at 25% 30%,rgba(23,166,91,.22) 0 18%,transparent 19%),
    radial-gradient(circle at 78% 70%,rgba(23,166,91,.18) 0 22%,transparent 23%),
    radial-gradient(circle at 70% 20%,rgba(23,166,91,.12) 0 14%,transparent 15%),
    var(--emerald-soft);}
.kd-pin{position:absolute;left:50%;top:32%;transform:translate(-50%,-50%);font-size:30px;filter:drop-shadow(0 3px 3px rgba(0,0,0,.25));}
.kd-karten-knopf{position:absolute;left:10px;right:10px;bottom:10px;background:#fff;border-radius:10px;padding:8px 10px;text-align:center;font-size:13px;font-weight:700;color:var(--ink);text-decoration:none;box-shadow:0 2px 8px rgba(19,26,23,.15);}
.kd-karten-knopf:hover{background:var(--canvas);}
.kd-status{position:relative;overflow:hidden;background:linear-gradient(140deg,#1B5E3B 0%,var(--graphite) 70%);color:#fff;border-radius:16px;padding:20px 22px;box-shadow:0 6px 18px rgba(19,26,23,.22);min-width:0;}
.kd-status::after{content:'';position:absolute;right:-40px;bottom:-60px;width:220px;height:160px;border-radius:50%;background:radial-gradient(circle,rgba(25,180,99,.35),transparent 70%);pointer-events:none;}
.kd-status-zeile{display:flex;align-items:center;gap:14px;}
.kd-status-schild{width:46px;height:52px;flex:none;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;background:linear-gradient(180deg,var(--emerald-bright),var(--emerald-deep));clip-path:polygon(50% 0,100% 18%,100% 55%,50% 100%,0 55%,0 18%);}
.kd-status-label{font-size:14px;opacity:.85;}
.kd-status-wert{font-size:24px;font-weight:800;margin-top:2px;}
.kd-status-pill{font-size:12.5px;font-weight:700;background:var(--emerald);border-radius:999px;padding:5px 12px;white-space:nowrap;align-self:center;}
.kd-status-trenner{height:1px;background:rgba(255,255,255,.18);margin:16px 0 12px;}
.kd-status-badge{display:inline-block;margin-top:8px;font-size:14px;font-weight:700;background:var(--emerald);border-radius:999px;padding:5px 14px;}
.kd-status-aus{background:rgba(255,255,255,.14);}
.kd-alle-knopf{position:relative;z-index:1;display:block;width:100%;margin-top:16px;border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:10px 12px;font:inherit;font-size:14px;font-weight:700;cursor:pointer;color:var(--graphite);background:#fff;box-shadow:0 2px 0 rgba(0,0,0,.2);}
.kd-alle-knopf:hover{background:var(--emerald-soft);}
.kd-alle-knopf.kd-ok{background:var(--emerald);color:#fff;}
.kd-schnell{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;box-shadow:0 2px 6px rgba(19,26,23,.05);min-width:0;}
.kd-schnell .kd-karte-titel{margin-bottom:8px;}
.kd-blitz{font-size:20px;}
.kd-schnell a,.kd-schnell button{display:flex;justify-content:space-between;align-items:center;width:100%;padding:11px 8px;border:0;border-top:1px solid var(--line);background:none;font:inherit;font-size:14px;font-weight:600;color:var(--ink);text-decoration:none;cursor:pointer;text-align:left;border-radius:6px;}
.kd-schnell a:hover,.kd-schnell button:hover{background:var(--canvas);}
.kd-schnell b{font-size:18px;color:var(--ink);}
.kd-portal .kk-portal{height:100%;}
.kk-portal-zeile{display:flex;align-items:center;gap:12px;flex-wrap:wrap;cursor:pointer;list-style:none;}
.kk-portal-zeile::-webkit-details-marker{display:none;}
.kk-portal-satz{display:block;font-size:13.5px;color:var(--ink-soft);margin-top:4px;}
.kk-portal-mehr{margin-left:auto;font-size:13px;font-weight:600;border:1px solid var(--line);border-radius:10px;padding:7px 14px;background:#fff;box-shadow:0 1px 2px rgba(19,26,23,.06);}
.kk-portal-mehr::after{content:'  ⌄';}
.kk-portal[open] .kk-portal-mehr::after{content:'  ⌃';}
.kd-verk .kd-karte-titel{font-size:15.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;}
.kd-verk .kd-karte-icon{margin-right:8px;}
.kd-alle-link{font-size:13px;font-weight:700;color:var(--ink);text-decoration:none;white-space:nowrap;}
.kd-verk-zeile{display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:10px;align-items:center;padding:5px 0;text-decoration:none;color:var(--ink);}
.kd-verk-av{width:34px;height:34px;border-radius:50%;background:var(--graphite);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;}
.kd-verk-zeile:nth-child(odd) .kd-verk-av{background:#8FA79A;}
.kd-verk-name{font-size:13.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.kd-verk-art{font-size:11px;font-weight:600;color:var(--emerald-deep);background:var(--emerald-soft);border-radius:6px;padding:2px 8px;white-space:nowrap;}
.kd-fuss{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:4px 0 22px;padding:4px 2px;}
.kd-fuss-info{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:var(--ink-soft);}
.kd-fuss-schutz{color:var(--emerald-deep);font-weight:600;}
.kd-fuss-trenner{color:var(--line);}
.kd-fuss-aktionen{display:flex;gap:10px;flex-wrap:wrap;}
.kd-fuss-knopf{display:inline-block;border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 16px;font:inherit;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;cursor:pointer;}
.kd-fuss-knopf:hover{background:var(--canvas);}
.kd-fuss-loeschen{color:#B42318;border-color:#F1B5AE;}
.kd-fuss-loeschen:hover{background:#FEF3F2;}
.kd-meldung{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:60;background:var(--graphite);color:#fff;font-size:13px;font-weight:600;border-radius:999px;padding:9px 18px;box-shadow:0 6px 20px rgba(0,0,0,.25);}
.kd-raster [hidden]{display:none!important;}
@media (max-width:1280px){.kd-raster{grid-template-columns:minmax(0,1fr) minmax(0,1fr);grid-template-areas:"pers adr" "bank vers" "status schnell" "portal portal" "verk verk";}}
@media (max-width:760px){.kd-raster{grid-template-columns:minmax(0,1fr);grid-template-areas:"pers" "adr" "status" "bank" "vers" "schnell" "portal" "verk";}.kd-adr-inhalt{grid-template-columns:1fr}.kd-kartenbild{min-height:130px}}
</style>

@pushOnce('cspScripts')
<script @cspNonce>
(function () {
    var karte = document.getElementById('kundendaten');
    if (!karte) return;
    var meldung = karte.querySelector('.kd-meldung');
    var timer = null;

    function zeige(text, fehler) {
        meldung.textContent = text;
        meldung.style.background = fehler ? '#B42318' : '';
        meldung.hidden = false;
        clearTimeout(timer);
        timer = setTimeout(function () { meldung.hidden = true; }, 2400);
    }

    // Zwischenablage: moderne Schnittstelle, sonst ein unsichtbares Textfeld.
    function kopiere(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (ok, nein) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy') ? ok() : nein(); } catch (e) { nein(e); }
            document.body.removeChild(ta);
        });
    }

    // Protokoll: nur der FELDNAME, nie der Wert.
    function protokolliere(feld) {
        if (!feld) return;
        var token = document.querySelector('meta[name="csrf-token"]');
        try {
            fetch(karte.dataset.kdProtokoll, {
                method: 'POST', keepalive: true, credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
                body: JSON.stringify({ feld: feld })
            }).catch(function () {});
        } catch (e) {}
    }

    // Auf dem ganzen Dokument: die Kontaktleiste im Kopf nutzt dieselben Knoepfe.
    document.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-kd-tab]');
        if (tab) {
            var reiter = document.querySelector('.cust-tab[data-tab="' + tab.dataset.kdTab + '"]');
            if (reiter) { reiter.click(); reiter.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            return;
        }
        var zeigen = e.target.closest('.kd-zeigen');
        if (zeigen) {
            var wert = zeigen.closest('dd').querySelector('[data-kd-wert]');
            var klar = wert.querySelector('.kd-klar');
            var offen = klar.hidden;
            klar.hidden = !offen;
            wert.querySelector('.kd-maske').hidden = offen;
            zeigen.title = offen ? 'Verbergen' : 'Anzeigen';
            if (offen) protokolliere('angezeigt:' + (zeigen.nextElementSibling.dataset.kdFeld || ''));
            return;
        }
        var knopf = e.target.closest('.kd-kopieren, .kd-alle');
        if (!knopf || knopf.disabled || !knopf.dataset.kopie) return;
        kopiere(knopf.dataset.kopie).then(function () {
            var alle = knopf.classList.contains('kd-alle');
            zeige(alle ? '✓ Alle Kundendaten kopiert – jetzt im Zielformular einfügen' : '✓ Kopiert');
            knopf.classList.add('kd-ok');
            // Nur die kleinen Symbol-Knoepfe tauschen ihr Zeichen.
            var symbol = knopf.classList.contains('kd-knopf');
            if (symbol) knopf.textContent = '✓';
            setTimeout(function () { knopf.classList.remove('kd-ok'); if (symbol) knopf.textContent = '📋'; }, 1400);
            protokolliere(alle ? (knopf.dataset.kdFelder || 'alle') : knopf.dataset.kdFeld);
        }, function () {
            zeige('Kopieren nicht möglich – bitte Wert markieren und mit Strg+C kopieren.', true);
        });
    });
})();
</script>
@endPushOnce
