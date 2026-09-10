@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.settings') }}">Einstellungen</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <span>Unternehmenssignaturen</span>
    </div>
    <div>
        <div class="page-title">Unternehmenssignaturen</div>
        <div class="page-sub">
            Unterschrift des Betriebs, Firmenstempel und Logo - einmal hinterlegt, im Feld-Editor
            auf jedes Dokument setzbar.
        </div>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif
@if($errors->any())
<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">
    <ul style="margin:0;padding-left:18px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

{{-- Die rechtliche Abgrenzung steht GANZ OBEN und nicht im Kleingedruckten:
     ein Bild auf einem Dokument ist keine abgegebene Willenserklaerung, und
     wer das verwechselt, setzt den Stempel des Betriebs unter etwas, das
     niemand gelesen hat. --}}
<div style="background:#FFF6E5;color:#8A5D00;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13.5px;">
    <strong>Ein Firmenbild ist kein Unterzeichner.</strong>
    Es hat keine E-Mail, kein Zugangstoken und keine Zustimmung. Im Signaturprotokoll steht deshalb
    <em>„eingesetzt von &lt;Mitarbeiter&gt;"</em> - nie „unterschrieben von". Für eine persönliche
    Unterschrift eines Menschen legen Sie einen Unterzeichner an.
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="card-head-bar">Neues Bild hinterlegen</div>
    <form method="POST" action="{{ route('admin.signatures.company.store') }}" enctype="multipart/form-data"
          style="padding:18px 20px;display:grid;gap:14px;max-width:640px;">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
            <div>
                <label for="type">Art <span style="color:#B3261E;">*</span></label>
                <select id="type" name="type" required
                        style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    @foreach($types as $key => $label)
                    <option value="{{ $key }}" @selected(old('type') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="name">Bezeichnung</label>
                <input id="name" type="text" name="name" maxlength="120" value="{{ old('name') }}"
                       placeholder="z. B. Unterschrift Geschäftsführung"
                       style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                <div class="muted-sm">Mehrere Bilder je Art sind erlaubt - der Name unterscheidet sie.</div>
            </div>
        </div>
        <div>
            <label for="bild">Bilddatei <span style="color:#B3261E;">*</span></label>
            <input id="bild" type="file" name="bild" accept="image/png,image/jpeg,image/webp" required
                   style="width:100%;padding:9px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
            <div class="muted-sm">
                PNG mit <strong>transparentem Hintergrund</strong>, möglichst hochauflösend.
                Ein weiß hinterlegtes Bild legt einen weißen Kasten über den Vertragstext.
            </div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;font-size:13.5px;">
            <input type="checkbox" name="is_default" value="1" @checked(old('is_default'))>
            <span>Als Voreinstellung für diese Art verwenden</span>
        </label>
        <div><button type="submit" class="btn btn-emerald">Hinterlegen</button></div>
    </form>
</div>

@foreach($types as $key => $label)
@php $gruppe = $assets->where('type', $key); @endphp
<div class="card" style="margin-bottom:16px;">
    <div class="card-head-bar">{{ $label }} <span class="muted-sm">({{ $gruppe->count() }})</span></div>
    <div style="padding:16px 20px;">
        @if($gruppe->isEmpty())
            <div class="muted-sm">Noch nichts hinterlegt.</div>
        @else
        <div style="display:grid;gap:12px;">
            @foreach($gruppe as $asset)
            <div style="display:flex;gap:14px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:12px 14px;
                        {{ $asset->active ? '' : 'opacity:.55;' }}">
                {{-- Karierter Hintergrund: nur so sieht man, ob das Bild
                     wirklich transparent ist - auf Weiss sieht ein weisser
                     Kasten aus wie Transparenz. --}}
                <div style="width:130px;height:56px;flex:none;border-radius:6px;
                            background-image:linear-gradient(45deg,#e6e6e6 25%,transparent 25%,transparent 75%,#e6e6e6 75%),
                                             linear-gradient(45deg,#e6e6e6 25%,transparent 25%,transparent 75%,#e6e6e6 75%);
                            background-size:12px 12px;background-position:0 0,6px 6px;background-color:#fff;
                            display:flex;align-items:center;justify-content:center;overflow:hidden;">
                    <img src="{{ route('admin.signatures.company.image', $asset->id) }}" alt="{{ $asset->name }}"
                         style="max-width:100%;max-height:100%;display:block;">
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;">
                        {{ $asset->name }}
                        @if($asset->is_default)<span class="badge badge-emerald">Voreinstellung</span>@endif
                        @if(!$asset->active)<span class="badge">Stillgelegt</span>@endif
                    </div>
                    <div class="muted-sm">
                        {{ $asset->width }}×{{ $asset->height }} px · {{ number_format($asset->bytes / 1024, 0, ',', '.') }} KB
                        · angelegt {{ $asset->created_at->lokal()->format('d.m.Y') }}
                        @if($asset->creator) von {{ $asset->creator->name }}@endif
                        @if($asset->fields()->exists()) · in {{ $asset->fields()->count() }} Dokument(en) verwendet @endif
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex:none;">
                    @if(!$asset->is_default && $asset->active)
                    <form method="POST" action="{{ route('admin.signatures.company.default', $asset->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost">Als Voreinstellung</button>
                    </form>
                    @endif
                    <form method="POST" action="{{ route('admin.signatures.company.destroy', $asset->id) }}"
                          data-confirm="„{{ $asset->name }}" entfernen? Steckt das Bild in bereits unterschriebenen Dokumenten, wird es nur stillgelegt.">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-ghost" style="color:#B3261E;">Entfernen</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endforeach
@endsection
