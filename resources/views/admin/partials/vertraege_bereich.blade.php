{{--
  EIN BEREICH "Vertraege" (Betreiber-Auftrag 27.11.2026): Liste, Analyse,
  Monatsuebersicht und Neukunden sind Reiter DESSELBEN Bereichs - nicht
  zwei Menuepunkte, die man getrennt suchen muss. Diese Leiste steht auf
  jeder der vier Seiten (und auf den Detaillisten der Analyse), damit man
  von ueberall mit einem Klick zum anderen Blick auf dieselben Vertraege
  kommt.

  Parameter:
    $aktiv         'liste' | 'analyse' | 'monat' | 'neukunden'
    $analyseQuery  optional: Filter, die die Analyse uebernehmen soll
    $monatQuery    optional: Filter fuer die Monatsuebersicht

  Selbsttragendes CSS (eigene Klassen, nur Tokens): die vier Seiten
  definierten `.rep-tab` je fuer sich und leicht verschieden.
--}}
@php
    $aktiv = $aktiv ?? 'liste';
    $reiter = [
        'liste' => ['📄 Verträge', route('admin.contracts')],
        'analyse' => ['📊 Analyse', route('admin.reports', $analyseQuery ?? [])],
        'monat' => ['🗓 Monatsübersicht', route('admin.reports.monat', $monatQuery ?? [])],
        'neukunden' => ['👤 Neukunden', route('admin.reports.neukunden')],
    ];
@endphp
<nav class="vb-reiter" aria-label="Bereich Verträge">
    @foreach($reiter as $key => [$label, $url])
    <a href="{{ $url }}" class="vb-reiter-link{{ $aktiv === $key ? ' vb-aktiv' : '' }}" @if($aktiv === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
    @can('provisionen-verwalten')
    <a href="{{ route('admin.provisions') }}" class="vb-reiter-link vb-neben">💶 Provisionen</a>
    @endcan
</nav>
@once
<style>
.vb-reiter{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 18px;padding:5px;background:var(--surface);border:1px solid var(--line);border-radius:12px;}
.vb-reiter-link{padding:8px 14px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--ink);text-decoration:none;white-space:nowrap;}
.vb-reiter-link:hover{background:var(--surface-soft);}
.vb-reiter-link.vb-aktiv{background:var(--tiefe-graphit);color:#fff;box-shadow:var(--tiefe-schatten-aktiv);}
.vb-reiter-link.vb-neben{margin-left:auto;color:var(--ink-soft);font-weight:500;}
@media (max-width:640px){.vb-reiter{overflow-x:auto;flex-wrap:nowrap;}.vb-reiter-link.vb-neben{margin-left:0;}}
</style>
@endonce
