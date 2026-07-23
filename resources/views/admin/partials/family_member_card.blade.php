{{--
    Rich-Karte fuer ein Familienmitglied (CustomerFamily). Zeigt alle
    relevanten Daten (Geburtsdatum, Geschlecht, Krankenkasse, KV-Nummer,
    KV-Status, Steuer-ID) statt nur Name + Beziehung.

    Erwartete Variablen:
      $f          CustomerFamily
      $showDelete bool  - Loeschen-Button rendern (nur in der Bearbeitung)
--}}
@php
    $showDelete = $showDelete ?? false;
    $rel = strtolower(trim($f->relation ?? ''));
    // Geschlecht robust lesen: neues Feld gender (male|female) mit Fallback
    // auf die Alt-Spalte geschlecht (m|w).
    $g = strtolower(trim($f->gender ?? $f->geschlecht ?? ''));
    $isFemale = in_array($g, ['female', 'w', 'weiblich'], true);
    $isMale   = in_array($g, ['male', 'm', 'maennlich', 'männlich'], true);
    $isKind   = str_contains($rel, 'kind');
    $icon = $isKind
        ? ($isFemale ? '👧' : ($isMale ? '👦' : '🧒'))
        : ($isFemale ? '👩' : ($isMale ? '👨' : '👤'));
    $genderLabel = $isFemale ? 'Weiblich' : ($isMale ? 'Männlich' : null);
    $relLabels = [
        'kind' => 'Kind', 'ehepartner' => 'Ehepartner', 'hauptversicherter' => 'Hauptversicherter',
        'andere' => 'Weitere Person', 'familienmitglied' => 'Familienmitglied',
        'elternteil' => 'Elternteil', 'geschwister' => 'Geschwister', 'sonstige' => 'Sonstige',
    ];
    $relLabel = $relLabels[$rel] ?? ($f->relation ?: 'Familienmitglied');
    $age   = $f->birth_date ? \Carbon\Carbon::parse($f->birth_date)->age : null;
    $birth = $f->birth_date ? \Carbon\Carbon::parse($f->birth_date)->format('d.m.Y') : null;
    $statusLabel = \App\Models\CustomerFamily::HEALTH_STATUS[$f->health_insurance_status] ?? null;
    $kvStart = $f->health_insurance_start ? \Carbon\Carbon::parse($f->health_insurance_start)->format('d.m.Y') : null;
@endphp
<div style="border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--surface,#FBFAF6);position:relative;">
    @if($showDelete)
    <form method="POST" action="{{ route('admin.customer.family.delete', $f->id) }}"
          onsubmit="return confirm('Familienmitglied „{{ addslashes($f->name) }}“ wirklich entfernen?')"
          style="position:absolute;top:8px;right:10px;margin:0;">
        @csrf @method('DELETE')
        <button type="submit" title="Entfernen" style="background:none;border:0;cursor:pointer;color:#A32D2D;font-size:14px;padding:0;line-height:1;">✕</button>
    </form>
    @endif
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;{{ $showDelete ? 'padding-right:18px;' : '' }}">
        <div style="width:44px;height:44px;border-radius:10px;background:#EDEAE0;display:flex;align-items:center;justify-content:center;font-size:24px;flex:none;">{{ $icon }}</div>
        <div style="min-width:0;">
            <div style="font-size:14.5px;font-weight:700;line-height:1.25;">{{ $f->name }}</div>
            <div style="font-size:12px;color:var(--ink-soft);margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <span style="background:#EAF2FB;color:#185FA5;border-radius:999px;padding:1px 9px;">{{ $relLabel }}</span>
                @if($age !== null)<span>{{ $age }} J.</span>@endif
            </div>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 12px;font-size:12.5px;">
        <span style="color:var(--ink-soft);">Geburtsdatum</span><span style="font-weight:600;text-align:right;">{{ $birth ?? '—' }}</span>
        <span style="color:var(--ink-soft);">Geschlecht</span><span style="font-weight:600;text-align:right;">{{ $genderLabel ?? '—' }}</span>
        <span style="color:var(--ink-soft);">Krankenkasse</span><span style="font-weight:600;text-align:right;">{{ $f->health_insurance_company ?? '—' }}</span>
        <span style="color:var(--ink-soft);">KV-Nummer</span><span style="font-weight:600;font-family:monospace;text-align:right;word-break:break-all;">{{ $f->health_insurance_number ?? '—' }}</span>
        @if($statusLabel)
        <span style="color:var(--ink-soft);">KV-Status</span><span style="font-weight:600;text-align:right;">{{ $statusLabel }}</span>
        @endif
        @if($kvStart)
        <span style="color:var(--ink-soft);">Versichert seit</span><span style="font-weight:600;text-align:right;">{{ $kvStart }}</span>
        @endif
        @if($f->tax_id)
        <span style="color:var(--ink-soft);">Steuer-ID</span><span style="font-weight:600;font-family:monospace;text-align:right;word-break:break-all;">{{ $f->tax_id }}</span>
        @endif
    </div>
</div>
