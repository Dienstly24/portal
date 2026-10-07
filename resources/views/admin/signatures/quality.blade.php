@extends('layouts.admin')
@section('content')
@php use App\Support\SignatureStatus; @endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span><span>Qualität</span></div>
    <h1 class="page-title">Signatur-Qualität</h1>
    <div class="page-sub">
        Vorgänge, deren Dokument die Qualitätsprüfung nicht bestanden hat. Geprüft wird beim Versand, beim Abschluss,
        beim Neu-Erzeugen und jede Nacht über den gesamten Bestand – am gerenderten Bild, nicht am PDF-Text.
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="card" style="padding:14px 16px;margin-bottom:16px;display:flex;gap:24px;flex-wrap:wrap;">
    <div><div class="muted-sm">Betroffen</div><strong style="font-size:20px;">{{ $gesamt }}</strong></div>
    <div><div class="muted-sm">Geprüfte Vorgänge</div><strong style="font-size:20px;">{{ $geprueft }}</strong></div>
    <div><div class="muted-sm">Letzte Prüfung</div><strong>{{ $letzterLauf ? \Illuminate\Support\Carbon::parse($letzterLauf)->lokal()->format('d.m.Y H:i') : '–' }}</strong></div>
</div>

@if($betroffene->isEmpty())
<div class="card" style="padding:18px;">✓ Kein Vorgang mit Befund. Alle geprüften Dokumente zeigen jede gesetzte Unterschrift.</div>
@else
@if($gesamt > $betroffene->count())
<p class="muted-sm">Angezeigt werden die {{ $betroffene->count() }} zuletzt geprüften von {{ $gesamt }} Vorgängen.</p>
@endif
<div class="card" style="padding:0;overflow-x:auto;">
<table class="table" style="width:100%;">
    <thead><tr><th>Dokument</th><th>Status</th><th>Befund</th><th>Geprüft</th><th></th></tr></thead>
    <tbody>
    @foreach($betroffene as $r)
    <tr>
        <td><a href="{{ route('admin.signatures.show', $r->id) }}">{{ $r->title }}</a>
            <div class="muted-sm">{{ $r->creator->name ?? '–' }}</div></td>
        <td><span class="badge {{ SignatureStatus::tone($r->status) }}">{{ SignatureStatus::label($r->status) }}</span></td>
        <td style="max-width:420px;">
            @foreach(array_slice($r->quality_findings ?? [], 0, 4) as $befund)
            <div style="font-size:13px;">• {{ $befund }}</div>
            @endforeach
            @if(empty($r->quality_findings) && $r->status === SignatureStatus::COMPLETION_FAILED)
            <div style="font-size:13px;">• Fertigstellung gescheitert – Details im Protokoll.</div>
            @endif
        </td>
        <td class="muted-sm">{{ $r->quality_checked_at ? $r->quality_checked_at->lokal()->format('d.m.Y H:i') : '–' }}
            @if($r->render_ms !== null)<div>{{ $r->render_ms }} ms</div>@endif</td>
        <td style="white-space:nowrap;">
            @if($r->status === SignatureStatus::COMPLETION_FAILED || $r->isCompleted())
            <form method="POST" action="{{ route('admin.signatures.quality.regenerate', $r->id) }}" style="display:inline;">
                @csrf<button class="btn btn-sm btn-emerald" type="submit">Neu erzeugen</button>
            </form>
            @else
            <a class="btn btn-sm btn-ghost" href="{{ route('admin.signatures.show', $r->id) }}">Vorgang öffnen</a>
            @endif
            <form method="POST" action="{{ route('admin.signatures.quality.check', $r->id) }}" style="display:inline;">
                @csrf<button class="btn btn-sm btn-ghost" type="submit">Jetzt prüfen</button>
            </form>
        </td>
    </tr>
    @endforeach
    </tbody>
</table>
</div>
@endif
@endsection
