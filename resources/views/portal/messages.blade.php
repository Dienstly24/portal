@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">💬 {{ __('Nachrichten') }}</div>
        <div class="page-sub">{{ __('Direkter Draht zu Ihrem Berater – Antworten Sie einfach hier im Portal.') }}</div>
    </div>
</div>

<div class="card">
    @forelse($messages as $m)
    @php $own = !$m->from_staff; @endphp
    <div style="margin-bottom:16px;padding:12px 16px;border-radius:10px;background:{{ $own ? 'var(--gold-soft)' : 'var(--canvas)' }};border:1px solid var(--line);">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">
            {{ $own ? __('Sie') : ($m->sender?->name ?? 'Dienstly24 Team') . ' · Dienstly24' }} · {{ $m->created_at->format('d.m.Y H:i') }}
        </div>
        <div style="font-size:14px;line-height:1.6;white-space:pre-line;">{{ $m->body }}</div>
        @if($m->attachments->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
            @foreach($m->attachments as $att)
            <div style="display:inline-flex;align-items:center;flex-wrap:wrap;gap:8px;font-size:12.5px;">
                <span style="display:inline-flex;align-items:center;gap:6px;color:var(--ink-soft);">
                    {{ $att->isImage() ? '🖼️' : ($att->isPdf() ? '📄' : '📎') }} {{ \Illuminate\Support\Str::limit($att->file_name, 32) }}
                </span>
                @if($att->isViewable())
                <a href="{{ route('portal.messages.attachment.view', $att->id) }}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:4px 11px;text-decoration:none;color:var(--ink);">👁 {{ __('Anzeigen') }}</a>
                @endif
                <a href="{{ route('portal.messages.attachment', $att->id) }}" style="display:inline-flex;align-items:center;gap:5px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:4px 11px;text-decoration:none;color:var(--ink);">⬇ {{ __('Herunterladen') }}</a>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;text-align:center;padding:24px 0;">{{ __('Noch keine Nachrichten. Ihr Berater meldet sich hier bei Ihnen – Sie können ihm auch direkt schreiben.') }}</p>
    @endforelse
</div>

<div class="card">
    <div class="card-title">{{ __('Nachricht an Ihren Berater') }}</div>
    <form method="POST" action="{{ route('portal.messages.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <textarea name="body" required maxlength="5000" placeholder="{{ __('Ihre Nachricht...') }}"></textarea>
        </div>
        <div class="field">
            <label>📎 {{ __('Anhänge (optional, max. 5 · PDF/JPG/PNG/WEBP, je max. 10 MB)') }}</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
        </div>
        <button type="submit" class="btn btn-primary">{{ __('Senden') }}</button>
    </form>
</div>
@endsection
