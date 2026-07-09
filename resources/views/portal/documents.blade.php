@extends('layouts.portal')
@section('content')
<div class="page-title">Dokumente</div>
<div class="page-sub">Alle Ihre Dokumente und Unterlagen.</div>
<div class="card">
    @forelse($documents as $d)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $d->file_name }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ ucfirst($d->category) }} · {{ $d->created_at->format('d.m.Y') }}</div>
        </div>
        <a href="{{ route('portal.documents.download', $d->id) }}" class="btn btn-ghost" style="padding:6px 12px;font-size:13px;">Herunterladen</a>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Noch keine Dokumente vorhanden.</p>
    @endforelse
</div>
@endsection
