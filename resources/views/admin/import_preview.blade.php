@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.import_export') }}">Import / Export</a><span class="breadcrumb-sep">›</span><span>Vorschau</span></div>
    <div class="page-title">Import-Vorschau</div>
    <div class="page-sub">Datei <strong>{{ $filename }}</strong> — bitte pruefen, bevor die Kunden angelegt werden. Es wurde noch <strong>nichts</strong> gespeichert.</div>
</div>

<div style="max-width:960px;">

    {{-- Zusammenfassung --}}
    <div class="grid-2" style="margin-bottom:16px;">
        <div class="card" style="text-align:center;background:#E4F0E7;">
            <div style="font-size:34px;font-weight:700;color:#17A65B;">{{ $preview['new_count'] }}</div>
            <div style="font-size:14px;color:#3B7A57;">werden neu angelegt</div>
        </div>
        <div class="card" style="text-align:center;">
            <div style="display:flex;justify-content:space-around;">
                <div>
                    <div style="font-size:24px;font-weight:700;color:var(--ink);">{{ $preview['dup_count'] }}</div>
                    <div style="font-size:12px;color:var(--ink-soft);">Duplikate (uebersprungen)</div>
                </div>
                <div>
                    <div style="font-size:24px;font-weight:700;color:var(--ink);">{{ $preview['skipped'] }}</div>
                    <div style="font-size:12px;color:var(--ink-soft);">ohne Name (uebersprungen)</div>
                </div>
                <div>
                    <div style="font-size:24px;font-weight:700;color:{{ $preview['error_count'] > 0 ? '#c0392b' : 'var(--ink)' }};">{{ $preview['error_count'] }}</div>
                    <div style="font-size:12px;color:var(--ink-soft);">Fehler</div>
                </div>
            </div>
        </div>
    </div>

    @if($preview['no_email'] > 0)
    <div class="alert alert-warning" style="margin-bottom:16px;">
        ⚠ {{ $preview['no_email'] }} der neuen Kunden haben <strong>keine E-Mail-Adresse</strong> und bekommen eine interne Platzhalter-Adresse. Diese Kunden koennen keine E-Mails erhalten, bis eine Adresse ergaenzt wird.
    </div>
    @endif

    {{-- Neue Kunden --}}
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><div class="card-title">✅ Neu anzulegen ({{ $preview['new_count'] }})</div></div>
        @if($preview['new_count'] === 0)
            <div style="font-size:13px;color:var(--ink-soft);">Keine neuen Kunden in dieser Datei.</div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="text-align:left;border-bottom:1px solid var(--line);">
                    <th style="padding:6px 8px;">Name</th><th style="padding:6px 8px;">E-Mail</th><th style="padding:6px 8px;">Nr.</th><th style="padding:6px 8px;">Ort</th>
                </tr></thead>
                <tbody>
                @foreach(array_slice($preview['new'], 0, 50) as $c)
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:6px 8px;">{{ $c['name'] }}</td>
                        <td style="padding:6px 8px;">
                            @if($c['has_email'])<span>{{ $c['email'] }}</span>@else<span style="color:#c0392b;">— keine —</span>@endif
                        </td>
                        <td style="padding:6px 8px;font-family:monospace;">{{ $c['number'] }}</td>
                        <td style="padding:6px 8px;">{{ $c['city'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if($preview['new_count'] > 50)
            <div style="font-size:12px;color:var(--ink-soft);margin-top:8px;">… und {{ $preview['new_count'] - 50 }} weitere.</div>
            @endif
        </div>
        @endif
    </div>

    {{-- Duplikate --}}
    @if($preview['dup_count'] > 0)
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><div class="card-title">↩ Duplikate — werden uebersprungen ({{ $preview['dup_count'] }})</div></div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="text-align:left;border-bottom:1px solid var(--line);">
                    <th style="padding:6px 8px;">Name</th><th style="padding:6px 8px;">E-Mail</th><th style="padding:6px 8px;">Grund</th>
                </tr></thead>
                <tbody>
                @foreach(array_slice($preview['duplicates'], 0, 50) as $c)
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:6px 8px;">{{ $c['name'] }}</td>
                        <td style="padding:6px 8px;">{{ $c['email'] }}</td>
                        <td style="padding:6px 8px;color:var(--ink-soft);">{{ $c['reason'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if($preview['dup_count'] > 50)
            <div style="font-size:12px;color:var(--ink-soft);margin-top:8px;">… und {{ $preview['dup_count'] - 50 }} weitere.</div>
            @endif
        </div>
    </div>
    @endif

    {{-- Fehler --}}
    @if($preview['error_count'] > 0)
    <div class="card" style="margin-bottom:16px;border:1px solid #e6b0aa;">
        <div class="card-header"><div class="card-title" style="color:#c0392b;">⚠ Fehler ({{ $preview['error_count'] }})</div></div>
        @foreach(array_slice($preview['errors'], 0, 20) as $err)
        <div style="font-size:13px;color:#c0392b;">• {{ $err }}</div>
        @endforeach
    </div>
    @endif

    {{-- Aktionen --}}
    <div style="display:flex;gap:12px;justify-content:flex-end;align-items:center;">
        <a href="{{ route('admin.import_export') }}" class="btn btn-ghost">Abbrechen</a>
        @if($preview['new_count'] > 0)
        <form method="POST" action="{{ route('admin.import.confirm') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="btn btn-primary">✅ {{ $preview['new_count'] }} Kunden jetzt importieren</button>
        </form>
        @else
        <span style="font-size:13px;color:var(--ink-soft);">Nichts zu importieren.</span>
        @endif
    </div>

</div>
@endsection
