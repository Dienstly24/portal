@extends('layouts.admin')
@section('content')
@php $isEdit = $mode === 'edit'; @endphp
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.service_pages') }}">Leistungsseiten</a><span class="breadcrumb-sep">›</span>
        <span>{{ $isEdit ? 'Bearbeiten' : 'Neu' }}</span>
    </div>
    <div class="page-title">{{ $isEdit ? 'Leistungsseite bearbeiten' : 'Neue Leistungsseite' }}</div>
    <div class="page-sub">Texte in Deutsch und Arabisch pflegen. Arabisch faellt bei leeren Feldern auf Deutsch zurueck.</div>
</div>

@if($errors->any())
<div class="alert alert-error">
    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
</div>
@endif

<form method="POST" enctype="multipart/form-data"
      action="{{ $isEdit ? route('admin.service_pages.update', $page) : route('admin.service_pages.store') }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="card">
        <div class="card-title">Grunddaten</div>
        <div class="grid-2">
            <div class="field"><label>Titel (DE) *</label>
                <input type="text" name="title_de" required maxlength="255" value="{{ old('title_de', $page->title_de) }}" placeholder="z.B. Kfz-Versicherung"></div>
            <div class="field"><label>Titel (AR)</label>
                <input type="text" name="title_ar" maxlength="255" dir="rtl" value="{{ old('title_ar', $page->title_ar) }}"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Slug (URL) *</label>
                <input type="text" name="slug" required maxlength="120" value="{{ old('slug', $page->slug) }}" placeholder="kfz-versicherung">
                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">Nur Kleinbuchstaben, Zahlen und Bindestriche. Ergibt <code>/leistungen/…</code></div>
            </div>
            <div class="field"><label>Kategorie</label>
                <input type="text" name="category" maxlength="60" value="{{ old('category', $page->category) }}" placeholder="versicherung / kfz / energie"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Icon (Emoji)</label>
                <input type="text" name="icon" maxlength="16" value="{{ old('icon', $page->icon) }}" placeholder="🚗"></div>
            <div class="field"><label>Reihenfolge</label>
                <input type="number" name="sort_order" min="0" max="65535" value="{{ old('sort_order', $page->sort_order) }}"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Untertitel (DE)</label>
                <input type="text" name="subtitle_de" maxlength="255" value="{{ old('subtitle_de', $page->subtitle_de) }}"></div>
            <div class="field"><label>Untertitel (AR)</label>
                <input type="text" name="subtitle_ar" maxlength="255" dir="rtl" value="{{ old('subtitle_ar', $page->subtitle_ar) }}"></div>
        </div>
        <div class="field">
            <label><input type="checkbox" name="is_active" value="1" {{ old('is_active', $page->is_active) ? 'checked' : '' }}> Seite oeffentlich sichtbar</label>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Einleitung (Definition) &amp; Kurzinfos</div>
        <div class="grid-2">
            <div class="field"><label>Einleitung / „Was ist …“ (DE)</label>
                <textarea name="intro_de" rows="5" maxlength="5000">{{ old('intro_de', $page->intro_de) }}</textarea></div>
            <div class="field"><label>Einleitung (AR)</label>
                <textarea name="intro_ar" rows="5" maxlength="5000" dir="rtl">{{ old('intro_ar', $page->intro_ar) }}</textarea></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Kurzinfos (DE) – eine pro Zeile</label>
                <textarea name="highlights_de" rows="5" maxlength="3000" placeholder="Gesetzliche Haftpflicht&#10;Teilkasko bei Diebstahl">{{ old('highlights_de', $page->highlights_de) }}</textarea></div>
            <div class="field"><label>Kurzinfos (AR) – eine pro Zeile</label>
                <textarea name="highlights_ar" rows="5" maxlength="3000" dir="rtl">{{ old('highlights_ar', $page->highlights_ar) }}</textarea></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">FAQ (optional)</div>
        <div id="faqRows">
            @php $faq = old('faq_q_de') ? null : ($page->faq ?? []); @endphp
            @if(old('faq_q_de'))
                @foreach(old('faq_q_de') as $i => $q)
                    @include('admin.partials.service_faq_row', [
                        'q_de' => old('faq_q_de.'.$i), 'q_ar' => old('faq_q_ar.'.$i),
                        'a_de' => old('faq_a_de.'.$i), 'a_ar' => old('faq_a_ar.'.$i)])
                @endforeach
            @else
                @foreach($faq as $item)
                    @include('admin.partials.service_faq_row', [
                        'q_de' => $item['q_de'] ?? '', 'q_ar' => $item['q_ar'] ?? '',
                        'a_de' => $item['a_de'] ?? '', 'a_ar' => $item['a_ar'] ?? ''])
                @endforeach
            @endif
        </div>
        <button type="button" class="btn btn-ghost" onclick="addFaqRow()">➕ FAQ-Eintrag</button>
    </div>

    <div class="card">
        <div class="card-title">Zusaetzliche Formularfelder (optional)</div>
        <div class="page-sub" style="margin-bottom:14px;">Zusaetzliche Eingabefelder im Anfrageformular dieser Leistung – z. B. bei der Kfz-Versicherung „Fahrzeug“ oder „gewuenschte Deckung“. Die Antworten werden an das Ticket angehaengt.</div>
        <div id="fieldRows">
            @php $fields = old('field_label_de') ? null : ($page->fields ?? []); @endphp
            @if(old('field_label_de'))
                @foreach(old('field_label_de') as $i => $l)
                    @include('admin.partials.service_field_row', [
                        'label_de' => old('field_label_de.'.$i), 'label_ar' => old('field_label_ar.'.$i),
                        'type' => old('field_type.'.$i), 'options_de' => old('field_options_de.'.$i),
                        'options_ar' => old('field_options_ar.'.$i), 'required' => old('field_required.'.$i) === '1'])
                @endforeach
            @else
                @foreach($fields as $f)
                    @include('admin.partials.service_field_row', [
                        'label_de' => $f['label_de'] ?? '', 'label_ar' => $f['label_ar'] ?? '',
                        'type' => $f['type'] ?? 'text', 'options_de' => $f['options_de'] ?? '',
                        'options_ar' => $f['options_ar'] ?? '', 'required' => $f['required'] ?? false])
                @endforeach
            @endif
        </div>
        <button type="button" class="btn btn-ghost" onclick="addFieldRow()">➕ Formularfeld</button>
    </div>

    <div class="card">
        <div class="card-title">Bild &amp; SEO (optional)</div>
        <div class="field"><label>Bild (JPG/PNG/WEBP, max. 4 MB)</label>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            @if($page->image_path)
                <div style="margin-top:8px;"><img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($page->image_path) }}" alt="" style="max-height:90px;border-radius:8px;"></div>
            @endif
        </div>
        <div class="grid-2">
            <div class="field"><label>Meta-Beschreibung (DE)</label>
                <input type="text" name="meta_description_de" maxlength="255" value="{{ old('meta_description_de', $page->meta_description_de) }}"></div>
            <div class="field"><label>Meta-Beschreibung (AR)</label>
                <input type="text" name="meta_description_ar" maxlength="255" dir="rtl" value="{{ old('meta_description_ar', $page->meta_description_ar) }}"></div>
        </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">💾 Speichern</button>
        <a href="{{ route('admin.service_pages') }}" class="btn btn-ghost">Abbrechen</a>
    </div>
</form>

<template id="faqRowTpl">
    @include('admin.partials.service_faq_row', ['q_de' => '', 'q_ar' => '', 'a_de' => '', 'a_ar' => ''])
</template>
<template id="fieldRowTpl">
    @include('admin.partials.service_field_row', ['label_de' => '', 'label_ar' => '', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false])
</template>
<script>
function addFaqRow() {
    var tpl = document.getElementById('faqRowTpl');
    document.getElementById('faqRows').appendChild(tpl.content.cloneNode(true));
}
function removeFaqRow(btn) { btn.closest('.faq-row').remove(); }
function addFieldRow() {
    var tpl = document.getElementById('fieldRowTpl');
    document.getElementById('fieldRows').appendChild(tpl.content.cloneNode(true));
}
function removeFieldRow(btn) { btn.closest('.field-row').remove(); }
</script>
@endsection
