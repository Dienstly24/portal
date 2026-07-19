@php
    $ext = strtolower(pathinfo($d->file_name, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif']);
    $emoji = $ext === 'pdf' ? '📕'
        : (in_array($ext, ['doc','docx']) ? '📘'
        : (in_array($ext, ['xls','xlsx','csv']) ? '📗'
        : (in_array($ext, ['heic','heif']) ? '🖼️' : '📄')));
    // Vorschau ohne Download nur fuer inline anzeigbare Dateien (PDF/Bild).
    $viewable = $d->isViewable();
    $pvKind = $d->isImage() ? 'image' : ($d->isPdf() ? 'pdf' : 'other');
    $viewUrl = route('portal.documents.view', $d->id);
    $downloadUrl = route('portal.documents.download', $d->id);
    // Gemeinsame Vorschau-Attribute (Schnellvorschau beim Ueberfahren, grosses
    // Fenster bei Klick) - nur wenn die Datei ohne Download ansehbar ist.
    $previewAttrs = $viewable
        ? 'data-preview-open data-preview-url="' . e($viewUrl) . '" data-preview-name="' . e($d->file_name) . '" data-preview-kind="' . $pvKind . '" data-preview-download="' . e($downloadUrl) . '"'
        : '';
@endphp
<div class="doc-tile">
    <a href="{{ $viewUrl }}" target="_blank" rel="noopener" class="doc-thumb" title="{{ $d->file_name }}" {!! $previewAttrs !!}>
        @if($isImage)
            <img src="{{ $viewUrl }}" alt="{{ $d->file_name }}" loading="lazy">
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
            @if($d->uploaded_by === auth()->id())<span class="doc-tag doc-tag-you">von Ihnen</span>@endif
        </div>
        <div class="doc-date">{{ $d->created_at->format('d.m.Y') }}</div>
    </div>
    <div class="doc-actions">
        <a href="{{ $viewUrl }}" target="_blank" rel="noopener" class="view" {!! $previewAttrs !!}>👁 {{ __('Ansehen') }}</a>
        <a href="{{ $downloadUrl }}">⬇ {{ __('Herunterladen') }}</a>
    </div>
</div>
