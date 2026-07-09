@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="page-title">💬 {{ $conversation->subject }}</div>
    <div class="page-sub">
        Teilnehmer: {{ $conversation->participants->map(fn($p) => $p->user?->name)->filter()->implode(', ') }}
    </div>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start;">
    <div class="card">
        <a href="{{ route('admin.chat.index') }}" class="btn btn-ghost" style="width:100%;margin-bottom:14px;">← Alle Unterhaltungen</a>
        @foreach($conversations as $c)
        <a href="{{ route('admin.chat.show', $c->id) }}" style="display:block;padding:12px;border:1px solid {{ $c->id === $conversation->id ? 'var(--petrol)' : 'var(--line)' }};border-radius:8px;margin-bottom:8px;text-decoration:none;color:var(--ink);{{ $c->id === $conversation->id ? 'background:var(--canvas);' : '' }}">
            <div style="font-weight:600;font-size:14px;">{{ $c->subject }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">{{ optional($c->last_message_at)->diffForHumans() ?? $c->created_at->diffForHumans() }}</div>
        </a>
        @endforeach
    </div>

    <div class="card">
        <div id="chat-scroll" style="max-height:520px;overflow-y:auto;padding:6px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:16px;">
            @foreach($conversation->messages->sortBy('created_at') as $m)
            @php $own = $m->sender_id === auth()->id(); @endphp
            <div style="display:flex;gap:10px;margin-bottom:14px;align-items:flex-end;{{ $own ? 'flex-direction:row-reverse;' : '' }}">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $own ? 'var(--gold)' : 'var(--petrol)' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex:none;">{{ strtoupper(mb_substr($m->sender?->name ?? '??', 0, 2)) }}</div>
                <div style="max-width:75%;">
                    <div style="font-size:11px;color:var(--ink-soft);margin-bottom:3px;{{ $own ? 'text-align:right;' : '' }}">{{ $m->sender?->name ?? 'Gelöschter Nutzer' }} · {{ $m->created_at->format('d.m.Y H:i') }}</div>
                    <div style="padding:10px 14px;border-radius:12px;font-size:13.5px;line-height:1.55;{{ $own ? 'background:var(--petrol);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;border:1px solid var(--line);border-bottom-left-radius:4px;' }}">{{ $m->body }}</div>
                </div>
            </div>
            @endforeach
        </div>
        <form method="POST" action="{{ route('admin.chat.reply', $conversation->id) }}" style="display:flex;gap:10px;margin-top:14px;align-items:flex-end;">
            @csrf
            <textarea name="body" required maxlength="5000" placeholder="Nachricht an das Team…" style="flex:1;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;min-height:52px;"></textarea>
            <button type="submit" class="btn btn-primary" style="height:44px;">Senden</button>
        </form>
    </div>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{const b=document.getElementById('chat-scroll');if(b)b.scrollTop=b.scrollHeight;});</script>
@endsection
