@extends('layouts.admin')
@section('content')
@php $signers = $signature->signers; @endphp
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <span>{{ Str::limit($signature->title, 50) }}</span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;">
        <div>
            <div class="page-title">{{ $signature->title }}</div>
            <div class="page-sub">
                <span class="badge {{ $signature->statusTone() }}">{{ $signature->statusLabel() }}</span>
                · {{ $signature->page_count }} Seite{{ $signature->page_count === 1 ? '' : 'n' }}
                · angelegt {{ $signature->created_at?->lokal()->format('d.m.Y H:i') }}
                @if($signature->creator) von {{ $signature->creator->name }}@endif
            </div>
        </div>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

@if($signature->isDraft() && $blockers)
<div style="background:#FFF6E5;color:#8A5D00;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <strong>Noch nicht versandfertig:</strong>
    <ul style="margin:6px 0 0;padding-left:18px;">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
</div>
@endif

<div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">
<div style="display:grid;gap:18px;">

    <div class="card" style="padding:0;">
        <div class="card-head-bar">Unterzeichner ({{ $signature->signedCount() }} von {{ $signers->count() }})</div>
        @forelse($signers as $signer)
        <div style="padding:14px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;">
            <div>
                <div style="font-weight:600;font-size:14px;">
                    {{ $signer->hasSigned() ? '✓' : ($signer->hasDeclined() ? '✕' : '⏳') }} {{ $signer->name }}
                </div>
                <div class="muted-sm">{{ $signer->email }} · Position {{ $signer->signing_order }}</div>
                <div class="muted-sm">
                    @if($signer->signed_at)
                        Unterschrieben {{ $signer->signed_at->lokal()->format('d.m.Y H:i') }} Uhr
                        @if($signer->ip_address) · IP {{ $signer->ip_address }}@endif
                    @elseif($signer->declined_at)
                        Abgelehnt {{ $signer->declined_at->lokal()->format('d.m.Y H:i') }} Uhr
                        @if($signer->decline_reason) – {{ $signer->decline_reason }}@endif
                    @elseif($signer->viewed_at)
                        Dokument geöffnet {{ $signer->viewed_at->lokal()->format('d.m.Y H:i') }} Uhr
                    @elseif($signer->invited_at)
                        Eingeladen {{ $signer->invited_at->lokal()->format('d.m.Y H:i') }} Uhr
                        @if($signer->reminder_count > 0) · {{ $signer->reminder_count }}× erinnert @endif
                    @else
                        Wartet auf den Versand
                    @endif
                </div>
            </div>
            <div style="text-align:right;">
                <span class="badge {{ $signer->hasSigned() ? 'badge-success' : ($signer->hasDeclined() ? 'badge-danger' : 'badge-pending') }}">
                    {{ $signer->statusLabel() }}
                </span>
                @if($signer->verified_at)
                <div class="muted-sm" style="margin-top:5px;">E-Mail bestätigt</div>
                @endif
            </div>
        </div>
        @empty
        <div style="padding:22px;text-align:center;color:var(--ink-soft);">Noch kein Unterzeichner erfasst.</div>
        @endforelse
    </div>

    @if($signature->isCompleted() && $signature->customer_id === null)
    {{-- Die Zuordnung NACH dem Unterschreiben - der Kern der Flexibilitaet
         dieses Moduls. Vorgeschlagen wird, entschieden wird von Hand: eine
         uebereinstimmende E-Mail-Adresse ist kein Identitaetsnachweis. --}}
    <div class="card" style="padding:0;border:2px solid var(--emerald);">
        <div class="card-head-bar">Dokument zuordnen</div>
        <div style="padding:18px 20px;display:grid;gap:16px;">
            <div class="muted-sm">
                Das unterschriebene Dokument liegt derzeit nur unter Signaturen. Ordnen Sie es zu, damit es in der
                Kundenakte und im Kundenportal erscheint.
            </div>

            @if($suggestions)
            <div>
                <div style="font-weight:600;font-size:13.5px;margin-bottom:8px;">Vorschläge</div>
                @foreach($suggestions as $suggestion)
                <form method="POST" action="{{ route('admin.signatures.assign', $signature->id) }}"
                      style="display:flex;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);">
                    @csrf
                    <input type="hidden" name="aktion" value="kunde">
                    <input type="hidden" name="customer_id" value="{{ $suggestion['customer']->id }}">
                    <div style="font-size:13.5px;">
                        <strong>{{ $suggestion['customer']->user?->name ?? $suggestion['customer']->company_name }}</strong>
                        <span class="muted-sm">({{ $suggestion['customer']->customer_number }})</span>
                        <div class="muted-sm">{{ $suggestion['grund'] }}</div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-emerald">Zuordnen</button>
                </form>
                @endforeach
            </div>
            @endif

            <form method="POST" action="{{ route('admin.signatures.assign', $signature->id) }}" style="display:grid;gap:9px;">
                @csrf
                <input type="hidden" name="aktion" value="kunde">
                <label for="kundensuche" style="font-size:13.5px;">Bestehendem Kunden zuordnen</label>
                <input id="kundensuche" type="search" autocomplete="off" placeholder="Name, Kundennummer oder E-Mail"
                       style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                <div id="kunden-treffer" style="display:grid;gap:4px;"></div>
                <input type="hidden" name="customer_id" id="kunden-id">
                <button type="submit" class="btn btn-sm btn-emerald" id="kunden-submit" disabled>Ausgewählten Kunden zuordnen</button>
            </form>

            <form method="POST" action="{{ route('admin.signatures.assign', $signature->id) }}"
                  data-confirm="Neuen Kunden aus den Angaben des Unterzeichners anlegen?">
                @csrf
                <input type="hidden" name="aktion" value="neuer_kunde">
                <input type="hidden" name="signer_id" value="{{ $signers->first()?->id }}">
                <button type="submit" class="btn btn-sm btn-ghost">Neuen Kunden erstellen</button>
                <span class="muted-sm">aus {{ $signers->first()?->name }} &lt;{{ $signers->first()?->email }}&gt;</span>
            </form>

            <form method="POST" action="{{ route('admin.signatures.assign', $signature->id) }}">
                @csrf
                <input type="hidden" name="aktion" value="keine">
                <button type="submit" class="btn btn-sm btn-ghost">Nicht zuordnen – nur als Dokument behalten</button>
            </form>
        </div>
    </div>
    @endif

    <div class="card" style="padding:0;">
        <div class="card-head-bar">Verlauf</div>
        <div style="padding:8px 20px 16px;">
            @forelse($events->take(30) as $event)
            <div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px;">
                <div class="muted-sm" style="min-width:132px;">{{ $event->created_at?->lokal()->format('d.m.Y H:i') }}</div>
                <div>
                    <strong>{{ $event->label() }}</strong>
                    @if($event->actor) <span class="muted-sm">— {{ $event->actor }}</span>@endif
                    @if($event->description)<div class="muted-sm">{{ $event->description }}</div>@endif
                </div>
            </div>
            @empty
            <div style="padding:16px;text-align:center;color:var(--ink-soft);">Noch keine Ereignisse.</div>
            @endforelse
            @if($events->count() > 30)
            <div style="margin-top:12px;"><a href="{{ route('admin.signatures.audit', $signature->id) }}">Vollständiges Audit-Protokoll ({{ $events->count() }} Einträge)</a></div>
            @endif
        </div>
    </div>
</div>

<div style="display:grid;gap:18px;position:sticky;top:14px;">
    <div class="card" style="padding:16px 18px;display:grid;gap:9px;">
        <div style="font-weight:600;">Aktionen</div>
        @if($signature->isDraft())
            <a href="{{ route('admin.signatures.prepare', $signature->id) }}" class="btn btn-sm btn-ghost">Felder bearbeiten</a>
            <form method="POST" action="{{ route('admin.signatures.send', $signature->id) }}"
                  data-confirm="Einladung jetzt an die Unterzeichner versenden?">
                @csrf
                <button type="submit" class="btn btn-emerald" style="width:100%;" @disabled((bool) $blockers)>Zur Unterschrift senden</button>
            </form>
        @endif

        @if($signature->acceptsSignatures())
            <form method="POST" action="{{ route('admin.signatures.remind', $signature->id) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-ghost" style="width:100%;">Erinnerung senden</button>
            </form>
        @endif

        @if($signature->isCompleted())
            <a href="{{ route('admin.signatures.download', [$signature->id, 'signed']) }}" class="btn btn-emerald">Unterschriebenes PDF</a>
        @endif
        <a href="{{ route('admin.signatures.download', [$signature->id, 'original']) }}" class="btn btn-sm btn-ghost">Original-PDF</a>
        <a href="{{ route('admin.signatures.audit', $signature->id) }}" class="btn btn-sm btn-ghost">Audit-Protokoll</a>

        @if(!$signature->isCompleted() && $signature->status !== 'cancelled')
        <form method="POST" action="{{ route('admin.signatures.cancel', $signature->id) }}"
              data-confirm="Signaturanfrage abbrechen? Die Links der Unterzeichner werden sofort ungültig."
              style="display:grid;gap:7px;">
            @csrf
            <input type="text" name="reason" maxlength="500" placeholder="Grund (optional)"
                   style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <button type="submit" class="btn btn-sm btn-ghost" style="color:#B3261E;">Abbrechen</button>
        </form>
        @endif
    </div>

    <div class="card" style="padding:16px 18px;display:grid;gap:7px;font-size:13px;">
        <div style="font-weight:600;">Angaben</div>
        <div><span class="muted-sm">Kunde:</span>
            @if($signature->customer)
                <a href="{{ route('admin.customer', $signature->customer_id) }}">{{ $signature->customer->user?->name }}</a>
            @else — @endif
        </div>
        <div><span class="muted-sm">Vertrag:</span>
            @if($signature->contract)
                <a href="{{ route('admin.contract.edit', $signature->contract_id) }}">{{ $signature->contract->contract_number ?: $signature->contract->insurer }}</a>
            @else — @endif
        </div>
        @if($signature->reference)<div><span class="muted-sm">Referenz:</span> {{ $signature->reference }}</div>@endif
        @if($signature->document_type)<div><span class="muted-sm">Dokumentart:</span> {{ $signature->document_type }}</div>@endif
        <div><span class="muted-sm">Reihenfolge:</span> {{ $signature->isSequential() ? 'Nacheinander' : 'Gleichzeitig' }}</div>
        <div><span class="muted-sm">E-Mail-Bestätigung:</span> {{ $signature->require_email_verification ? 'ja' : 'nein' }}</div>
        <div><span class="muted-sm">Ablauf:</span> {{ $signature->expires_at?->lokal()->format('d.m.Y') ?? 'ohne Frist' }}</div>
        @if($signature->completedDocument)
        <div><span class="muted-sm">In der Kundenakte:</span>
            <a href="{{ route('admin.documents.show', $signature->completed_document_id) }}">{{ $signature->completedDocument->file_name }}</a>
        </div>
        @endif
    </div>

    {{-- Die Pruefsummen sind der eigentliche Nachweis. Sie stehen sichtbar
         da, weil man sie im Streitfall braucht - nicht versteckt im Log. --}}
    <div class="card" style="padding:16px 18px;display:grid;gap:7px;font-size:12px;">
        <div style="font-weight:600;font-size:13px;">Prüfsummen (SHA-256)</div>
        <div><span class="muted-sm">Original:</span><br><span style="word-break:break-all;">{{ $signature->original_hash }}</span></div>
        @if($signature->signed_hash)
        <div><span class="muted-sm">Unterschrieben:</span><br><span style="word-break:break-all;">{{ $signature->signed_hash }}</span></div>
        @endif
        <div class="muted-sm">
            Das unterschriebene PDF ist eine Fortschreibung des Originals: dessen Bytes bleiben unverändert erhalten.
        </div>
    </div>
</div>
</div>

@if($signature->isCompleted() && $signature->customer_id === null)
{{-- Kundensuche: derselbe Endpunkt wie im Vertrags- und Zusammenfuehren-
     Formular. Die Trefferliste wird per textContent gebaut - Kundennamen
     sind Fremddaten und gehoeren nie als HTML in die Seite. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
(function () {
    var input = document.getElementById('kundensuche');
    if (!input) { return; }
    var list = document.getElementById('kunden-treffer');
    var hidden = document.getElementById('kunden-id');
    var submit = document.getElementById('kunden-submit');
    var timer = null;

    input.addEventListener('input', function () {
        hidden.value = '';
        submit.disabled = true;
        clearTimeout(timer);
        var term = input.value.trim();
        if (term.length < 2) { list.textContent = ''; return; }
        timer = setTimeout(function () {
            fetch(@json(route('admin.customers.search')) + '?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                list.textContent = '';
                (data.customers || []).slice(0, 8).forEach(function (hit) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-sm btn-ghost';
                    button.style.cssText = 'text-align:left;justify-content:flex-start;';
                    button.textContent = (hit.name || '—') + ' · ' + (hit.number || '')
                        + (hit.email ? ' · ' + hit.email : '');
                    button.addEventListener('click', function () {
                        hidden.value = hit.id;
                        input.value = hit.name || '';
                        list.textContent = '';
                        submit.disabled = false;
                    });
                    list.appendChild(button);
                });
            }).catch(function () { list.textContent = ''; });
        }, 220);
    });
})();
</script>
@endPushOnce
@endif
@endsection
