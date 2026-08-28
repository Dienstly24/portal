{{--
    Familie & Kinder: verknuepfte KUNDENAKTEN (Betreiber-Vorgabe 28.08.2026).

    Bewusst getrennt von den Familienmitgliedern aus `customer_family`: dort
    stehen Personen OHNE eigene Akte (nur Name + KV-Daten). Hier stehen
    Familienmitglieder, die einen EIGENEN Kundendatensatz mit Vertraegen,
    Dokumenten und Historie haben - so entstanden beim Einlesen mehrerer
    Gesundheitskarten. Diese Akten werden verknuepft, nie zusammengefuehrt.

    Erwartete Variablen:
      $customer   Customer
      $familie    array (FamilyRelationService::overview)
--}}
@php
    $famVorlaufTage = app(\App\Services\Family\FamilyRelationService::class)->leadMonths() * 30;
    $rollenGruppen = [
        ['titel' => 'Ehepartner/in', 'items' => $familie['spouses']],
        ['titel' => 'Kinder',        'items' => $familie['children']],
        ['titel' => 'Eltern',        'items' => $familie['parents']],
        ['titel' => 'Weitere Familienmitglieder', 'items' => $familie['others']],
    ];
    $gesamt = $familie['all']->count();
@endphp

<div class="card" id="familie-verknuepfungen">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:10px;">
        <div class="card-title" style="margin-bottom:0;">👪 Familie &amp; Kinder – verknüpfte Kunden ({{ $gesamt }})</div>
        <button type="button" class="btn btn-gold btn-sm" onclick="famSucheOeffnen()">Bestehenden Kunden hinzufügen</button>
    </div>
    <p style="font-size:12px;color:var(--ink-soft);margin:0 0 16px;">
        Familienmitglieder mit <strong>eigener Kundenakte</strong>. Die Verknüpfung wird in beide Richtungen
        gespeichert – vom Hauptkunden zum Kind und zurück. Es wird dabei nie ein Kunde angelegt, verändert oder gelöscht.
    </p>

    @if($familie['guardians']->isNotEmpty())
    {{-- Sicht des KINDES: wer ist die Bezugsperson? --}}
    <div style="border:1px solid #FDE68A;background:#FEF9E7;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
        <div style="font-size:13px;font-weight:700;color:#92400E;">🧒 Familienmitglied / Kind – abhängig von</div>
        <div style="font-size:12.5px;margin-top:6px;display:flex;flex-wrap:wrap;gap:10px;">
            @foreach($familie['guardians'] as $g)
            <a href="{{ route('admin.customer', $g->id) }}" style="color:#92400E;font-weight:600;text-decoration:none;">{{ $g->user?->name ?? 'Kunde' }}</a>
            @endforeach
        </div>
        <div style="font-size:11.5px;color:#92400E;margin-top:6px;">
            Adresse, E-Mail und Telefon werden – solange nichts Eigenes hinterlegt ist – von der Bezugsperson übernommen.
            Die Kundenakte bleibt mit allen Verträgen, Dokumenten und Vorgängen vollständig erhalten.
        </div>
    </div>
    @endif

    @if($familie['suggestions']->isNotEmpty())
    {{-- Bereits als "verwandt / kein Duplikat" erkannte Akten (z. B. gleicher
         Familienname beim Einlesen mehrerer Gesundheitskarten). Es fehlt nur
         noch die Rolle - und die vergibt immer ein Mensch. --}}
    <div style="border:1px solid #C7D9EF;background:#EAF2FB;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
        <div style="font-size:13px;font-weight:700;color:#185FA5;margin-bottom:4px;">Bereits erkannte verwandte Kunden ({{ $familie['suggestions']->count() }})</div>
        <div style="font-size:11.5px;color:#185FA5;margin-bottom:10px;">Diese Kundenakten sind als verwandt gekennzeichnet, haben aber noch keine Familienrolle. Rolle wählen und verknüpfen – der Datensatz bleibt unverändert.</div>
        @foreach($familie['suggestions'] as $vorschlag)
        <form method="POST" action="{{ route('admin.customer.family.link', $customer->id) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:6px 0;{{ !$loop->first ? 'border-top:1px solid #C7D9EF;' : '' }}">
            @csrf
            <input type="hidden" name="related_customer_id" value="{{ $vorschlag->id }}">
            <div style="flex:1;min-width:180px;font-size:13px;">
                <strong>{{ $vorschlag->user?->name ?? 'Kunde' }}</strong>
                <span style="color:var(--ink-soft);font-size:12px;"> · {{ $vorschlag->customer_number }}@if($vorschlag->age() !== null) · {{ $vorschlag->age() }} J.@endif</span>
            </div>
            <select name="relationship_type" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;">
                @foreach(\App\Models\CustomerFamilyRelation::SELECTABLE_ROLES as $rolle)
                <option value="{{ $rolle }}">{{ \App\Models\CustomerFamilyRelation::ROLES[$rolle] }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">Verknüpfen</button>
        </form>
        @endforeach
    </div>
    @endif

    @if($gesamt === 0)
    <div style="text-align:center;padding:26px 12px;color:var(--ink-soft);">
        <div style="font-size:36px;margin-bottom:6px;">👪</div>
        <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Keine Familienverknüpfungen</div>
        <div style="font-size:12.5px;">Über „Bestehenden Kunden hinzufügen" verbinden Sie eine bereits vorhandene Kundenakte
            (z. B. ein Kind, das über eine eigene Gesundheitskarte angelegt wurde) mit dieser Familie.</div>
    </div>
    @endif

    @foreach($rollenGruppen as $gruppe)
        @if($gruppe['items']->isNotEmpty())
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft);margin:14px 0 8px;">{{ $gruppe['titel'] }}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">
            @foreach($gruppe['items'] as $rel)
            @php
                $mitglied = $rel->relatedCustomer;
                $alter = $mitglied->age();
                $abhaengig = $rel->dependentNow();
                $stichtag = $rel->independenceDate();
            @endphp
            <div style="border:1px solid var(--line);border-radius:12px;padding:14px;background:var(--surface,#FBFAF6);">
                <div style="display:flex;align-items:flex-start;gap:10px;">
                    <div style="width:38px;height:38px;border-radius:10px;background:#EDEAE0;display:flex;align-items:center;justify-content:center;font-size:20px;flex:none;">{{ \App\Models\CustomerFamilyRelation::roleEmoji($rel->relationship_type) }}</div>
                    <div style="min-width:0;flex:1;">
                        <a href="{{ route('admin.customer', $mitglied->id) }}" style="font-size:14px;font-weight:700;color:var(--ink);text-decoration:none;">{{ $mitglied->user?->name ?? 'Kunde' }}</a>
                        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px;">{{ $mitglied->customer_number }}</div>
                        <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">
                            <span style="font-size:11px;background:#EAF2FB;color:#185FA5;border-radius:999px;padding:2px 9px;">{{ \App\Models\CustomerFamilyRelation::roleLabel($rel->relationship_type) }}</span>
                            @if($alter !== null)<span style="font-size:11px;background:var(--surface-soft,#F1EEE5);border:1px solid var(--line);border-radius:999px;padding:2px 9px;">{{ $alter }} Jahre</span>@endif
                            @if($abhaengig)
                            <span style="font-size:11px;background:#FEF3C7;color:#92400E;border-radius:999px;padding:2px 9px;">Familienmitglied (abhängig)</span>
                            @else
                            <span style="font-size:11px;background:#D9F4E6;color:#128a4b;border-radius:999px;padding:2px 9px;">Eigenständiger Kunde</span>
                            @endif
                        </div>
                        @if($abhaengig && $stichtag)
                        @php $restTage = \Illuminate\Support\Carbon::today()->diffInDays($stichtag, false); @endphp
                        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:6px;">15. Geburtstag: {{ $stichtag->format('d.m.Y') }}</div>
                        @if($restTage >= 0 && $restTage <= $famVorlaufTage)
                        {{-- Automatischer Hinweis: die Verselbststaendigung steht
                             an. Es wird NICHTS automatisch geaendert - der
                             Knopf legt nur eine Wiedervorlage an. --}}
                        <div style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:8px 10px;margin-top:8px;font-size:11.5px;">
                            ⚠ Wird in {{ $restTage > 60 ? (int) floor($restTage / 30) . ' Monaten' : $restTage . ' Tagen' }} 15.
                            Empfehlung: eigenständige Verträge / Kundenvorgänge prüfen.
                            @if($rel->transition_prepared_at)
                            <div style="margin-top:6px;">✓ Übergang vorbereitet am {{ $rel->transition_prepared_at->lokal()->format('d.m.Y') }}</div>
                            @endif
                        </div>
                        @if(!$rel->transition_prepared_at)
                        <form method="POST" action="{{ route('admin.family.prepare_transition', $rel->id) }}" style="margin-top:6px;">
                            @csrf
                            <button type="submit" class="btn btn-ghost" style="padding:4px 10px;font-size:11.5px;">Übergang vorbereiten</button>
                        </form>
                        @endif
                        @endif
                        @elseif($rel->independent_since)
                        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:6px;">Eigenständig seit {{ $rel->independent_since->lokal()->format('d.m.Y') }} – Beziehung bleibt bestehen.</div>
                        @endif
                    </div>
                </div>
                <div style="display:flex;gap:6px;align-items:center;margin-top:12px;flex-wrap:wrap;">
                    <form method="POST" action="{{ route('admin.customer.family.role', [$customer->id, $rel->id]) }}" style="display:flex;gap:6px;align-items:center;">
                        @csrf
                        <select name="relationship_type" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;">
                            @foreach(\App\Models\CustomerFamilyRelation::ROLES as $key => $label)
                            <option value="{{ $key }}" {{ $rel->relationship_type === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">Rolle ändern</button>
                    </form>
                    <form method="POST" action="{{ route('admin.customer.family.unlink', [$customer->id, $rel->id]) }}"
                          onsubmit="return confirm('Nur die Verknüpfung wird aufgehoben – die Kundenakte von „{{ addslashes($mitglied->user?->name ?? 'Kunde') }}“ bleibt mit allen Verträgen und Dokumenten bestehen. Fortfahren?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;color:#A32D2D;">Verknüpfung lösen</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    @endforeach
</div>

{{-- Suchdialog: bestehenden Kunden finden und verknuepfen. --}}
<div id="fam-suche-modal" style="display:none;position:fixed;inset:0;background:rgba(11,19,16,.55);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
    <div style="background:var(--surface,#FBFAF6);border-radius:14px;max-width:620px;width:100%;padding:22px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <div class="card-title" style="margin-bottom:0;">Bestehenden Kunden hinzufügen</div>
            <button type="button" onclick="document.getElementById('fam-suche-modal').style.display='none'" style="background:none;border:0;font-size:20px;cursor:pointer;color:var(--ink-soft);">×</button>
        </div>
        <p style="font-size:12px;color:var(--ink-soft);margin:0 0 14px;">
            Suche nach Vorname, Nachname, Kundennummer, Geburtsdatum (TT.MM.JJJJ), E-Mail, Telefon oder Anschrift.
            Der gefundene Kunde wird <strong>verknüpft, nicht kopiert</strong> – sein Datensatz bleibt unverändert.
        </p>
        <input type="text" id="fam-suche-feld" placeholder="z. B. Ebraheem, 2600610 oder 12.03.2012"
               oninput="famSucheStarten()" autocomplete="off"
               style="width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:9px;font-size:14px;">
        <div id="fam-suche-treffer" style="margin-top:12px;"></div>
    </div>
</div>

<form method="POST" id="fam-link-form" action="{{ route('admin.customer.family.link', $customer->id) }}" style="display:none;">
    @csrf
    <input type="hidden" name="related_customer_id" id="fam-link-id">
    <input type="hidden" name="relationship_type" id="fam-link-role">
</form>

<script>
const FAM_SUCHE_URL = @js(route('admin.customer.family.search', $customer->id));
const FAM_ROLLEN = @js(collect(\App\Models\CustomerFamilyRelation::SELECTABLE_ROLES)
    ->mapWithKeys(fn($r) => [$r => \App\Models\CustomerFamilyRelation::ROLES[$r]])->all());
let famSucheTimer = null;

function famSucheOeffnen() {
    document.getElementById('fam-suche-modal').style.display = 'flex';
    document.getElementById('fam-suche-feld').focus();
    famSucheStarten();
}

function famSucheStarten() {
    clearTimeout(famSucheTimer);
    famSucheTimer = setTimeout(famSucheLaden, 220);
}

function famSucheLaden() {
    const q = document.getElementById('fam-suche-feld').value;
    fetch(FAM_SUCHE_URL + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(d => famTrefferZeichnen(d.customers || []))
        .catch(() => famMeldung('Die Suche ist gerade nicht erreichbar.'));
}

function famMeldung(text) {
    const box = document.getElementById('fam-suche-treffer');
    box.textContent = '';
    const p = document.createElement('div');
    p.style.cssText = 'font-size:12.5px;color:var(--ink-soft);padding:10px 2px;';
    p.textContent = text;
    box.appendChild(p);
}

// Trefferliste wird per textContent gebaut, NIE per HTML-String: Kundennamen
// sind Fremddaten (gleiche Regel wie in der Vertrags-/Aufgaben-Suche).
function famTrefferZeichnen(kunden) {
    const box = document.getElementById('fam-suche-treffer');
    box.textContent = '';
    if (!kunden.length) {
        famMeldung('Kein passender Kunde gefunden. Der Kunde muss bereits als Kundenakte vorhanden sein.');
        return;
    }
    kunden.forEach(function (k) {
        const zeile = document.createElement('div');
        zeile.style.cssText = 'border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:8px;';

        const kopf = document.createElement('div');
        kopf.style.cssText = 'font-size:13.5px;font-weight:700;';
        kopf.textContent = k.name;
        zeile.appendChild(kopf);

        const meta = document.createElement('div');
        meta.style.cssText = 'font-size:12px;color:var(--ink-soft);margin-top:3px;';
        const teile = [k.number];
        if (k.birth_date) { teile.push('geb. ' + k.birth_date + (k.age !== null ? ' (' + k.age + ' J.)' : '')); }
        if (k.email) { teile.push(k.email); }
        if (k.phone) { teile.push(k.phone); }
        if (k.address) { teile.push(k.address); }
        if (k.status) { teile.push(k.status); }
        meta.textContent = teile.filter(Boolean).join(' · ');
        zeile.appendChild(meta);

        const knoepfe = document.createElement('div');
        knoepfe.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;';
        Object.keys(FAM_ROLLEN).forEach(function (rolle) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-ghost';
            btn.style.cssText = 'padding:5px 10px;font-size:12px;';
            btn.textContent = 'Als ' + FAM_ROLLEN[rolle] + ' hinzufügen';
            btn.onclick = function () { famVerknuepfen(k.id, rolle); };
            knoepfe.appendChild(btn);
        });
        zeile.appendChild(knoepfe);
        box.appendChild(zeile);
    });
}

function famVerknuepfen(id, rolle) {
    document.getElementById('fam-link-id').value = id;
    document.getElementById('fam-link-role').value = rolle;
    document.getElementById('fam-link-form').submit();
}
</script>
