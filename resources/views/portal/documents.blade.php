@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">Dokumente</div>
        <div class="page-sub" style="margin-bottom:0;">Alle Ihre Dokumente und Unterlagen. Sie können hier auch eigene Dokumente hochladen.</div>
    </div>
    <button onclick="document.getElementById('upload-doc-modal').style.display='flex'" class="btn btn-gold">+ Dokument hochladen</button>
</div>

<div class="card">
    @forelse($documents as $d)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">📄 {{ $d->file_name }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">
                {{ \App\Models\Document::CATEGORIES[$d->category] ?? ucfirst($d->category) }} · {{ $d->created_at->format('d.m.Y') }}
                @if($d->uploaded_by === auth()->id())<span style="font-size:11px;background:#EAF2FB;color:#185FA5;padding:1px 6px;border-radius:4px;">von Ihnen</span>@endif
            </div>
        </div>
        <a href="{{ route('portal.documents.download', $d->id) }}" class="btn btn-ghost" style="padding:6px 12px;font-size:13px;">Herunterladen</a>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Noch keine Dokumente vorhanden. Laden Sie Ihr erstes Dokument hoch.</p>
    @endforelse
</div>

<div id="upload-doc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:460px;position:relative;">
        <button onclick="document.getElementById('upload-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Dokument hochladen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">PDF, JPG, PNG, DOC oder XLS – max. 10 MB. Unser Team wird über Ihren Upload informiert.</p>
        <form method="POST" action="{{ route('portal.documents.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="field"><label>Kategorie *</label>
                <select name="category" required>
                    @foreach(\App\Models\Document::CATEGORIES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Datei *</label><input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></div>
            @error('document')<div class="alert-error">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-primary" style="width:100%;">Hochladen</button>
        </form>
    </div>
</div>
@endsection
