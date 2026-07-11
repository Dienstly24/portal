@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">Dokumente</div>
        <div class="page-sub" style="margin-bottom:0;">Alle Ihre Dokumente und Unterlagen. Sie können hier auch eigene Dokumente hochladen.</div>
    </div>
    <button onclick="document.getElementById('upload-doc-modal').style.display='flex'" class="btn btn-gold">+ Dokument hochladen</button>
</div>

@if(session('success'))<div style="background:#E4F0E7;color:#3B7A57;padding:10px 16px;border-radius:8px;margin:16px 0;">{{ session('success') }}</div>@endif
@if($errors->any())<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin:16px 0;">{{ $errors->first() }}</div>@endif

{{-- Angeforderte Dokumente (Architekturplan Abschnitt 14: klare Statusanzeige) --}}
@if($documentRequests->isNotEmpty())
<div class="card" style="margin-bottom:20px;">
    <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Angeforderte Dokumente</div>
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:12px;">Wir benötigen die folgenden Unterlagen von Ihnen. Nach dem Hochladen prüft unser Team Ihr Dokument.</div>
    @foreach($documentRequests as $req)
    <div style="border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin-bottom:10px;
        {{ $req->status === 'rejected' ? 'border-color:#E4B4B4;background:#FDF7F7;' : '' }}
        {{ $req->status === 'approved' ? 'opacity:.65;' : '' }}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <div style="min-width:220px;">
                <div style="font-weight:600;font-size:14px;">{{ $req->title }}</div>
                @if($req->description)<div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">{{ $req->description }}</div>@endif
                @if($req->contract)<div style="font-size:12px;color:var(--ink-soft);margin-top:2px;">Vertrag: {{ $req->contract->contract_number }} ({{ $req->contract->insurer }})</div>@endif
                @if($req->deadline && in_array($req->status, ['open','rejected']))
                    <div style="font-size:12px;color:{{ $req->deadline->isPast() ? '#B3261E' : 'var(--ink-soft)' }};margin-top:2px;">Frist: {{ $req->deadline->format('d.m.Y') }}</div>
                @endif
                @if($req->status === 'rejected' && $req->rejection_note)
                    <div style="font-size:13px;color:#B3261E;margin-top:6px;">Hinweis unseres Teams: {{ $req->rejection_note }}</div>
                @endif
            </div>
            <div style="text-align:right;">
                @if($req->status === 'open')<span class="badge" style="background:#FEF3C7;color:#92400E;">{{ $req->statusLabel() }}</span>
                @elseif($req->status === 'uploaded')<span class="badge" style="background:#E6F1FB;color:#185FA5;">{{ $req->statusLabel() }}</span>
                @elseif($req->status === 'approved')<span class="badge" style="background:#E4F0E7;color:#3B7A57;">{{ $req->statusLabel() }}</span>
                @else<span class="badge" style="background:#FBE9E9;color:#B3261E;">{{ $req->statusLabel() }}</span>@endif
            </div>
        </div>
        @if($req->acceptsUpload())
        <form method="POST" action="{{ route('portal.document_requests.upload', $req->id) }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;">
            @csrf
            <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="font-size:13px;">
            <button type="submit" class="btn btn-gold" style="padding:7px 16px;font-size:13px;">{{ $req->status === 'rejected' ? 'Erneut hochladen' : 'Hochladen' }}</button>
        </form>
        @elseif($req->status === 'uploaded')
        <div style="font-size:13px;color:var(--ink-soft);margin-top:8px;">✓ Ihr Dokument ist eingegangen und wird von unserem Team geprüft.</div>
        @endif
    </div>
    @endforeach
</div>
@endif

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
