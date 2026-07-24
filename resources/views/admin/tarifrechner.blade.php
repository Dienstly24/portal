@extends('layouts.admin')
@section('content')
<style>
/* ===== Vergleichsportale / Link-Center ===== */
.vp-searchbar{position:relative;margin-bottom:22px;}
.vp-searchbar input{width:100%;padding:14px 18px 14px 46px;border:1px solid var(--line);border-radius:12px;font-size:15px;background:var(--surface);color:var(--ink);transition:.15s;}
.vp-searchbar input:focus{outline:2px solid var(--gold);outline-offset:1px;background:#fff;}
.vp-searchbar .vp-search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--ink-soft);}
.vp-searchbar .vp-search-clear{position:absolute;right:12px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;font-size:16px;color:var(--ink-soft);display:none;}

.vp-strip{margin-bottom:22px;}
.vp-strip-title{font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.vp-chips{display:flex;flex-wrap:wrap;gap:8px;}
.vp-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border:1px solid var(--line);border-radius:999px;background:var(--surface);text-decoration:none;color:var(--ink);font-size:13px;font-weight:600;transition:.15s;max-width:240px;}
.vp-chip:hover{border-color:var(--gold);box-shadow:0 2px 8px rgba(0,0,0,.06);transform:translateY(-1px);}
.vp-chip .ico{font-size:14px;flex:none;}
.vp-chip .txt{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.vp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;align-items:start;}
.vp-card{background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden;transition:.15s;}
.vp-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.06);}
.vp-card-head{display:flex;align-items:center;gap:12px;padding:15px 16px;border-bottom:1px solid var(--line);}
.vp-card-ico{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:21px;flex:none;}
.vp-card-title{font-weight:700;font-size:14.5px;line-height:1.2;}
.vp-card-count{font-size:12px;color:var(--ink-soft);margin-top:2px;}
.vp-card-add{margin-left:auto;border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:20px;line-height:1;padding:4px 6px;border-radius:8px;transition:.15s;}
.vp-card-add:hover{background:var(--canvas);color:var(--gold);}

.vp-links{list-style:none;padding:6px;margin:0;}
.vp-link{display:flex;align-items:center;gap:4px;border-radius:9px;padding:2px 4px 2px 2px;transition:.12s;}
.vp-link:hover{background:var(--canvas);}
.vp-link.vp-collapsed{display:none;}
body.vp-searching .vp-link.vp-collapsed{display:flex;}
.vp-drag{cursor:grab;color:var(--line);font-size:14px;padding:0 4px;user-select:none;flex:none;line-height:1;}
.vp-link:hover .vp-drag{color:var(--ink-soft);}
.vp-link.vp-dragging{opacity:.4;}
.vp-link.vp-dropover{background:#D9F4E6;}
.vp-link-main{flex:1;min-width:0;display:flex;flex-direction:column;padding:9px 8px;text-decoration:none;color:var(--ink);border-radius:8px;}
.vp-link-title{font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:7px;}
.vp-link-title .go{opacity:0;font-size:12px;color:var(--gold);transition:.12s;}
.vp-link:hover .vp-link-title .go{opacity:1;}
.vp-link-desc{font-size:11.5px;color:var(--ink-soft);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.vp-star{border:none;background:none;cursor:pointer;font-size:16px;color:var(--line);padding:4px;flex:none;line-height:1;transition:.12s;}
.vp-star:hover{color:var(--akzent);}
.vp-star.on{color:var(--akzent);}
.vp-del{border:none;background:none;cursor:pointer;color:var(--line);font-size:14px;padding:4px;flex:none;line-height:1;}
.vp-link:hover .vp-del{color:var(--ink-soft);}
.vp-del:hover{color:#A32D2D;}
.vp-more{width:100%;border:none;background:none;cursor:pointer;color:var(--gold);font-size:12.5px;font-weight:600;padding:9px;text-align:center;border-top:1px solid var(--line);}
.vp-more:hover{background:var(--canvas);}
.vp-empty-cat{padding:14px 12px;color:var(--ink-soft);font-size:12.5px;}

.vp-noresult{display:none;text-align:center;padding:48px 20px;color:var(--ink-soft);}
.vp-noresult .big{font-size:34px;margin-bottom:10px;}
body.vp-searching .vp-card.vp-hidden{display:none;}

@media(max-width:600px){.vp-grid{grid-template-columns:1fr;}}
</style>

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Vergleichsportale</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <div class="page-title">Vergleichsportale</div>
            <div class="page-sub">Link-Center — alle Portale &amp; Rechner mit einem Klick</div>
        </div>
        <button onclick="vpOpenAdd()" class="btn btn-gold">+ Link hinzufügen</button>
    </div>
</div>

<div class="vp-searchbar">
    <span class="vp-search-icon">🔍</span>
    <input type="text" id="vp-search" placeholder="Suchen … z.B. „NAFI“, „Check24“ oder „Strom“" autocomplete="off">
    <button type="button" class="vp-search-clear" id="vp-search-clear" onclick="vpClearSearch()" title="Löschen">✕</button>
</div>

{{-- Favoriten (localStorage) --}}
<div class="vp-strip" id="vp-fav-strip" style="display:none;">
    <div class="vp-strip-title">⭐ Favoriten</div>
    <div class="vp-chips" id="vp-fav-chips"></div>
</div>

{{-- Zuletzt verwendet (localStorage) --}}
<div class="vp-strip" id="vp-recent-strip" style="display:none;">
    <div class="vp-strip-title">🕒 Zuletzt verwendet</div>
    <div class="vp-chips" id="vp-recent-chips"></div>
</div>

@php $totalLinks = collect($links)->flatten(1)->count(); @endphp

<div class="vp-grid" id="vp-grid">
@foreach($categories as $key => $cat)
@php $catLinks = $links[$key] ?? collect(); @endphp
@continue($catLinks->isEmpty())
<div class="vp-card" data-cat="{{ $key }}" data-cat-label="{{ $cat['label'] }}" data-cat-keywords="{{ $cat['keywords'] }}">
    <div class="vp-card-head">
        <div class="vp-card-ico" style="background:{{ $cat['color'] }};">{{ $cat['icon'] }}</div>
        <div>
            <div class="vp-card-title">{{ $cat['label'] }}</div>
            <div class="vp-card-count">{{ $catLinks->count() }} {{ $catLinks->count() === 1 ? 'Link' : 'Links' }}</div>
        </div>
        <button class="vp-card-add" title="Link zu {{ $cat['label'] }} hinzufügen" onclick="vpOpenAdd('{{ $key }}')">+</button>
    </div>
    <ul class="vp-links" data-cat="{{ $key }}">
        @foreach($catLinks as $i => $link)
        <li class="vp-link {{ $i >= 6 ? 'vp-collapsed' : '' }}" draggable="true"
            data-id="{{ $link->id }}" data-title="{{ $link->title }}" data-url="{{ $link->url }}"
            data-cat="{{ $key }}" data-icon="{{ $cat['icon'] }}">
            <span class="vp-drag" title="Ziehen zum Sortieren">⠿</span>
            <a class="vp-link-main" href="{{ $link->url }}" target="_blank" rel="noopener"
               onclick="vpRecord('{{ $link->id }}')">
                <span class="vp-link-title">{{ $link->title }} <span class="go">↗</span></span>
                @if($link->description)<span class="vp-link-desc">{{ $link->description }}</span>@endif
            </a>
            <button class="vp-star" data-id="{{ $link->id }}" onclick="vpToggleFav('{{ $link->id }}')" title="Favorit">☆</button>
            <form method="POST" action="{{ route('admin.tarifrechner.destroy', $link->id) }}" onsubmit="return confirm('Link löschen?')" style="margin:0;">
                @csrf @method('DELETE')
                <button type="submit" class="vp-del" title="Löschen">🗑</button>
            </form>
        </li>
        @endforeach
    </ul>
    @if($catLinks->count() > 6)
    <button class="vp-more" onclick="vpToggleMore(this)" data-more="{{ $catLinks->count() - 6 }}">+ {{ $catLinks->count() - 6 }} weitere anzeigen</button>
    @endif
</div>
@endforeach
</div>

@if($totalLinks === 0)
<div class="card" style="text-align:center;padding:48px 20px;">
    <div style="font-size:38px;margin-bottom:12px;">🔗</div>
    <div style="font-weight:700;font-size:16px;margin-bottom:6px;">Noch keine Links</div>
    <div style="color:var(--ink-soft);font-size:14px;margin-bottom:18px;">Legen Sie die ersten Vergleichsportale und Rechner an.</div>
    <button onclick="vpOpenAdd()" class="btn btn-gold">+ Ersten Link hinzufügen</button>
</div>
@endif

<div class="vp-noresult" id="vp-noresult">
    <div class="big">🔍</div>
    <div style="font-weight:600;">Keine Treffer für „<span id="vp-noresult-q"></span>“</div>
    <div style="font-size:13px;margin-top:4px;">Andere Schreibweise oder Portal-Name probieren.</div>
</div>

{{-- Modal: Link hinzufügen --}}
<div id="add-link-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="vpCloseAdd()" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Neuen Link hinzufügen</div>
        <form method="POST" action="{{ route('admin.tarifrechner.store') }}">
            @csrf
            <div class="field"><label>Kategorie *</label>
                <select name="category" id="vp-add-category" required>
                    @foreach($categories as $key => $cat)
                    <option value="{{ $key }}">{{ $cat['icon'] }} {{ $cat['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Name *</label><input type="text" name="title" required placeholder="z.B. NAFI, Check24, Mr-Money, EVB"></div>
            <div class="field"><label>URL *</label><input type="url" name="url" required placeholder="https://..."></div>
            <div class="field"><label>Beschreibung</label><input type="text" name="description" placeholder="Optional"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="vpCloseAdd()" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-gold">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

<script>
const VP_CSRF = '{{ csrf_token() }}';
const VP_REORDER_URL = '{{ route('admin.tarifrechner.reorder') }}';
const VP_FAV_KEY = 'vp_favs', VP_RECENT_KEY = 'vp_recent', VP_RECENT_MAX = 8;

/* ---- Snapshot aller Links (fuer Favoriten/Verlauf-Rendering) ---- */
const VP_LINKS = {};
document.querySelectorAll('.vp-link').forEach(li => {
    VP_LINKS[li.dataset.id] = {id:li.dataset.id, title:li.dataset.title, url:li.dataset.url, icon:li.dataset.icon};
});

/* ---- localStorage Helfer ---- */
function vpGet(key){ try { return JSON.parse(localStorage.getItem(key)) || []; } catch(e){ return []; } }
function vpSet(key,val){ localStorage.setItem(key, JSON.stringify(val)); }

/* ---- Favoriten ---- */
function vpFavs(){ return vpGet(VP_FAV_KEY); }
function vpToggleFav(id){
    let favs = vpFavs();
    favs = favs.includes(id) ? favs.filter(x => x !== id) : [id, ...favs];
    vpSet(VP_FAV_KEY, favs);
    vpRenderFavs();
    vpSyncStars();
}
function vpSyncStars(){
    const favs = vpFavs();
    document.querySelectorAll('.vp-star').forEach(b => {
        const on = favs.includes(b.dataset.id);
        b.classList.toggle('on', on);
        b.textContent = on ? '★' : '☆';
    });
}
function vpChip(l){
    return `<a class="vp-chip" href="${encodeURI(l.url)}" target="_blank" rel="noopener" onclick="vpRecord('${l.id}')" title="${vpEsc(l.title)}"><span class="ico">${l.icon||'🔗'}</span><span class="txt">${vpEsc(l.title)}</span></a>`;
}
function vpEsc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function vpRenderFavs(){
    const favs = vpFavs().map(id => VP_LINKS[id]).filter(Boolean);
    const strip = document.getElementById('vp-fav-strip');
    if(!favs.length){ strip.style.display = 'none'; return; }
    document.getElementById('vp-fav-chips').innerHTML = favs.map(vpChip).join('');
    strip.style.display = '';
}

/* ---- Zuletzt verwendet ---- */
function vpRecord(id){
    const l = VP_LINKS[id]; if(!l) return;
    let rec = vpGet(VP_RECENT_KEY).filter(r => r.id !== id);
    rec.unshift({id:l.id, title:l.title, url:l.url, icon:l.icon});
    vpSet(VP_RECENT_KEY, rec.slice(0, VP_RECENT_MAX));
    // kein Re-Render noetig (neuer Tab), erst beim naechsten Laden sichtbar
}
function vpRenderRecent(){
    const rec = vpGet(VP_RECENT_KEY).filter(r => VP_LINKS[r.id]); // nur noch existierende Links
    const strip = document.getElementById('vp-recent-strip');
    if(!rec.length){ strip.style.display = 'none'; return; }
    document.getElementById('vp-recent-chips').innerHTML = rec.map(vpChip).join('');
    strip.style.display = '';
}

/* ---- "+ weitere anzeigen" ---- */
function vpToggleMore(btn){
    const card = btn.closest('.vp-card');
    const collapsed = card.querySelectorAll('.vp-link.vp-collapsed');
    const isOpen = btn.dataset.open === '1';
    card.querySelectorAll('.vp-link').forEach((li,i) => { if(i >= 6) li.classList.toggle('vp-collapsed', isOpen); });
    btn.dataset.open = isOpen ? '' : '1';
    btn.textContent = isOpen ? ('+ ' + btn.dataset.more + ' weitere anzeigen') : 'weniger anzeigen';
}

/* ---- Suche ---- */
function vpNorm(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
function vpSearch(){
    const q = vpNorm(document.getElementById('vp-search').value.trim());
    document.getElementById('vp-search-clear').style.display = q ? 'block' : 'none';
    if(!q){ vpClearSearch(true); return; }
    document.body.classList.add('vp-searching');
    let total = 0;
    document.querySelectorAll('.vp-card').forEach(card => {
        const catHay = vpNorm(card.dataset.catLabel + ' ' + card.dataset.catKeywords);
        const catMatch = catHay.includes(q);
        let shown = 0;
        card.querySelectorAll('.vp-link').forEach(li => {
            const hay = vpNorm(li.dataset.title) + ' ' + catHay;
            const match = catMatch || hay.includes(q);
            li.style.display = match ? 'flex' : 'none';
            if(match) shown++;
        });
        card.classList.toggle('vp-hidden', shown === 0);
        total += shown;
    });
    document.querySelectorAll('.vp-more').forEach(b => b.style.display = 'none');
    const nr = document.getElementById('vp-noresult');
    document.getElementById('vp-noresult-q').textContent = document.getElementById('vp-search').value.trim();
    nr.style.display = total === 0 ? 'block' : 'none';
}
function vpClearSearch(keepValue){
    if(!keepValue){ document.getElementById('vp-search').value = ''; document.getElementById('vp-search-clear').style.display = 'none'; }
    document.body.classList.remove('vp-searching');
    document.querySelectorAll('.vp-link').forEach(li => li.style.display = '');
    document.querySelectorAll('.vp-card').forEach(c => c.classList.remove('vp-hidden'));
    // Kollaps-Zustand je Abschnitt wiederherstellen (ausser "weitere" ist offen).
    document.querySelectorAll('.vp-card').forEach(card => {
        card.querySelectorAll('.vp-link').forEach((li,i) => li.classList.toggle('vp-collapsed', i >= 6 && card.querySelector('.vp-more')?.dataset.open !== '1'));
    });
    document.querySelectorAll('.vp-more').forEach(b => b.style.display = '');
    document.getElementById('vp-noresult').style.display = 'none';
}

/* ---- Modal ---- */
function vpOpenAdd(cat){
    if(cat){ const sel = document.getElementById('vp-add-category'); if(sel) sel.value = cat; }
    document.getElementById('add-link-modal').style.display = 'flex';
}
function vpCloseAdd(){ document.getElementById('add-link-modal').style.display = 'none'; }

/* ---- Drag & Drop (Reihenfolge je Abschnitt) ---- */
let vpDragEl = null;
function vpInitDnd(){
    document.querySelectorAll('.vp-links').forEach(list => {
        list.addEventListener('dragstart', e => {
            const li = e.target.closest('.vp-link'); if(!li) return;
            vpDragEl = li; li.classList.add('vp-dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragend', () => {
            if(vpDragEl){ vpDragEl.classList.remove('vp-dragging'); }
            list.querySelectorAll('.vp-dropover').forEach(x => x.classList.remove('vp-dropover'));
            vpDragEl = null;
        });
        list.addEventListener('dragover', e => {
            e.preventDefault();
            if(!vpDragEl || vpDragEl.parentElement !== list) return; // nur innerhalb desselben Abschnitts
            const after = vpDragAfter(list, e.clientY);
            list.querySelectorAll('.vp-dropover').forEach(x => x.classList.remove('vp-dropover'));
            if(after == null){ list.appendChild(vpDragEl); }
            else { after.classList.add('vp-dropover'); list.insertBefore(vpDragEl, after); }
        });
        list.addEventListener('drop', e => {
            e.preventDefault();
            list.querySelectorAll('.vp-dropover').forEach(x => x.classList.remove('vp-dropover'));
            vpPersistOrder(list);
        });
    });
}
function vpDragAfter(list, y){
    const els = [...list.querySelectorAll('.vp-link:not(.vp-dragging)')];
    return els.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if(offset < 0 && offset > closest.offset){ return {offset, element: child}; }
        return closest;
    }, {offset: -Infinity, element: null}).element;
}
function vpPersistOrder(list){
    const order = [...list.querySelectorAll('.vp-link')].map(li => li.dataset.id);
    fetch(VP_REORDER_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':VP_CSRF,'Accept':'application/json'},
        body: JSON.stringify({order})
    }).catch(() => {});
}

/* ---- Init ---- */
document.getElementById('vp-search').addEventListener('input', vpSearch);
document.getElementById('vp-search').addEventListener('keydown', e => { if(e.key === 'Escape') vpClearSearch(); });
document.addEventListener('keydown', e => {
    if((e.ctrlKey || e.metaKey) && e.key === 'k'){ e.preventDefault(); document.getElementById('vp-search').focus(); }
    if(e.key === 'Escape') vpCloseAdd();
});
vpRenderFavs();
vpRenderRecent();
vpSyncStars();
vpInitDnd();
</script>
@endsection
