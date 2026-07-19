@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>E-Mail-Posteingang</span></div>
    <div>
        <div class="page-title">E-Mail-Posteingang</div>
        <div class="page-sub">Automatisch verarbeitete E-Mails: Zuordnungen bestätigen und offene Fälle zuweisen.</div>
    </div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

{{-- Weitere Freigabe-Warteschlangen im Blick (Abschnitt 11: EIN Arbeitsvorrat) --}}
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;">
    <a href="{{ route('admin.commissions') }}" class="card" style="padding:16px 20px;text-decoration:none;display:flex;justify-content:space-between;align-items:center;">
        <div><div style="font-size:13px;color:var(--ink-soft);">Provisionen zu prüfen</div><div style="font-size:22px;font-weight:700;">{{ $queues['commissions'] }}</div></div><div style="font-size:22px;">💶</div>
    </a>
    <a href="{{ route('admin.document_requests') }}" class="card" style="padding:16px 20px;text-decoration:none;display:flex;justify-content:space-between;align-items:center;">
        <div><div style="font-size:13px;color:var(--ink-soft);">Dokument-Uploads zu prüfen</div><div style="font-size:22px;font-weight:700;">{{ $queues['document_requests'] }}</div></div><div style="font-size:22px;">📄</div>
    </a>
    <a href="{{ route('admin.change_requests') }}" class="card" style="padding:16px 20px;text-decoration:none;display:flex;justify-content:space-between;align-items:center;">
        <div><div style="font-size:13px;color:var(--ink-soft);">Kundenänderungen offen</div><div style="font-size:22px;font-weight:700;">{{ $queues['change_requests'] }}</div></div><div style="font-size:22px;">✏️</div>
    </a>
</div>

{{-- Stufe 70-90%: Ein-Klick-Bestätigung (Architekturplan Abschnitt 13) --}}
<div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Zuordnung bestätigen ({{ $suggested->count() }})</div>
    @forelse($suggested as $m)
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;">
        <div style="min-width:260px;flex:1;">
            <div style="font-weight:600;font-size:14px;"><a href="{{ route('admin.email_inbox.show', $m->id) }}">{{ $m->subject ?: '(kein Betreff)' }}</a></div>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;">
                von {{ $m->from_name ?: $m->from_address }} · {{ $m->received_at?->format('d.m.Y H:i') ?? $m->created_at->format('d.m.Y H:i') }}
                · <span class="badge badge-pending">{{ $m->categoryLabel() }}</span>
            </div>
            <div style="font-size:13px;margin-top:6px;">
                Vorschlag: <a href="{{ route('admin.customer', $m->customer_id) }}" style="font-weight:600;">{{ $m->customer?->user?->name }}</a>
                <span style="color:var(--ink-soft);">({{ $m->customer?->customer_number }}, Übereinstimmung {{ $m->match_score }}%)</span>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <form method="POST" action="{{ route('admin.email_inbox.confirm', $m->id) }}">@csrf<button type="submit" class="btn btn-gold btn-sm">✓ Bestätigen</button></form>
            <form method="POST" action="{{ route('admin.email_inbox.reject', $m->id) }}">@csrf<button type="submit" class="btn btn-ghost btn-sm">✕ Anderer Kunde</button></form>
        </div>
    </div>
    @empty
    <div style="text-align:center;padding:28px;color:var(--ink-soft);">Keine Zuordnungen zu bestätigen.</div>
    @endforelse
</div>

{{-- Nicht zugeordnete Mails: manuelle Zuweisung mit Kunden-Suche --}}
<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Nicht zugeordnet ({{ $unmatched->count() }})</div>
    @forelse($unmatched as $m)
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);">
        <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;">
            <div style="min-width:260px;flex:1;">
                <div style="font-weight:600;font-size:14px;"><a href="{{ route('admin.email_inbox.show', $m->id) }}">{{ $m->subject ?: '(kein Betreff)' }}</a></div>
                <div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;">
                    von {{ $m->from_name ?: $m->from_address }} · {{ $m->received_at?->format('d.m.Y H:i') ?? $m->created_at->format('d.m.Y H:i') }}
                    · <span class="badge badge-pending">{{ $m->categoryLabel() }}</span>
                </div>
                @if($m->body_text)<div style="font-size:12.5px;color:var(--ink-soft);margin-top:4px;">{{ Str::limit($m->body_text, 140) }}</div>@endif
                @foreach($m->aiDecisions as $ai)
                <div style="margin-top:8px;padding:8px 12px;background:#F5F0FA;border:1px solid #E2D5F0;border-radius:8px;font-size:12.5px;">
                    🤖 <strong>KI-Vorschlag:</strong> {{ \App\Models\EmailMessage::CATEGORIES[$ai->output['category']] ?? $ai->output['category'] }}
                    ({{ $ai->confidence }}% sicher)
                    @if(!empty($ai->output['summary']))<span style="color:var(--ink-soft);"> – {{ $ai->output['summary'] }}</span>@endif
                    <span style="white-space:nowrap;margin-left:8px;">
                        <form method="POST" action="{{ route('admin.email_inbox.ai_accept', $ai->id) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-gold btn-sm" style="padding:3px 10px;font-size:11.5px;">Übernehmen</button></form>
                        <form method="POST" action="{{ route('admin.email_inbox.ai_reject', $ai->id) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 10px;font-size:11.5px;">Verwerfen</button></form>
                    </span>
                </div>
                @endforeach
            </div>
            <form method="POST" action="{{ route('admin.email_inbox.assign', $m->id) }}" class="assign-form" style="display:flex;gap:8px;align-items:center;position:relative;">
                @csrf
                <input type="hidden" name="customer_id" class="assign-customer-id">
                <input type="text" class="assign-search" placeholder="Kunde suchen (Name/Nr./Telefon)…" autocomplete="off"
                    style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;width:240px;font-size:13px;">
                <div class="assign-results" style="display:none;position:absolute;top:40px;left:0;width:280px;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:50;max-height:260px;overflow-y:auto;"></div>
                <button type="submit" class="btn btn-gold btn-sm" disabled>Zuordnen</button>
            </form>
        </div>
    </div>
    @empty
    <div style="text-align:center;padding:28px;color:var(--ink-soft);">Keine offenen E-Mails – alles zugeordnet.</div>
    @endforelse
</div>

<script>
// Kunden-Autocomplete über die BESTEHENDE Suche (employees.customer-search)
document.querySelectorAll('.assign-form').forEach(form => {
    const search = form.querySelector('.assign-search');
    const results = form.querySelector('.assign-results');
    const hidden = form.querySelector('.assign-customer-id');
    const submit = form.querySelector('button[type=submit]');
    let timer = null;

    search.addEventListener('input', () => {
        hidden.value = '';
        submit.disabled = true;
        clearTimeout(timer);
        const q = search.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch('{{ route('admin.employees.customer-search') }}?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                .then(r => r.json())
                .then(items => {
                    results.innerHTML = '';
                    if (!items.length) { results.style.display = 'none'; return; }
                    items.forEach(c => {
                        const div = document.createElement('div');
                        div.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--line);';
                        div.innerHTML = '<strong>' + (c.name ?? '—') + '</strong> <span style="color:var(--ink-soft);">(' + (c.number ?? '') + ')</span>';
                        div.onmouseover = () => div.style.background = '#F8F9FA';
                        div.onmouseout = () => div.style.background = '#fff';
                        div.onclick = () => {
                            hidden.value = c.id;
                            search.value = (c.name ?? '') + ' (' + (c.number ?? '') + ')';
                            results.style.display = 'none';
                            submit.disabled = false;
                        };
                        results.appendChild(div);
                    });
                    results.style.display = 'block';
                });
        }, 250);
    });

    document.addEventListener('click', e => { if (!form.contains(e.target)) results.style.display = 'none'; });
});
</script>
@endsection
