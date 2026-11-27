@extends('layouts.admin')
@section('content')
{{-- Interessenten aus dem Website-Assistenten (Spezifikation Abschnitt 20).

     Ein Lead ist bewusst KEIN Kunde: der Besucher hat nichts unterschrieben
     und meist keine Akte. Hier steht, was die KI gesammelt hat, wie weit das
     Gespraech ist und was als naechstes zu tun ist - damit ein Mitarbeiter
     ohne Nachfragen weiterarbeiten kann.

     Sensible Angaben (Bankverbindung, Geburtsdatum) erhebt der
     Website-Assistent GRUNDSAETZLICH nicht - ein Interessent ist nicht
     identifiziert. --}}
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <span>Interessenten</span>
    </div>
    <div>
        <h1 class="page-title">Interessenten von der Website</h1>
        <div class="page-sub">Vom KI-Assistenten qualifizierte Anfragen. Angebot auswählen und den Interessenten kontaktieren.</div>
    </div>
</div>

{{-- Der Layout-Rahmen zeigt Erfolg/Fehler als Rueckmeldung oben rechts. --}}

{{-- Kennzahlen = Filter-Links: jede Zahl fuehrt auf genau ihre Zeilen. --}}
<div class="kpi-raster">
    <x-admin.kpi label="Interessenten gesamt" :wert="$zaehler['gesamt']" symbol="lead" :href="route('admin.leads.index')" />
    <x-admin.kpi label="Noch kein Kunde" :wert="$zaehler['offen']" symbol="clock" ton="warn" :href="route('admin.leads.index', ['kunde' => 'offen'])" />
    <x-admin.kpi label="Ohne Zuständigen" :wert="$zaehler['ohne_zustaendig']" symbol="alert" ton="warn" :href="route('admin.leads.index', ['kunde' => 'offen', 'zustaendig' => 'niemand'])" />
    <x-admin.kpi label="In Kunden umgewandelt" :wert="$zaehler['umgewandelt']" symbol="users" :href="route('admin.leads.index', ['kunde' => 'umgewandelt'])" />
</div>

<form method="GET" class="vf-leiste" aria-label="Interessenten filtern">
    <label class="vf-feld"><span>Zustand</span>
        <select name="zustand">
            <option value="">Alle</option>
            @foreach(\App\Services\Ai\Assistant\Sales\ConversationState::LABELS as $key => $label)
                <option value="{{ $key }}" @selected($zustand === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="vf-feld"><span>Quelle</span>
        <select name="quelle">
            <option value="">Alle Quellen</option>
            @foreach($quellen as $q)<option value="{{ $q }}" @selected($quelle === $q)>{{ ucfirst($q) }}</option>@endforeach
        </select>
    </label>
    <label class="vf-feld"><span>Zuständig</span>
        <select name="zustaendig">
            <option value="">Alle</option>
            <option value="ich" @selected($zustaendig === 'ich')>Ich</option>
            <option value="niemand" @selected($zustaendig === 'niemand')>Niemand zugewiesen</option>
            @foreach($mitarbeiter as $m)<option value="{{ $m->id }}" @selected($zustaendig === (string) $m->id)>{{ $m->name }}</option>@endforeach
        </select>
    </label>
    <label class="vf-feld"><span>Kunde</span>
        <select name="kunde">
            <option value="">Alle</option>
            <option value="offen" @selected($kunde === 'offen')>Noch kein Kunde</option>
            <option value="umgewandelt" @selected($kunde === 'umgewandelt')>Bereits Kunde</option>
        </select>
    </label>
    <div class="vf-knoepfe">
        <button type="submit" class="btn btn-primary btn-sm">Filtern</button>
        @if($zustand.$quelle.$zustaendig.$kunde !== '')<a href="{{ route('admin.leads.index') }}" class="btn btn-ghost btn-sm">Zurücksetzen</a>@endif
    </div>
</form>

<div class="card card-flush">
    @if($leads->isEmpty())
        <div class="leer-hinweis" style="padding:28px;">
            @if($zustand.$quelle.$zustaendig.$kunde !== '')Keine Interessenten für diese Filter.@else Noch keine Interessenten. Sobald ein Besucher den Assistenten auf der Website nutzt, erscheint er hier.@endif
        </div>
    @else
    <div class="tabelle-wrap">
        <table class="table">
            <thead>
                <tr style="background:var(--surface-soft);">
                    <th style="padding-left:20px;">Interessent</th>
                    <th>Anliegen</th>
                    <th>Angaben</th>
                    <th>Quelle / Eingang</th>
                    <th>Zustand</th>
                    <th>Zuständig</th>
                    <th style="padding-right:20px;">Nächster Schritt</th>
                </tr>
            </thead>
            <tbody>
            @foreach($leads as $lead)
                @php $kontakt = $lead->contactData(); @endphp
                <tr>
                    <td style="padding-left:20px;">
                        <strong>{{ $lead->displayName() }}</strong>
                        @if(!empty($kontakt['email']))<div class="muted-xs">{{ $kontakt['email'] }}</div>@endif
                        @if(!empty($kontakt['phone']))<div class="muted-xs">{{ $kontakt['phone'] }}</div>@endif
                    </td>
                    <td>{{ $lead->intentLabel() }}</td>
                    <td style="font-size:12px;">
                        @foreach($lead->collectedData() as $key => $wert)
                            @continue(in_array($key, ['name','email','phone'], true))
                            <div><span class="muted">{{ $key }}:</span> {{ \Illuminate\Support\Str::limit($wert, 60) }}</div>
                        @endforeach
                    </td>
                    <td class="nowrap"><span class="badge badge-open">{{ ucfirst((string) $lead->source) }}</span><div class="muted-2xs" style="margin-top:4px;">{{ $lead->created_at->lokal()->format('d.m.Y H:i') }}</div></td>
                    <td><span class="badge">{{ $lead->stateLabel() }}</span></td>
                    <td style="min-width:170px;">
                        <form method="POST" action="{{ route('admin.leads.assign', $lead->id) }}" style="display:flex;gap:6px;align-items:center;">
                            @csrf
                            <select name="assigned_employee_id" aria-label="Zuständigen wählen" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12.5px;background:var(--surface);max-width:150px;">
                                <option value="">— niemand —</option>
                                @foreach($mitarbeiter as $m)<option value="{{ $m->id }}" @selected($lead->assigned_employee_id === $m->id)>{{ $m->name }}</option>@endforeach
                            </select>
                            <button type="submit" class="btn btn-ghost btn-sm" title="Zuständigkeit speichern">✓</button>
                        </form>
                    </td>
                    <td style="font-size:12px;padding-right:20px;min-width:170px;">
                        @if($lead->customer_id)
                            <a href="{{ route('admin.customer', $lead->customer_id) }}" class="btn btn-ghost btn-sm">👤 Zur Kundenakte</a>
                        @else
                            <div style="margin-bottom:6px;">{{ $lead->next_action ?: 'Interessent kontaktieren' }}</div>
                            <form method="POST" action="{{ route('admin.leads.convert', $lead->id) }}"
                                data-confirm="Aus diesem Interessenten einen Kunden anlegen? Gibt es bereits einen passenden Kunden, wird nichts angelegt.">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">In Kunden umwandeln</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="padding:14px 20px;">{{ $leads->links() }}</div>
    @endif
</div>
@endsection
