@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">{{ __('Dokumente') }}</div>
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
            <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.gif,.doc,.docx" style="font-size:13px;">
            <button type="submit" class="btn btn-gold" style="padding:7px 16px;font-size:13px;">{{ $req->status === 'rejected' ? 'Erneut hochladen' : 'Hochladen' }}</button>
        </form>
        @elseif($req->status === 'uploaded')
        <div style="font-size:13px;color:var(--ink-soft);margin-top:8px;">✓ Ihr Dokument ist eingegangen und wird von unserem Team geprüft.</div>
        @endif
    </div>
    @endforeach
</div>
@endif

<style>
.doc-folder{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:16px;overflow:hidden;}
.doc-folder>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;padding:16px 18px;font-weight:700;font-size:15px;}
.doc-folder>summary::-webkit-details-marker{display:none;}
.doc-folder>summary .fold-ico{font-size:26px;line-height:1;}
.doc-folder>summary .fold-sub{font-weight:500;font-size:12.5px;color:var(--ink-soft);}
.doc-folder>summary .fold-count{margin-left:auto;background:#EEF0F3;color:var(--ink-soft);font-size:12px;font-weight:600;padding:2px 10px;border-radius:20px;}
.doc-folder>summary .fold-chev{transition:transform .15s;color:var(--ink-soft);}
.doc-folder[open]>summary .fold-chev{transform:rotate(90deg);}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;padding:0 18px 18px;}
.doc-tile{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .15s,transform .15s;}
.doc-tile:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-2px);}
.doc-thumb{display:flex;align-items:center;justify-content:center;height:126px;background:#F4F5F7;position:relative;overflow:hidden;text-decoration:none;}
.doc-thumb img{width:100%;height:100%;object-fit:cover;}
.doc-thumb .doc-emoji{font-size:50px;line-height:1;}
.doc-thumb .doc-ext{position:absolute;bottom:8px;right:8px;font-size:10px;font-weight:700;letter-spacing:.03em;background:#17191d;color:#fff;padding:2px 7px;border-radius:6px;text-transform:uppercase;}
.doc-body{padding:11px 13px;display:flex;flex-direction:column;gap:7px;flex:1;}
.doc-name{font-weight:600;font-size:13px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;}
.doc-tags{display:flex;flex-wrap:wrap;gap:5px;font-size:11px;}
.doc-tag{padding:1px 7px;border-radius:5px;background:#EEF0F3;color:var(--ink-soft);}
.doc-tag-you{background:#EAF2FB;color:#185FA5;}
.doc-date{font-size:12px;color:var(--ink-soft);margin-top:auto;}
.doc-actions{display:flex;border-top:1px solid var(--line);}
.doc-actions a{flex:1;text-align:center;padding:10px 4px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--ink);}
.doc-actions a:hover{background:#F4F5F7;}
.doc-actions a.view{color:#128a4b;border-right:1px solid var(--line);}
</style>

@php
    // Nach Vertrag gruppieren: jeder Vertrag ist ein eigener "Ordner",
    // nicht zugeordnete Dokumente kommen zuletzt.
    $groups = $documents->groupBy(fn($d) => $d->contract_id ?: '');
    $withContract = $groups->filter(fn($v, $k) => $k !== '');
    $withoutContract = $groups->get('', collect());
@endphp

@if($documents->isEmpty())
<div class="card"><p style="color:var(--ink-soft);font-size:14px;padding:12px 0;text-align:center;">📂 Noch keine Dokumente vorhanden. Laden Sie oben Ihr erstes Dokument hoch.</p></div>
@else

    {{-- Ein Ordner je Vertrag --}}
    @foreach($withContract as $docs)
    @php $contract = $docs->first()->contract; @endphp
    <details class="doc-folder" open>
        <summary>
            <span class="fold-ico">{{ $contract?->typeIcon() ?? '📁' }}</span>
            <span>
                {{ $contract?->typeLabel() ?? 'Vertrag' }}
                <span class="fold-sub">{{ $contract?->insurer }}@if($contract?->contract_number) · {{ $contract->contract_number }}@endif</span>
            </span>
            <span class="fold-count">{{ $docs->count() }}</span>
            <span class="fold-chev">▸</span>
        </summary>
        <div class="doc-grid">
            @foreach($docs as $d)@include('portal.partials.document_tile')@endforeach
        </div>
    </details>
    @endforeach

    {{-- Ordner fuer Dokumente ohne Vertragszuordnung --}}
    @if($withoutContract->isNotEmpty())
    <details class="doc-folder" open>
        <summary>
            <span class="fold-ico">📁</span>
            <span>Weitere Dokumente <span class="fold-sub">ohne Vertragszuordnung</span></span>
            <span class="fold-count">{{ $withoutContract->count() }}</span>
            <span class="fold-chev">▸</span>
        </summary>
        <div class="doc-grid">
            @foreach($withoutContract as $d)@include('portal.partials.document_tile')@endforeach
        </div>
    </details>
    @endif
@endif

<div id="upload-doc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:460px;position:relative;">
        <button onclick="document.getElementById('upload-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Dokument hochladen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">PDF, Bild (JPG, PNG, HEIC, WEBP), DOC oder XLS – max. 10 MB. Unser Team wird über Ihren Upload informiert.</p>
        <form method="POST" action="{{ route('portal.documents.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="field"><label>Kategorie *</label>
                <select name="category" required>
                    @foreach(\App\Models\Document::CATEGORIES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($contracts->isNotEmpty())
            <div class="field"><label>Zu welchem Vertrag gehört das Dokument? (optional)</label>
                <select name="contract_id">
                    <option value="">— Keinem Vertrag zuordnen —</option>
                    @foreach($contracts as $c)
                    <option value="{{ $c->id }}">{{ $c->typeIcon() }} {{ $c->typeLabel() }} · {{ $c->insurer }}@if($c->contract_number) ({{ $c->contract_number }})@endif</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="field"><label>Datei *</label><input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.gif,.doc,.docx,.xls,.xlsx"></div>
            @error('document')<div class="alert-error">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-primary" style="width:100%;">{{ __('Hochladen') }}</button>
        </form>
    </div>
</div>
@endsection
