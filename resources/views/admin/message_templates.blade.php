@extends('layouts.admin')
@section('content')

@php $canManage = in_array(auth()->user()->role, ['admin','manager']); @endphp

<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <span>Vorlagen</span>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div class="page-title">📋 Nachrichten- &amp; E-Mail-Vorlagen</div>
            <div style="font-size:14px;color:var(--ink-soft);">Einmal anlegen, überall mit einem Klick einsetzen – Platzhalter werden automatisch mit Kundendaten gefüllt.</div>
        </div>
        @if($canManage)
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <form method="POST" action="{{ route('admin.templates.seed') }}" style="margin:0;">
                @csrf<button type="submit" class="btn btn-ghost">✨ Standard-Vorlagen anlegen</button>
            </form>
            <button type="button" class="btn btn-gold" onclick="openTplModal()">+ Neue Vorlage</button>
        </div>
        @endif
    </div>
</div>

{{-- Platzhalter-Referenz --}}
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:10px;">🧩 Verfügbare Platzhalter</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        @foreach($placeholders as $key => $desc)
        <span title="{{ $desc }}" style="font-size:12.5px;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:4px 12px;font-family:monospace;cursor:help;">&#123;&#123;{{ $key }}&#125;&#125;</span>
        @endforeach
    </div>
    <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">Beispiel: „@{{anrede}}, vielen Dank für Ihre Anfrage…" wird beim Einsetzen zu „Sehr geehrter Herr Meyer, vielen Dank für Ihre Anfrage…".</p>
</div>

@foreach(\App\Models\MessageTemplate::CATEGORIES as $catKey => $catLabel)
@php $catTemplates = $templates->where('category', $catKey); @endphp
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:14px;">{{ $catKey === 'kunde' ? '👤 Vorlagen für Kunden' : '🏢 Vorlagen für Gesellschaften / Anbieter' }} <span style="opacity:.6;">({{ $catTemplates->count() }})</span></div>
    @forelse($catTemplates as $tpl)
    <div style="padding:12px 0;border-bottom:1px solid var(--line);display:flex;gap:14px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">
        <div style="flex:1;min-width:260px;">
            <div style="font-weight:600;font-size:14px;">{{ $tpl->name }}</div>
            @if($tpl->subject)<div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;">Betreff: {{ $tpl->subject }}</div>@endif
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:4px;white-space:pre-line;">{{ \Illuminate\Support\Str::limit($tpl->body, 180) }}</div>
        </div>
        @if($canManage)
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-ghost btn-sm"
                onclick='openTplModal(@json($tpl->only(["id","name","category","subject","body","sort"]), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP))'>✏️ Bearbeiten</button>
            <form method="POST" action="{{ route('admin.templates.destroy', $tpl->id) }}" onsubmit="return confirm('Vorlage „{{ $tpl->name }}" wirklich löschen?');" style="margin:0;">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;">Löschen</button>
            </form>
        </div>
        @endif
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:13.5px;">Noch keine Vorlagen in dieser Kategorie.</p>
    @endforelse
</div>
@endforeach

@if($canManage)
{{-- Modal: Vorlage anlegen/bearbeiten --}}
<div id="tpl-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:560px;max-width:94vw;max-height:92vh;overflow-y:auto;position:relative;">
        <button onclick="document.getElementById('tpl-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div id="tpl-modal-title" style="font-size:17px;font-weight:700;margin-bottom:16px;">Neue Vorlage</div>
        <form id="tpl-form" method="POST" action="{{ route('admin.templates.store') }}">
            @csrf
            <input type="hidden" name="_method" id="tpl-method" value="POST">
            <div style="display:grid;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 180px;gap:12px;">
                    <div>
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Name *</label>
                        <input type="text" name="name" id="tpl-name" required maxlength="120" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                    </div>
                    <div>
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Kategorie *</label>
                        <select name="category" id="tpl-category" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                            <option value="kunde">Kunde</option>
                            <option value="gesellschaft">Gesellschaft</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Betreff (für E-Mails)</label>
                    <input type="text" name="subject" id="tpl-subject" maxlength="200" placeholder="z. B. Fehlende Unterlagen zu Ihrem Vertrag" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                </div>
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Text *</label>
                    <textarea name="body" id="tpl-body" required maxlength="10000" rows="9" placeholder="@{{anrede}},&#10;&#10;…&#10;&#10;Mit freundlichen Grüßen&#10;@{{berater}}" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;"></textarea>
                </div>
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Sortierung</label>
                    <input type="number" name="sort" id="tpl-sort" min="0" max="9999" value="0" style="width:120px;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:18px;">
                <button type="submit" class="btn btn-gold">Speichern</button>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('tpl-modal').style.display='none'">Abbrechen</button>
            </div>
        </form>
    </div>
</div>
<script>
function openTplModal(tpl) {
    const form = document.getElementById('tpl-form');
    document.getElementById('tpl-modal-title').textContent = tpl ? 'Vorlage bearbeiten' : 'Neue Vorlage';
    document.getElementById('tpl-method').value = tpl ? 'PUT' : 'POST';
    form.action = tpl ? '{{ url('admin/vorlagen') }}/' + tpl.id : '{{ route('admin.templates.store') }}';
    document.getElementById('tpl-name').value = tpl ? tpl.name : '';
    document.getElementById('tpl-category').value = tpl ? tpl.category : 'kunde';
    document.getElementById('tpl-subject').value = tpl && tpl.subject ? tpl.subject : '';
    document.getElementById('tpl-body').value = tpl ? tpl.body : '';
    document.getElementById('tpl-sort').value = tpl ? (tpl.sort || 0) : 0;
    document.getElementById('tpl-modal').style.display = 'flex';
}
document.getElementById('tpl-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
@endif

@endsection
