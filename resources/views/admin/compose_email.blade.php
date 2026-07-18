@extends('layouts.admin')
@section('content')

<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        @if($customer)<a href="{{ route('admin.customer', $customer->id) }}">{{ $customer->user?->name }}</a><span class="breadcrumb-sep">›</span>@endif
        <span>E-Mail verfassen</span>
    </div>
    <div class="page-title">✉️ E-Mail verfassen</div>
    <div style="font-size:14px;color:var(--ink-soft);">Vorlage wählen, prüfen, senden – an Kunden oder Gesellschaften. Der Versand läuft über das Systempostfach.</div>
</div>

@if($customer)
<div class="card" style="margin-bottom:20px;padding:14px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:22px;">👤</span>
    <div>
        <div style="font-weight:700;">{{ $customer->user?->name }} <span style="font-weight:400;color:var(--ink-soft);">· Nr. {{ $customer->customer_number }}</span></div>
        <div style="font-size:12.5px;color:var(--ink-soft);">Kundenbezug aktiv: Platzhalter werden mit den Daten dieses Kunden gefüllt, der Versand wird in der Kundenakte protokolliert.</div>
    </div>
    <a href="{{ route('admin.email.compose') }}" class="btn btn-ghost btn-sm" style="margin-left:auto;">✕ Ohne Kundenbezug</a>
</div>
@endif

<div class="card" style="max-width:860px;">
    <form method="POST" action="{{ route('admin.email.compose.send') }}" enctype="multipart/form-data">
        @csrf
        @if($customer)<input type="hidden" name="customer_id" value="{{ $customer->id }}">@endif
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="field" style="margin-bottom:0;">
                <label>Empfänger (E-Mail) *</label>
                <input type="email" name="to" required maxlength="190" value="{{ old('to', $customer?->user?->email && !str_contains($customer->user->email, '@dienstly24.internal') ? $customer->user->email : '') }}" placeholder="kunde@example.de oder service@gesellschaft.de">
            </div>
            <div class="field" style="margin-bottom:0;">
                <label>📋 Vorlage einfügen</label>
                <select id="compose-template" onchange="applyComposeTemplate(this.value)">
                    <option value="">– Keine Vorlage –</option>
                    <optgroup label="👤 Kunden">
                        @foreach($templates->where('category', 'kunde') as $tpl)
                        <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="🏢 Gesellschaften / Anbieter">
                        @foreach($templates->where('category', 'gesellschaft') as $tpl)
                        <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                        @endforeach
                    </optgroup>
                </select>
            </div>
        </div>
        <div class="field" style="margin-top:14px;">
            <label>Betreff *</label>
            <input type="text" name="subject" id="compose-subject" required maxlength="200" value="{{ old('subject') }}">
        </div>
        <div class="field">
            <label>Nachricht *</label>
            <textarea name="body" id="compose-body" required maxlength="10000" rows="12" placeholder="Text schreiben oder Vorlage wählen – Platzhalter wie @{{anrede}} oder @{{name}} werden automatisch ersetzt{{ $customer ? '' : ' (Kundenplatzhalter nur mit Kundenbezug)' }}.">{{ old('body') }}</textarea>
        </div>
        <div class="field">
            <label>📎 Anhänge (optional, max. 5 · PDF/JPG/PNG/WEBP/DOC/DOCX, je max. 10 MB)</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn btn-gold">✉️ E-Mail senden</button>
            @if($customer)
            <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">← Zurück zur Kundenakte</a>
            @endif
        </div>
    </form>
</div>

<div class="card" style="max-width:860px;margin-top:16px;">
    <div class="card-title" style="margin-bottom:10px;">🧩 Platzhalter</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        @foreach($placeholders as $key => $desc)
        <span title="{{ $desc }}" onclick="insertPlaceholder('{{ $key }}')" style="font-size:12.5px;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:4px 12px;font-family:monospace;cursor:pointer;">&#123;&#123;{{ $key }}&#125;&#125;</span>
        @endforeach
    </div>
    <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">Klick fügt den Platzhalter an der Cursor-Position im Text ein. Beim Einsetzen einer Vorlage werden Platzhalter sofort ersetzt{{ $customer ? '' : ', sobald ein Kundenbezug besteht' }}.</p>
</div>

<script>
function applyComposeTemplate(id) {
    if (!id) return;
    fetch('{{ url('admin/vorlagen') }}/' + id + '/render{{ $customer ? '?customer_id=' . $customer->id : '' }}', {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => {
            if (d.subject) document.getElementById('compose-subject').value = d.subject;
            document.getElementById('compose-body').value = d.body || '';
        })
        .catch(() => {});
}
function insertPlaceholder(key) {
    const el = document.getElementById('compose-body');
    const token = '{' + '{' + key + '}' + '}';
    const start = el.selectionStart ?? el.value.length;
    el.value = el.value.slice(0, start) + token + el.value.slice(el.selectionEnd ?? start);
    el.focus();
    el.selectionStart = el.selectionEnd = start + token.length;
}
</script>

@endsection
