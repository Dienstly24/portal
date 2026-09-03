@extends('layouts.admin')
@section('content')
@php
$customer = $changeRequest->customer;
$proof = $changeRequest->proofState();
@endphp

<div class="page-header">
    <div class="page-title">📨 Mitteilungen an Gesellschaften</div>
    <div class="page-sub">
        {{ $changeRequest->typeLabel() }} von
        <a href="{{ route('admin.customer', $changeRequest->customer_id) }}" style="color:var(--petrol);font-weight:600;">{{ $customer?->user?->name ?? '—' }}</a>
        @if($changeRequest->effective_from) · gültig ab {{ $changeRequest->effective_from->format('d.m.Y') }} @endif
        · Jede Gesellschaft bekommt einen fertigen Text; Sie prüfen, ergänzen die Adresse und senden.
    </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
    <a href="{{ route('admin.change_requests', ['status' => 'approved']) }}" class="btn btn-ghost" style="font-size:13px;">← Zurück zu den Änderungen</a>
    <a href="{{ route('admin.customer_chat', ['kunde' => $changeRequest->customer_id]) }}" class="btn btn-ghost" style="font-size:13px;">💬 Chat mit Kunde</a>
</div>

@if($changeRequest->documents->isNotEmpty())
<div class="card">
    <div class="card-title">📎 Nachweise des Kunden <span style="font-size:12px;font-weight:400;color:{{ $proof['color'] }};">{{ $proof['icon'] }} {{ $proof['label'] }}</span></div>
    @foreach($changeRequest->documents as $doc)
    <div style="font-size:13px;padding:3px 0;">
        📄 <a href="{{ route('admin.change_requests.proof', $doc->id) }}" target="_blank" rel="noopener" style="color:var(--petrol);font-weight:600;">{{ $doc->kindLabel() }}</a>
        <span style="color:var(--ink-soft);">{{ $doc->file_name }}</span>
    </div>
    @endforeach
    <p style="font-size:12px;color:var(--ink-soft);margin-top:8px;">Beim Versand können die Nachweise als Anhang mitgehen – prüfen Sie vorher, ob die Gesellschaft den Beleg wirklich benötigt (Datensparsamkeit).</p>
</div>
@endif

@forelse($notifications as $n)
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <div>
            <span style="font-size:15px;font-weight:700;">{{ $n->insurer }}</span>
            @if($n->contract_numbers)<span style="font-size:12.5px;color:var(--ink-soft);"> · Vertrag {{ $n->contract_numbers }}</span>@endif
        </div>
        <div>
            @if($n->status === 'sent')
                <span class="badge badge-active">✓ Gesendet {{ $n->sent_at?->lokal()->format('d.m.Y H:i') }}@if($n->sender) · {{ $n->sender->name }}@endif</span>
            @elseif($n->status === 'skipped')
                <span class="badge" style="background:#EFEAD8;color:#7A6A34;">Erledigt{{ $n->channel ? ' · ' . (\App\Models\ChangeNotification::CHANNEL_LABELS[$n->channel] ?? $n->channel) : '' }}</span>
            @else
                <span class="badge badge-pending">Offen</span>
            @endif
        </div>
    </div>

    @if($n->status === 'sent')
        <div style="font-size:13px;color:var(--ink-soft);margin-bottom:6px;">An: {{ $n->recipient }}</div>
        <div style="font-size:13.5px;font-weight:600;margin-bottom:6px;">{{ $n->subject }}</div>
        <pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;color:var(--ink-soft);background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:12px;">{{ $n->body }}</pre>
    @else
    <form method="POST" action="{{ route('admin.change_notifications.send', $n->id) }}">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label style="font-size:12px;">E-Mail der Gesellschaft *</label>
                <input type="email" name="recipient" value="{{ $n->recipient }}" placeholder="service@gesellschaft.de" maxlength="190" style="width:100%;">
            </div>
            <div class="field">
                <label style="font-size:12px;">Betreff *</label>
                <input type="text" name="subject" value="{{ $n->subject }}" required maxlength="190" style="width:100%;">
            </div>
        </div>
        <div class="field">
            <label style="font-size:12px;">Text *</label>
            <textarea name="body" required maxlength="10000" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-height:220px;font-family:inherit;resize:vertical;">{{ $n->body }}</textarea>
        </div>
        @if($changeRequest->documents->isNotEmpty())
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:12px;">
            <input type="checkbox" name="attach_proof" value="1" checked> Nachweis(e) des Kunden anhängen ({{ $changeRequest->documents->count() }})
        </label>
        @endif
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary" data-h-click="d72a4421d0">📤 Senden</button>
            <button type="submit" formaction="{{ route('admin.change_notifications.update', $n->id) }}" class="btn btn-ghost">💾 Entwurf speichern</button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.change_notifications.skip', $n->id) }}" style="margin-top:12px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
        @csrf
        <div class="field" style="margin:0;">
            <label style="font-size:12px;">Anders erledigt</label>
            <select name="channel" style="font-size:13px;">
                <option value="">— nicht nötig —</option>
                <option value="post">Per Post gesendet</option>
                <option value="portal">Im Portal der Gesellschaft erfasst</option>
            </select>
        </div>
        <div class="field" style="margin:0;flex:1;min-width:200px;">
            <label style="font-size:12px;">Notiz (optional)</label>
            <input type="text" name="note" maxlength="500" style="width:100%;">
        </div>
        <button type="submit" class="btn btn-ghost" style="font-size:12.5px;">✓ Als erledigt markieren</button>
    </form>
    @endif
</div>
@empty
<div class="card">
    <p style="color:var(--ink-soft);font-size:14px;">
        Für diesen Kunden sind keine laufenden Verträge hinterlegt – es wurden keine Mitteilungen vorbereitet.
    </p>
</div>
@endforelse
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["d72a4421d0"] = function (event) { return confirm('Mitteilung jetzt an die Gesellschaft senden?'); };
</script>
@endPushOnce
