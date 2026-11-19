@extends('layouts.admin')
@section('content')
@php use App\Support\SignatureStatus; @endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Signaturen</span></div>
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;">
        <div>
            <h1 class="page-title">Signaturen</h1>
            <div class="page-sub">Dokumente zur Unterschrift versenden – mit oder ohne Kundenakte.</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            @if(auth()->user()->role === 'admin')
            @php $qualitaetsBefunde = \App\Services\Signature\SignatureQualityGate::betroffene()->count(); @endphp
            <a href="{{ route('admin.signatures.quality') }}" class="btn btn-ghost">Qualität
                @if($qualitaetsBefunde > 0)
                <span class="badge badge-danger">{{ $qualitaetsBefunde }}</span>
                @endif
            </a>
            @endif
            @can('firmensignatur-verwalten')
            <a href="{{ route('admin.signatures.company.index') }}" class="btn btn-ghost">Unternehmenssignaturen</a>
            @endcan
            @if(auth()->user()->darfFuerFirmaUnterschreiben())
            <a href="{{ route('admin.meine_unterschrift') }}" class="btn btn-ghost">🖋 Meine Unterschrift</a>
            @endif
            <a href="{{ route('admin.signatures.create') }}" class="btn btn-emerald">+ Neue Signaturanfrage</a>
        </div>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

{{-- Reiter und Suche sind normale Links bzw. ein GET-Formular: jeder Stand
     der Liste ist damit teilbar, zurueck-tauglich und als Lesezeichen
     speicherbar (Lehre aus den grossen Listen, 20.08.2026). --}}
<nav class="sigl-tabs" aria-label="Status">
    @foreach(SignatureStatus::TABS as $key => $definition)
    <a href="{{ route('admin.signatures.index', array_merge(request()->only('suche','von','bis','ersteller','kunde'), ['reiter' => $key])) }}"
       class="sigl-tab {{ $tab === $key ? 'aktiv' : '' }}" @if($tab === $key) aria-current="page" @endif>
        {{ $definition['label'] }} <span class="sigl-zahl">{{ $counts[$key] ?? 0 }}</span>
    </a>
    @endforeach
</nav>

<form method="GET" action="{{ route('admin.signatures.index') }}" class="card"
      style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding:14px 16px;">
    <input type="hidden" name="reiter" value="{{ $tab }}">
    <div style="flex:1;min-width:240px;">
        <label class="muted-sm" for="suche">Suche</label>
        <input id="suche" type="search" name="suche" value="{{ $search }}" maxlength="120"
               placeholder="Dokument, Unterzeichner, E-Mail, Kunde, Referenz"
               style="width:100%;padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;" aria-label="Dokument, Unterzeichner, E-Mail, Kunde, Referenz">
    </div>
    <div>
        <label class="muted-sm" for="von">Erstellt von</label>
        <input id="von" type="date" name="von" value="{{ $filters['von'] ?? '' }}"
               style="padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    </div>
    <div>
        <label class="muted-sm" for="bis">bis</label>
        <input id="bis" type="date" name="bis" value="{{ $filters['bis'] ?? '' }}"
               style="padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
    </div>
    <button type="submit" class="btn btn-sm btn-emerald">Filtern</button>
    @if($search !== '' || array_filter($filters))
    <a href="{{ route('admin.signatures.index', ['reiter' => $tab]) }}" class="btn btn-sm btn-ghost">Filter zurücksetzen</a>
    @endif
</form>

{{-- LISTE (Betreiber-Meldung 19.11.2026): kompakt wie Kunden/Tickets -
     Hauptaktion als erhabener Knopf, alles Weitere im •••-Menue. "Anzeigen"
     zeigt beim Ueberfahren den AKTUELLEN Stand des Dokuments (wer hat wo
     unterschrieben), ein Klick oeffnet den Vorgang. --}}
<div class="card card-flush sigl-karte">
<div class="sigl-scroll">
    <table class="sigl-tabelle">
        <thead><tr>
            <th>Dokument</th>
            <th>Unterzeichner</th>
            <th>Kunde</th>
            <th>Status</th>
            <th>Datum</th>
            <th class="rechts">Aktionen</th>
        </tr></thead>
        <tbody>
        @forelse($signatures as $signature)
        <tr>
            <td class="sigl-dok">
                <a href="{{ route('admin.signatures.show', $signature->id) }}" class="sigl-titel">
                    <span class="sigl-icon" aria-hidden="true">📄</span><span>{{ $signature->title }}</span>
                </a>
                <div class="muted-sm">{{ $signature->page_count }} Seite{{ $signature->page_count === 1 ? '' : 'n' }}@if($signature->reference) · Ref. {{ $signature->reference }}@endif</div>
            </td>
            <td>
                <div class="sigl-personen">
                @forelse($signature->signers as $signer)
                    <span class="sigl-person {{ $signer->hasSigned() ? 'ok' : ($signer->hasDeclined() ? 'nein' : 'offen') }}"
                          title="{{ $signer->hasSigned() ? 'Unterschrieben' : ($signer->hasDeclined() ? 'Abgelehnt' : 'Noch offen') }}">
                        {{ $signer->hasSigned() ? '✓' : ($signer->hasDeclined() ? '✕' : '⏳') }} {{ $signer->name }}
                    </span>
                @empty
                    <span class="muted-sm">noch keiner</span>
                @endforelse
                </div>
            </td>
            <td class="sigl-kunde">
                @if($signature->customer)
                    <a href="{{ route('admin.customer', $signature->customer_id) }}">{{ $signature->customer->user?->name ?? $signature->customer->customer_number }}</a>
                @else
                    <span class="muted-sm">nicht zugeordnet</span>
                @endif
            </td>
            <td>
                <span class="badge {{ $signature->statusTone() }}">{{ $signature->statusLabel() }}</span>
                @if($signature->expires_at)
                <div class="muted-sm" style="margin-top:4px;color:{{ $signature->hasExpired() ? '#B3261E' : 'var(--ink-soft)' }};">Frist {{ $signature->expires_at->lokal()->format('d.m.Y') }}</div>
                @endif
            </td>
            <td class="sigl-datum">
                <div>{{ $signature->created_at?->lokal()->format('d.m.Y') }}</div>
                @if($signature->last_activity_at)
                <div class="muted-sm" title="Letzte Aktivität">⟳ {{ $signature->last_activity_at->lokal()->format('d.m. H:i') }}</div>
                @endif
            </td>
            <td class="rechts">
                <div class="sigl-aktionen">
                    <a href="{{ route('admin.signatures.show', $signature->id) }}" class="sigl-knopf"
                       data-preview-url="{{ route('admin.signatures.preview', $signature->id) }}"
                       data-preview-name="{{ $signature->title }}" data-preview-kind="pdf">👁 Anzeigen</a>
                    @if($signature->isDraft())
                        <a href="{{ route('admin.signatures.prepare', $signature->id) }}" class="sigl-knopf">✏️ Bearbeiten</a>
                    @elseif($signature->isCompleted())
                        <a href="{{ route('admin.signatures.download', [$signature->id, 'signed']) }}" class="sigl-knopf">⬇ PDF</a>
                    @elseif($signature->acceptsSignatures())
                        <form method="POST" action="{{ route('admin.signatures.remind', $signature->id) }}" style="display:inline;"
                              data-confirm="Erinnerung an die offenen Unterzeichner senden?">
                            @csrf
                            <button type="submit" class="sigl-knopf">🔔 <span>Erinnern</span></button>
                        </form>
                    @endif

                    <div data-menu class="sigl-menue">
                        <button type="button" data-menu-trigger aria-haspopup="true" aria-expanded="false" title="Weitere Aktionen" class="sigl-knopf sigl-punkte">•••</button>
                        <div data-menu-panel hidden class="sigl-panel">
                            <a href="{{ route('admin.signatures.show', $signature->id) }}" class="rowmenu-item">👁 Vorgang öffnen</a>
                            <a href="{{ route('admin.signatures.download', [$signature->id, 'original']) }}" class="rowmenu-item">📄 Original-PDF</a>
                            @if($signature->isCompleted())
                                @can('supplement', $signature)
                                <form method="POST" action="{{ route('admin.signatures.supplement', $signature->id) }}" style="margin:0;"
                                      data-confirm="Ergänzung anlegen? Die vorhandenen Unterschriften bleiben im Dokument; danach können Sie einen weiteren Unterzeichner, eine vergessene Stelle oder ein Textfeld hinzufügen.">
                                    @csrf
                                    <button type="submit" class="rowmenu-item">➕ Ergänzen (weitere Unterschrift)</button>
                                </form>
                                @endcan
                            @endif
                            @if(!$signature->isDraft() && !$signature->acceptsSignatures())
                                @can('reissue', $signature)
                                <form method="POST" action="{{ route('admin.signatures.reissue', $signature->id) }}" style="margin:0;"
                                      data-confirm="Neue Anfrage aus demselben Original anlegen? Dieser Vorgang bleibt als Nachweis unverändert erhalten.">
                                    @csrf
                                    <button type="submit" class="rowmenu-item">🔁 Neu anfordern</button>
                                </form>
                                @endcan
                            @endif
                            @if($signature->acceptsSignatures())
                                @can('cancel', $signature)
                                <form method="POST" action="{{ route('admin.signatures.cancel', $signature->id) }}" style="margin:0;"
                                      data-confirm="Auftrag „{{ $signature->title }}“ stornieren? Der Link funktioniert danach nicht mehr. Der Vorgang bleibt im Protokoll erhalten.">
                                    @csrf
                                    <button type="submit" class="rowmenu-item gefahr">⛔ Stornieren</button>
                                </form>
                                @endcan
                            @endif
                            @if($signature->isDraft())
                                @can('delete', $signature)
                                <form method="POST" action="{{ route('admin.signatures.destroy', $signature->id) }}" style="margin:0;"
                                      data-confirm="Entwurf „{{ $signature->title }}“ löschen? Versendet wurde noch nichts.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rowmenu-item gefahr">🗑 Löschen</button>
                                </form>
                                @endcan
                            @endif
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:34px;color:var(--ink-soft);">
            Keine Signaturanfragen in dieser Ansicht.
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</div>

@include('partials.doc_preview')

<style>
/* Signaturen-Liste: dieselbe Bildsprache wie Kunden/Tickets, Knoepfe mit
   leichter Tiefe (heller Verlauf, Unterkante, beim Druecken eingesunken). */
.sigl-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.sigl-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;font-size:13.5px;font-weight:600;
  color:var(--ink);text-decoration:none;background:var(--tiefe-hell);border:1px solid var(--line);
  box-shadow:var(--tiefe-schatten);transition:transform .08s,box-shadow .08s;}
.sigl-tab:hover{transform:translateY(-1px);box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 3px 0 rgba(22,33,28,.08),0 6px 14px rgba(22,33,28,.08);}
.sigl-tab.aktiv{background:linear-gradient(180deg,var(--emerald-bright),var(--emerald-deep));color:#fff;border-color:transparent;
  box-shadow:0 1px 0 rgba(255,255,255,.25) inset,0 2px 0 rgba(0,0,0,.18),0 4px 12px rgba(23,166,91,.28);}
.sigl-zahl{display:inline-block;min-width:22px;padding:1px 7px;border-radius:999px;background:rgba(22,33,28,.07);font-size:12px;text-align:center;}
.sigl-tab.aktiv .sigl-zahl{background:rgba(255,255,255,.22);}
.sigl-karte{overflow:visible;}
.sigl-scroll{overflow-x:auto;}
.sigl-tabelle{width:100%;min-width:900px;border-collapse:collapse;}
.sigl-tabelle th{background:var(--canvas);font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft);
  padding:11px 14px;text-align:left;white-space:nowrap;}
.sigl-tabelle th:first-child,.sigl-tabelle td:first-child{padding-left:20px;}
.sigl-tabelle td{padding:12px 14px;font-size:13px;vertical-align:middle;border-top:1px solid var(--line);}
.sigl-tabelle tbody tr:hover{background:#FAF9F4;}
.sigl-tabelle .rechts{text-align:right;padding-right:18px;}
.sigl-dok{min-width:200px;max-width:280px;}
.sigl-titel{display:inline-flex;gap:8px;align-items:flex-start;font-weight:700;color:var(--ink);text-decoration:none;}
.sigl-titel:hover span:last-child{text-decoration:underline;}
.sigl-icon{font-size:16px;line-height:1.2;}
.sigl-personen{display:flex;flex-direction:column;gap:4px;align-items:flex-start;}
.sigl-person{display:inline-flex;gap:5px;align-items:center;padding:3px 9px;border-radius:999px;font-size:12.5px;font-weight:600;white-space:nowrap;}
.sigl-person.ok{background:var(--emerald-soft);color:var(--emerald-deep);}
.sigl-person.offen{background:#F7E7D6;color:#8A5D00;}
.sigl-person.nein{background:#F9E3E3;color:#A32D2D;}
.sigl-kunde a{color:var(--ink);font-weight:600;}
.sigl-datum{white-space:nowrap;}
.sigl-aktionen{display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:nowrap;}
.sigl-knopf{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border-radius:9px;font-size:12.5px;font-weight:700;color:var(--ink);
  text-decoration:none;cursor:pointer;font-family:inherit;white-space:nowrap;background:linear-gradient(180deg,#fff,#F1EEE5);border:1px solid var(--line);
  box-shadow:0 1px 0 rgba(255,255,255,.95) inset,0 2px 0 rgba(22,33,28,.10),0 3px 8px rgba(22,33,28,.06);transition:transform .08s,box-shadow .08s;}
.sigl-knopf:hover{transform:translateY(-1px);border-color:var(--ink-soft);}
.sigl-knopf:active{transform:translateY(1px);box-shadow:0 1px 2px rgba(22,33,28,.12) inset;}
.sigl-punkte{padding:7px 10px;letter-spacing:1px;}
.sigl-menue{position:relative;}
.sigl-panel{position:absolute;right:0;top:100%;z-index:50;background:#fff;border:1px solid var(--line);border-radius:12px;
  box-shadow:0 12px 30px rgba(0,0,0,.16);min-width:250px;padding:6px;text-align:left;}
.rowmenu-item{display:block;width:100%;text-align:left;padding:9px 12px;border-radius:7px;font-size:13.5px;color:var(--ink);text-decoration:none;
  box-sizing:border-box;background:none;border:none;cursor:pointer;font-family:inherit;}
.rowmenu-item:hover{background:#F4F7F5;}
.rowmenu-item.gefahr{color:#A32D2D;}
</style>

<div style="margin-top:16px;">{{ $signatures->links() }}</div>
@endsection
