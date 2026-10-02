{{--
    "Verknuepfte Kunden" (Auftrag 01.10.2026): alle Beziehungen dieser Akte
    aus customer_relationships - Art, Link zum anderen Kunden, Notiz,
    Aendern/Entfernen - und das Anlegen per Kundensuche.
    Erwartet: $customer, $verknuepft (Collection von CustomerRelationship mit
    ->unbestaetigt), nur Paare, deren andere Seite im Portfolio liegt.
--}}
@php $cid = (string) $customer->id; @endphp
<div class="card" id="verknuepfte-kunden">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <div class="card-title" style="margin-bottom:0;">🔗 Verknüpfte Kunden ({{ $verknuepft->count() }})</div>
        <a href="{{ route('admin.customers.relationships') }}" class="muted-2xs">alle Beziehungen →</a>
    </div>

    @forelse($verknuepft as $vk)
    @php
        $anderer = (string) $vk->customer_a_id === $cid ? $vk->customerB : $vk->customerA;
    @endphp
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:9px 0;{{ !$loop->first ? 'border-top:1px solid var(--line);' : '' }}flex-wrap:wrap;">
        <div style="min-width:0;flex:1;">
            <span style="font-size:11px;background:#EDE9FE;color:#5B21B6;border-radius:999px;padding:2px 8px;">{{ \App\Models\CustomerRelationship::typeEmoji($vk->type) }} {{ $vk->labelFor($cid) }}</span>
            @if($vk->unbestaetigt)
            <span style="font-size:11px;background:#FEF3C7;color:#92400E;border-radius:999px;padding:2px 8px;" title="Keine passende Rolle in der Registerkarte „Familie“">unbestätigt</span>
            @endif
            <a href="{{ route('admin.customer', $anderer->id) }}" style="font-size:13.5px;font-weight:600;color:var(--ink);text-decoration:none;margin-left:4px;">{{ $anderer->user?->name ?? 'Unbekannt' }}</a>
            <span class="muted-xs"> · {{ $anderer->customer_number }}</span>
            @if($vk->note)
            <div class="muted-xs" style="margin-top:3px;">📝 {{ $vk->note }}</div>
            @endif
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex:none;">
            @if($vk->unbestaetigt)
            <form method="POST" action="{{ route('admin.customers.relationships.confirm', $vk->id) }}" style="margin:0;"
                  data-confirm="„{{ \App\Models\CustomerRelationship::typeLabel($vk->type) }}“ bestätigen? Die passende Rolle wird in der Registerkarte „Familie“ beider Kunden eingetragen.">
                @csrf
                <button type="submit" class="btn btn-ghost" style="padding:6px 10px;font-size:12px;">✓ Bestätigen</button>
            </form>
            @endif
            @include('admin.partials.beziehung_festlegen', [
                'a' => $customer, 'b' => $anderer,
                'action' => route('admin.customers.relationships.type', $vk->id),
                'hidden' => [],
                'current' => $vk->type === 'not_duplicate' ? null : $vk->type,
                'parent' => $vk->parent_customer_id ?? \App\Models\CustomerRelationship::suggestParent($customer, $anderer),
                'suggested' => $vk->parent_customer_id === null,
                'note' => $vk->note,
                'label' => 'Ändern',
            ])
            <form method="POST" action="{{ route('admin.customers.relationships.delete', $vk->id) }}" style="margin:0;"
                  data-confirm="Beziehung entfernen? Beide Akten bleiben unverändert; eine Familienrolle wird ebenfalls entfernt. Das Paar kann wieder als mögliche Dublette erscheinen.">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost" style="padding:6px 10px;font-size:12px;" title="Beziehung entfernen" aria-label="Beziehung entfernen">✕</button>
            </form>
        </div>
    </div>
    @empty
    <div class="muted-sm" style="padding:4px 0 8px;">Noch keine verknüpften Kunden.</div>
    @endforelse

    <details style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px;" @if($errors->has('related_customer_id') || $errors->has('type') || $errors->has('parent') || $errors->has('note')) open @endif>
        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--ink);">➕ Kunden verknüpfen</summary>
        <form method="POST" action="{{ route('admin.customer.relationships.store', $customer->id) }}" data-beziehung-form data-verknuepfen-form style="margin-top:10px;">
            @csrf
            <input type="hidden" name="related_customer_id" value="{{ old('related_customer_id') }}" data-verknuepfen-id>
            <input type="search" class="eingabe" placeholder="Kunde suchen (Name oder Kundennummer) …" aria-label="Kunde suchen" autocomplete="off"
                   data-verknuepfen-suche data-url="{{ route('admin.customers.search') }}" data-exclude="{{ $customer->id }}" style="width:100%;font-size:13px;">
            <div data-verknuepfen-treffer style="margin-top:6px;"></div>
            <div data-verknuepfen-auswahl class="muted-sm" style="margin:6px 0;" hidden></div>

            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-top:8px;">Beziehung</label>
            <select name="type" class="eingabe" required style="width:100%;font-size:13px;" aria-label="Beziehung">
                @foreach(\App\Models\CustomerRelationship::RELATION_TYPES as $t)
                <option value="{{ $t }}" @selected(old('type') === $t)>{{ \App\Models\CustomerRelationship::typeEmoji($t) }} {{ $t === 'sonstiges' ? 'Sonstiges (mit Freitext)' : \App\Models\CustomerRelationship::typeLabel($t) }}</option>
                @endforeach
            </select>
            <fieldset data-beziehung-eltern hidden style="border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:8px 0 0;">
                <legend style="font-size:11.5px;color:var(--ink-soft);padding:0 4px;">Wer ist der Elternteil?</legend>
                <label style="display:block;font-size:13px;"><input type="radio" name="parent" value="self" @checked(old('parent') === 'self')> {{ $customer->user?->name ?? 'Dieser Kunde' }} (diese Akte)</label>
                <label style="display:block;font-size:13px;"><input type="radio" name="parent" value="other" @checked(old('parent') === 'other')> der ausgewählte Kunde</label>
            </fieldset>
            <div data-beziehung-notiz style="margin-top:8px;">
                <input type="text" name="note" value="{{ old('note') }}" maxlength="255" class="eingabe" placeholder="Notiz (bei „Sonstiges“ Pflicht)" aria-label="Notiz zur Beziehung" style="width:100%;font-size:13px;">
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:10px;padding:8px 14px;">Verknüpfen</button>
        </form>
    </details>
</div>

@include('admin.partials.beziehung_festlegen_script')

{{-- Sofort-Suche: Trefferliste per textContent (Kundennamen sind Fremddaten). --}}
@pushOnce('cspScripts')
<script @cspNonce>
(function () {
    var feld = document.querySelector('[data-verknuepfen-suche]');
    if (!feld) return;
    var form = feld.closest('[data-verknuepfen-form]');
    var liste = form.querySelector('[data-verknuepfen-treffer]');
    var auswahl = form.querySelector('[data-verknuepfen-auswahl]');
    var idFeld = form.querySelector('[data-verknuepfen-id]');
    var timer = null;
    feld.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var q = feld.value.trim();
            liste.textContent = '';
            if (q.length < 2) return;
            var url = feld.dataset.url + '?q=' + encodeURIComponent(q) + '&exclude=' + encodeURIComponent(feld.dataset.exclude);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    liste.textContent = '';
                    (data.customers || []).forEach(function (c) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'btn btn-ghost';
                        b.style.cssText = 'display:block;width:100%;text-align:left;padding:6px 10px;font-size:12.5px;margin-bottom:4px;';
                        b.textContent = c.name + ' · ' + (c.number || '—');
                        b.addEventListener('click', function () {
                            idFeld.value = c.id;
                            auswahl.hidden = false;
                            auswahl.textContent = 'Ausgewählt: ' + c.name + ' · ' + (c.number || '—');
                            liste.textContent = '';
                            feld.value = '';
                        });
                        liste.appendChild(b);
                    });
                    if (!data.customers || data.customers.length === 0) {
                        liste.textContent = 'Kein Kunde gefunden.';
                    }
                });
        }, 250);
    });
    form.addEventListener('submit', function (e) {
        if (!idFeld.value) { e.preventDefault(); alert('Bitte zuerst einen Kunden aus der Suche auswählen.'); }
    });
})();
</script>
@endPushOnce
