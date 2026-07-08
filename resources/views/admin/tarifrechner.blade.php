@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Tarifrechner</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">Tarifrechner</div>
            <div class="page-sub">Tarifrechner und Ressourcen für Ihr Team</div>
        </div>
        <button onclick="document.getElementById('add-link-modal').style.display='flex'" class="btn btn-gold">+ Link hinzufügen</button>
    </div>
</div>

<p style="font-size:14px;color:var(--ink-soft);margin-bottom:20px;">Wählen Sie eine Kategorie, um die Links zu öffnen.</p>

@php
$categories = [
    'kfz'              => ['label'=>'KFZ-Versicherung',         'icon'=>'🚗','color'=>'#E6F1FB','text'=>'#185FA5'],
    'kranken'          => ['label'=>'Krankenversicherung',       'icon'=>'🏥','color'=>'#E4F0E7','text'=>'#3B7A57'],
    'haftpflicht'      => ['label'=>'Haftpflichtversicherung',   'icon'=>'🛡️','color'=>'#F0E6FB','text'=>'#6D28D9'],
    'rechtsschutz'     => ['label'=>'Rechtsschutzversicherung',  'icon'=>'⚖️','color'=>'#FEF3C7','text'=>'#92400E'],
    'hausrat'          => ['label'=>'Hausrat & Gebäude',         'icon'=>'🏠','color'=>'#E4F0E7','text'=>'#3B7A57'],
    'escooter'         => ['label'=>'E-Scooter Versicherung',    'icon'=>'🛴','color'=>'#E6F1FB','text'=>'#185FA5'],
    'tierkranken'      => ['label'=>'Tierkrankenversicherung',   'icon'=>'🐾','color'=>'#FEF3C7','text'=>'#92400E'],
    'berufsunfaehigkeit' => ['label'=>'Berufsunfähigkeit',      'icon'=>'💼','color'=>'#F9E3E3','text'=>'#A32D2D'],
    'unfall'           => ['label'=>'Unfallversicherung',        'icon'=>'🚑','color'=>'#F9E3E3','text'=>'#A32D2D'],
    'leben'            => ['label'=>'Lebensversicherung',        'icon'=>'❤️','color'=>'#FBEAF0','text'=>'#993556'],
    'energie'          => ['label'=>'Energie',                   'icon'=>'⚡','color'=>'#FEF3C7','text'=>'#92400E'],
    'internet'         => ['label'=>'Internet',                  'icon'=>'📶','color'=>'#EDE9FE','text'=>'#6D28D9'],
    'sonstige'         => ['label'=>'Sonstige Links',            'icon'=>'🔗','color'=>'#F1EFE8','text'=>'#5F5E5A'],
];
@endphp

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px;">
@foreach($categories as $key => $cat)
@php $catLinks = $links[$key] ?? collect(); @endphp
<div style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px;cursor:pointer;transition:.2s;"
    onclick="toggleCategory('cat-{{ $key }}')"
    onmouseover="this.style.borderColor='var(--gold)'" onmouseout="this.style.borderColor='var(--line)'">
    <div style="width:56px;height:56px;border-radius:14px;background:{{ $cat['color'] }};display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:14px;">{{ $cat['icon'] }}</div>
    <div style="font-weight:700;font-size:15px;margin-bottom:6px;">{{ $cat['label'] }}</div>
    <div style="font-size:13px;color:var(--ink-soft);">🔗 {{ $catLinks->count() }} {{ $catLinks->count() === 1 ? 'Link' : 'Links' }}</div>
</div>
@endforeach
</div>

@foreach($categories as $key => $cat)
@php $catLinks = $links[$key] ?? collect(); @endphp
<div id="cat-{{ $key }}" style="display:none;margin-bottom:20px;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">{{ $cat['icon'] }} {{ $cat['label'] }}</div>
        </div>
        @forelse($catLinks as $link)
        <div class="item-row">
            <div>
                <div style="font-weight:600;font-size:14px;">{{ $link->title }}</div>
                @if($link->description)<div style="font-size:12px;color:var(--ink-soft);">{{ $link->description }}</div>@endif
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <a href="{{ $link->url }}" target="_blank" class="btn btn-primary btn-sm">Öffnen →</a>
                <form method="POST" action="{{ route('admin.tarifrechner.destroy', $link->id) }}" onsubmit="return confirm('Löschen?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:16px;">🗑</button>
                </form>
            </div>
        </div>
        @empty
        <p style="color:var(--ink-soft);font-size:14px;padding:8px 0;">Noch keine Links in dieser Kategorie.</p>
        @endforelse
    </div>
</div>
@endforeach

<div id="add-link-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="document.getElementById('add-link-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Neuen Link hinzufügen</div>
        <form method="POST" action="{{ route('admin.tarifrechner.store') }}">
            @csrf
            <div class="field"><label>Kategorie *</label>
                <select name="category" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    @foreach($categories as $key => $cat)
                    <option value="{{ $key }}">{{ $cat['icon'] }} {{ $cat['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Titel *</label><input type="text" name="title" required placeholder="z.B. HUK Rechner"></div>
            <div class="field"><label>URL *</label><input type="url" name="url" required placeholder="https://..."></div>
            <div class="field"><label>Beschreibung</label><input type="text" name="description" placeholder="Optional"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="document.getElementById('add-link-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCategory(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
@endsection
