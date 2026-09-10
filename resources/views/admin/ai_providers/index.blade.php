@extends('layouts.admin')
@section('content')

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>KI-Assistent</span></div>
    <div class="page-title">KI-Assistent</div>
    <div class="page-sub">
        Anbieter, Modell und Zugangsschlüssel – damit ein Wechsel nicht mehr bedeutet,
        die Server-Konfiguration anzufassen. Schlüssel werden verschlüsselt gespeichert
        und nie wieder angezeigt.
    </div>
</div>

{{-- Was gilt gerade, und WOHER kommt es. --}}
<div class="card" style="margin-bottom:16px;border-left:5px solid var(--emerald);">
    <div style="font-weight:700;margin-bottom:4px;">Aktuell in Betrieb</div>
    <div style="font-size:15px;">
        Anbieter: <strong>{{ $providers[$geltung['provider']] ?? $geltung['provider'] }}</strong>
        @if($geltung['model'])
            · Modell: <strong>{{ $geltung['model'] }}</strong>
        @endif
    </div>
    <div style="color:var(--ink-soft);font-size:13px;margin-top:4px;">
        Quelle: {{ $geltung['source'] }}
    </div>
    <div style="color:var(--ink-soft);font-size:12px;margin-top:8px;">
        Rangfolge: Notbremse in der .env (<code>AI_ASSISTANT_PROVIDER=none</code>) schlägt alles ·
        danach ein aktiver Zugang von dieser Seite · sonst die Server-.env.
        Solange hier nichts gepflegt ist, läuft alles wie bisher.
    </div>
</div>

@foreach($accounts as $account)
    <div class="card">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
            <strong style="font-size:14px;">{{ $account->name }}</strong>
            <span class="badge {{ $account->is_active ? 'badge-active' : 'badge-closed' }}">
                {{ $account->is_active ? 'Aktiv' : 'Inaktiv' }}
            </span>
            <span class="badge {{ $account->apiKey() ? 'badge-approved' : 'badge-danger' }}">
                Schlüssel {{ $account->apiKey() ? 'gesetzt' : 'fehlt' }}
            </span>
        </div>

        <form method="POST" action="{{ route('admin.ai_providers.update', $account->id) }}">
            @csrf @method('PUT')
            <div class="feld-raster">
                <div class="field">
                    <label for="zName{{ $account->id }}">Name</label>
                    <input type="text" name="name" id="zName{{ $account->id }}"
                           value="{{ $account->name }}" required maxlength="120">
                </div>
                <div class="field">
                    <label for="zAnbieter{{ $account->id }}">Anbieter</label>
                    <select name="provider" id="zAnbieter{{ $account->id }}">
                        @foreach($providers as $key => $label)
                            <option value="{{ $key }}" @selected($account->provider === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="zModell{{ $account->id }}">Modell <span class="feld-zusatz">(leer = Standard)</span></label>
                    <input type="text" name="model" id="zModell{{ $account->id }}"
                           value="{{ $account->model }}" maxlength="120">
                </div>
                <div class="field">
                    <label for="zKey{{ $account->id }}">Schlüssel</label>
                    <input type="password" name="api_key" id="zKey{{ $account->id }}"
                           autocomplete="new-password" placeholder="leer lassen = unverändert">
                </div>
            </div>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <label class="check-inline">
                    <input type="checkbox" name="is_active" value="1" @checked($account->is_active)>
                    <span>Zugang aktiv</span>
                </label>
                <button type="submit" class="btn btn-emerald">Speichern</button>
            </div>
        </form>

        <div class="karten-fuss">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="POST" action="{{ route('admin.ai_providers.test', $account->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost">Verbindung testen</button>
                </form>
                <form method="POST" action="{{ route('admin.ai_providers.destroy', $account->id) }}"
                      data-confirm="Diesen Zugang entfernen? Ohne gepflegten Zugang gilt wieder die Server-.env.">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger">Entfernen</button>
                </form>
            </div>
            @if($account->last_tested_at)
                <span style="color:var(--ink-soft);font-size:12px;">
                    Zuletzt getestet {{ $account->last_tested_at->lokal()->format('d.m.Y H:i') }}
                    · {{ $account->last_test_status }}
                </span>
            @endif
        </div>
    </div>
@endforeach

<div class="card">
    <div class="card-title" style="margin-bottom:14px;">Zugang hinzufügen</div>
    <form method="POST" action="{{ route('admin.ai_providers.store') }}">
        @csrf
        <div class="feld-raster">
            <div class="field">
                <label for="neuName">Name</label>
                <input type="text" name="name" id="neuName" required maxlength="120"
                       placeholder="z. B. Anthropic produktiv">
            </div>
            <div class="field">
                <label for="neuAnbieter">Anbieter</label>
                <select name="provider" id="neuAnbieter">
                    @foreach($providers as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="neuModell">Modell <span class="feld-zusatz">(leer = Standard)</span></label>
                <input type="text" name="model" id="neuModell" maxlength="120">
            </div>
            <div class="field">
                <label for="neuKey">Schlüssel</label>
                <input type="password" name="api_key" id="neuKey" autocomplete="new-password">
            </div>
        </div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <label class="check-inline">
                <input type="checkbox" name="is_active" value="1">
                <span>sofort aktiv</span>
            </label>
            <button type="submit" class="btn btn-emerald">Anlegen</button>
        </div>
    </form>
</div>

{{-- Geschäftszeiten (Abschnitt 64) --}}
<div class="card">
    <div class="card-title" style="margin-bottom:6px;">Geschäftszeiten</div>
    <p style="color:var(--ink-soft);font-size:13px;margin:0 0 14px;line-height:1.55;">
        Außerhalb der Geschäftszeiten antwortet die KI nicht inhaltlich. Der Kunde bekommt
        den hinterlegten Abwesenheitshinweis (höchstens einmal je Unterhaltung und 12 Stunden),
        und der Vorgang liegt am Morgen im Posteingang.
        Zeiten gelten in <strong>{{ $displayTimezone }}</strong>.
    </p>

    @if($hoursEnforced)
        <div class="{{ $hoursOpenNow ? 'alert-success' : 'alert-warning' }}">
            Aktuell <strong>{{ $hoursOpenNow ? 'geöffnet' : 'geschlossen' }}</strong>.
        </div>
    @else
        <div class="alert-info">
            Die Regel ist derzeit <strong>ausgeschaltet</strong> — die KI antwortet rund um die Uhr.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.ai_providers.hours') }}">
        @csrf @method('PUT')
        <label class="check-inline" style="margin-bottom:14px;">
            <input type="checkbox" name="enforced" value="1" @checked($hoursEnforced)>
            <span>Geschäftszeiten beachten</span>
        </label>

        <div class="zeit-tabelle">
            @foreach($days as $key => $label)
                @php $tag = $hours[$key]; @endphp
                <div class="zeit-zeile">
                    <label class="check-inline zeit-tag">
                        <input type="checkbox" name="days[{{ $key }}][open]" value="1" @checked($tag['open'])>
                        <span>{{ $label }}</span>
                    </label>
                    <input type="time" name="days[{{ $key }}][from]" class="eingabe zeit-feld"
                           value="{{ $tag['from'] }}" aria-label="{{ $label }} von">
                    <span style="color:var(--ink-soft);font-size:13px;">bis</span>
                    <input type="time" name="days[{{ $key }}][to]" class="eingabe zeit-feld"
                           value="{{ $tag['to'] }}" aria-label="{{ $label }} bis">
                </div>
            @endforeach
        </div>

        <p style="color:var(--ink-soft);font-size:12px;margin:14px 0;line-height:1.5;">
            Ein Ende vor dem Anfang (z. B. 20:00 bis 02:00) gilt als Zeitraum über Mitternacht.
            Feiertage werden bewusst nicht geführt — eine halbe Feiertagsliste wäre schlechter
            als keine, weil sie an Ostern „geöffnet“ behaupten würde.
        </p>
        <button type="submit" class="btn btn-emerald">Geschäftszeiten speichern</button>
    </form>
</div>

{{-- Textbausteine (Abschnitt 65) --}}
<div class="card">
    <div class="card-title" style="margin-bottom:6px;">Textbausteine</div>
    <p style="color:var(--ink-soft);font-size:13px;margin:0 0 16px;line-height:1.55;">
        Leer lassen = die geprüfte Vorgabe gilt. Der Text folgt der <strong>erkannten Sprache
        der Kundennachricht</strong>, nicht der Oberflächensprache — wer nur Deutsch pflegt,
        bekommt für Arabisch weiterhin die Vorgabe und nie einen deutschen Text an einen
        arabisch schreibenden Kunden.
    </p>

    <form method="POST" action="{{ route('admin.ai_providers.texts') }}">
        @csrf @method('PUT')
        @foreach($texts as $key => $sprachen)
            <div class="baustein">
                <div class="baustein-titel">{{ $textLabels[$key] ?? $key }}</div>
                <div class="baustein-sprachen">
                    @foreach($languages as $sprache)
                        @php $eintrag = $sprachen[$sprache]; @endphp
                        <div class="field" style="margin-bottom:0;">
                            <label for="txt{{ $key }}{{ $sprache }}">{{ strtoupper($sprache) }}</label>
                            <textarea name="texts[{{ $key }}][{{ $sprache }}]" id="txt{{ $key }}{{ $sprache }}"
                                      rows="3" maxlength="2000"
                                      @if($sprache === 'ar') dir="rtl" @endif
                                      placeholder="{{ $eintrag['default'] }}">{{ $eintrag['custom'] }}</textarea>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        <button type="submit" class="btn btn-emerald">Textbausteine speichern</button>
    </form>
</div>

@push('styles')
<style @cspNonce>
    /* Bausteine dieser Seite - sie beschreiben die KI-Einstellungen,
       nicht das ganze System, und stehen deshalb nicht in components.css. */
    .feld-raster {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0 16px;
    }
    .feld-zusatz { color: var(--ink-soft); font-weight: 400; }
    .karten-fuss {
        display: flex; gap: 16px; flex-wrap: wrap;
        justify-content: space-between; align-items: center;
        border-top: 1px solid var(--line); margin-top: 4px; padding-top: 14px;
    }
    .check-inline { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; cursor: pointer; }
    .check-inline input { width: 16px; height: 16px; accent-color: var(--emerald); }

    /* Die Woche als TABELLE: sieben Zeilen mit gleicher Spaltenbreite.
       Vorher sprangen die Felder je nach Tagesname unterschiedlich weit
       ein - man konnte die Zeiten nicht untereinander vergleichen. */
    .zeit-tabelle { display: grid; gap: 8px; }
    .zeit-zeile {
        display: grid;
        grid-template-columns: 200px 130px auto 130px;
        gap: 10px; align-items: center;
        max-width: 560px;
    }
    .zeit-tag { min-width: 0; }
    /* Aussehen kommt aus .eingabe - hier nur die Breite in der Zeile. */
    .zeit-feld { width: 100%; }
    @media (max-width: 640px) {
        .zeit-zeile { grid-template-columns: 1fr 1fr; }
        .zeit-tag { grid-column: 1 / -1; }
    }

    .baustein { border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 12px; background: var(--surface-soft); }
    .baustein-titel { font-weight: 600; font-size: 13.5px; margin-bottom: 12px; }
    .baustein-sprachen { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
</style>
@endpush
@endsection
