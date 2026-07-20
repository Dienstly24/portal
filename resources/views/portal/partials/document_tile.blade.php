@php
    $ext = strtolower(pathinfo($d->file_name, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif']);
    $emoji = $ext === 'pdf' ? '📕'
        : (in_array($ext, ['doc','docx']) ? '📘'
        : (in_array($ext, ['xls','xlsx','csv']) ? '📗'
        : (in_array($ext, ['heic','heif']) ? '🖼️' : '📄')));
@endphp
<div class="doc-tile">
    <a href="{{ route('portal.documents.view', $d->id) }}" target="_blank" rel="noopener" class="doc-thumb" title="{{ $d->file_name }}">
        @if($isImage)
            <img src="{{ route('portal.documents.view', $d->id) }}" alt="{{ $d->file_name }}" loading="lazy">
        @else
            <span class="doc-emoji">{{ $emoji }}</span>
        @endif
        @if($ext)<span class="doc-ext">{{ $ext }}</span>@endif
    </a>
    <div class="doc-body">
        <div class="doc-name">{{ $d->file_name }}</div>
        <div class="doc-tags">
            <span class="doc-tag">{{ \App\Models\Document::CATEGORIES[$d->category] ?? ucfirst($d->category) }}</span>
            @if($d->aiTypeLabel())<span class="doc-tag" style="background:#d9f4e6;color:#128a4b;">{{ __($d->aiTypeLabel()) }}</span>@endif
            @if($d->aiInProgress())<span class="doc-tag" style="background:#FEF3C7;color:#92400E;">{{ __('Wird analysiert…') }}</span>@endif
            @if($d->uploaded_by === auth()->id())<span class="doc-tag doc-tag-you">{{ __('von Ihnen') }}</span>@endif
        </div>
        <div class="doc-date">{{ $d->created_at->format('d.m.Y') }}</div>
    </div>
    <div class="doc-actions">
        <a href="{{ route('portal.documents.view', $d->id) }}" target="_blank" rel="noopener" class="view">👁 {{ __('Ansehen') }}</a>
        <a href="{{ route('portal.documents.download', $d->id) }}">⬇ {{ __('Herunterladen') }}</a>
    </div>
</div>
