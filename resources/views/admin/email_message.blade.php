@extends('layouts.admin')
@section('content')
@php
$statusBadge = [
    'confirmed' => ['#D9F4E6', '#17A65B', 'Zugeordnet'],
    'suggested' => ['#FEF3C7', '#92400E', 'Vorschlag – Bestätigung offen'],
    'unmatched' => ['#F9E3E3', '#A32D2D', 'Nicht zugeordnet'],
];
$sb = $statusBadge[$message->match_status] ?? ['#EEF0F3', '#555', $message->match_status];
@endphp
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.email_inbox') }}">E-Mail-Posteingang</a><span class="breadcrumb-sep">›</span>
        <span>E-Mail</span>
    </div>
    <div class="page-title">{{ $message->subject ?: '(kein Betreff)' }}</div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
    {{-- Linke Spalte: die eigentliche E-Mail --}}
    <div>
        <div class="card" style="padding:20px;margin-bottom:20px;">
            <table style="font-size:13px;color:var(--ink-soft);line-height:1.9;">
                <tr><td style="padding-right:14px;vertical-align:top;">Von</td><td style="color:var(--ink);"><strong>{{ $message->from_name ?: $message->from_address }}</strong> &lt;{{ $message->from_address }}&gt;</td></tr>
                @if($message->to_address)<tr><td style="padding-right:14px;">An</td><td style="color:var(--ink);">{{ $message->to_address }}</td></tr>@endif
                <tr><td style="padding-right:14px;">Datum</td><td style="color:var(--ink);">{{ ($message->received_at ?? $message->created_at)?->format('d.m.Y H:i') }}</td></tr>
                <tr><td style="padding-right:14px;">Postfach</td><td style="color:var(--ink);">{{ $message->account?->email_address ?? '—' }}</td></tr>
                <tr><td style="padding-right:14px;">Kategorie</td><td><span class="badge badge-pending">{{ $message->categoryLabel() }}</span></td></tr>
            </table>
        </div>

        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:14px 20px;font-weight:700;border-bottom:1px solid var(--line);">Inhalt</div>
            <div style="padding:20px;">
                @if($message->body_text)
                    <div style="white-space:pre-wrap;font-size:13.5px;line-height:1.65;color:var(--ink);">{{ $message->body_text }}</div>
                @elseif($message->body_html)
                    {{-- Reine Textdarstellung: HTML-Mails werden entschaerft angezeigt (kein aktives Markup). --}}
                    <div style="white-space:pre-wrap;font-size:13.5px;line-height:1.65;color:var(--ink);">{{ trim(preg_replace('/\n{3,}/', "\n\n", strip_tags(str_ireplace(['<br>','<br/>','<br />','</p>','</div>','</tr>'], "\n", $message->body_html)))) }}</div>
                @else
                    <div style="color:var(--ink-soft);font-style:italic;">Kein Textinhalt – siehe Anhänge.</div>
                @endif
            </div>
        </div>

        @php $attachments = $message->attachments_meta ?? []; @endphp
        @if(count($attachments))
        <div class="card" style="padding:0;overflow:hidden;margin-top:20px;">
            <div style="padding:14px 20px;font-weight:700;border-bottom:1px solid var(--line);">Anhänge ({{ count($attachments) }})</div>
            @foreach($attachments as $i => $att)
            <div style="padding:12px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div style="min-width:0;">
                    <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;">📎 {{ $att['filename'] ?? 'Anhang' }}</div>
                    <div style="font-size:11.5px;color:var(--ink-soft);">{{ $att['mime'] ?? '' }} · {{ isset($att['size']) ? round(($att['size'])/1024) . ' KB' : '' }}</div>
                </div>
                <a href="{{ route('admin.email_inbox.attachment', [$message->id, $i]) }}" class="btn btn-ghost btn-sm">Herunterladen</a>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Rechte Spalte: Status, Zuordnung, verknüpfte Aufgaben --}}
    <div>
        <div class="card" style="padding:18px 20px;margin-bottom:18px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">Verarbeitungsstatus</div>
            <span style="background:{{ $sb[0] }};color:{{ $sb[1] }};font-size:12px;padding:4px 12px;border-radius:999px;font-weight:600;">{{ $sb[2] }}</span>

            @if($message->customer)
            <div style="margin-top:14px;font-size:13px;">
                <div style="color:var(--ink-soft);margin-bottom:2px;">Kunde</div>
                <a href="{{ route('admin.customer', $message->customer_id) }}" style="font-weight:600;">{{ $message->customer->user?->name }}</a>
                <span style="color:var(--ink-soft);">({{ $message->customer->customer_number }})</span>
                @if($message->match_score !== null)<div style="color:var(--ink-soft);font-size:12px;margin-top:2px;">Übereinstimmung {{ $message->match_score }}%</div>@endif
            </div>
            @endif

            {{-- Aktionen je nach Status --}}
            @if($message->match_status === 'suggested' && $message->customer)
            <div style="display:flex;gap:8px;margin-top:16px;">
                <form method="POST" action="{{ route('admin.email_inbox.confirm', $message->id) }}">@csrf<button type="submit" class="btn btn-gold btn-sm">✓ Bestätigen</button></form>
                <form method="POST" action="{{ route('admin.email_inbox.reject', $message->id) }}">@csrf<button type="submit" class="btn btn-ghost btn-sm">✕ Anderer Kunde</button></form>
            </div>
            @elseif($message->customer_id === null)
            <form method="POST" action="{{ route('admin.email_inbox.assign', $message->id) }}" class="assign-form" style="margin-top:16px;position:relative;">
                @csrf
                <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">Manuell zuordnen</div>
                <input type="hidden" name="customer_id" class="assign-customer-id">
                <input type="text" class="assign-search" placeholder="Kunde suchen (Name/Nr.)…" autocomplete="off"
                    style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;width:100%;font-size:13px;box-sizing:border-box;">
                <div class="assign-results" style="display:none;position:absolute;top:60px;left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:50;max-height:240px;overflow-y:auto;"></div>
                <button type="submit" class="btn btn-gold btn-sm" style="margin-top:10px;width:100%;" disabled>Zuordnen</button>
            </form>
            @endif
        </div>

        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:14px 20px;font-weight:700;border-bottom:1px solid var(--line);">Verknüpfte Aufgaben ({{ $tasks->count() }})</div>
            @forelse($tasks as $t)
            @php $taskTab = $t->status === 'done' ? 'done' : ($t->customer_id ? 'customer' : 'mine'); @endphp
            <a href="{{ route('admin.tasks', ['tab' => $taskTab]) }}#task-{{ $t->id }}" class="row-link" title="Aufgabe öffnen" style="display:block;padding:12px 20px;border-bottom:1px solid var(--line);font-size:13px;color:inherit;text-decoration:none;">
                <div style="font-weight:600;">{{ $t->title }} <span style="color:var(--ink-soft);font-weight:400;">→</span></div>
                <div style="color:var(--ink-soft);font-size:12px;margin-top:2px;">
                    {{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','done'=>'Erledigt'][$t->status] ?? $t->status }}
                    @if($t->assignedTo) · {{ $t->assignedTo->name }}@endif
                </div>
            </a>
            @empty
            <div style="padding:16px 20px;color:var(--ink-soft);font-size:13px;">Keine verknüpften Aufgaben.</div>
            @endforelse
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.assign-form').forEach(form => {
    const search = form.querySelector('.assign-search');
    const results = form.querySelector('.assign-results');
    const hidden = form.querySelector('.assign-customer-id');
    const submit = form.querySelector('button[type=submit]');
    let timer = null;
    search.addEventListener('input', () => {
        hidden.value = ''; submit.disabled = true;
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
