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
    <div style="color:var(--text-muted);font-size:13px;margin-top:4px;">
        Quelle: {{ $geltung['source'] }}
    </div>
    <div style="color:var(--text-muted);font-size:12px;margin-top:8px;">
        Rangfolge: Notbremse in der .env (<code>AI_ASSISTANT_PROVIDER=none</code>) schlägt alles ·
        danach ein aktiver Zugang von dieser Seite · sonst die Server-.env.
        Solange hier nichts gepflegt ist, läuft alles wie bisher.
    </div>
</div>

@foreach($accounts as $account)
    <div class="card" style="margin-bottom:12px;{{ $account->is_active ? 'border-left:5px solid var(--emerald);' : '' }}">
        <form method="POST" action="{{ route('admin.ai_providers.update', $account->id) }}">
            @csrf @method('PUT')
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <label style="flex:1;min-width:170px;">
                    <span style="font-size:12px;color:var(--text-muted);">Name</span>
                    <input type="text" name="name" class="field" value="{{ $account->name }}" required maxlength="120">
                </label>
                <label style="flex:1;min-width:170px;">
                    <span style="font-size:12px;color:var(--text-muted);">Anbieter</span>
                    <select name="provider" class="field">
                        @foreach($providers as $key => $label)
                            <option value="{{ $key }}" @selected($account->provider === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="flex:1;min-width:170px;">
                    <span style="font-size:12px;color:var(--text-muted);">Modell (leer = Standard)</span>
                    <input type="text" name="model" class="field" value="{{ $account->model }}" maxlength="120">
                </label>
                <label style="flex:1;min-width:200px;">
                    <span style="font-size:12px;color:var(--text-muted);">
                        Schlüssel
                        @if($account->apiKey())
                            <strong style="color:var(--emerald);">· gesetzt</strong>
                        @else
                            <strong style="color:#C0392B;">· fehlt</strong>
                        @endif
                    </span>
                    <input type="password" name="api_key" class="field" autocomplete="new-password"
                           placeholder="leer lassen = unverändert">
                </label>
                <label style="display:flex;gap:6px;align-items:center;font-size:14px;padding-bottom:8px;">
                    <input type="checkbox" name="is_active" value="1" @checked($account->is_active)>
                    aktiv
                </label>
                <button type="submit" class="btn btn-gold" style="margin-bottom:2px;">Speichern</button>
            </div>
        </form>

        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center;">
            <form method="POST" action="{{ route('admin.ai_providers.test', $account->id) }}">
                @csrf
                <button type="submit" class="btn">Verbindung testen</button>
            </form>
            <form method="POST" action="{{ route('admin.ai_providers.destroy', $account->id) }}"
                  data-confirm="Diesen Zugang entfernen? Ohne gepflegten Zugang gilt wieder die Server-.env.">
                @csrf @method('DELETE')
                <button type="submit" class="btn">Entfernen</button>
            </form>
            @if($account->last_tested_at)
                <span style="color:var(--text-muted);font-size:12px;">
                    Zuletzt getestet {{ $account->last_tested_at->lokal()->format('d.m.Y H:i') }}
                    · {{ $account->last_test_status }}
                </span>
            @endif
        </div>
    </div>
@endforeach

<div class="card">
    <div style="font-weight:700;margin-bottom:8px;">Zugang hinzufügen</div>
    <form method="POST" action="{{ route('admin.ai_providers.store') }}"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        @csrf
        <label style="flex:1;min-width:170px;">
            <span style="font-size:12px;color:var(--text-muted);">Name</span>
            <input type="text" name="name" class="field" required maxlength="120" placeholder="z. B. Anthropic produktiv">
        </label>
        <label style="flex:1;min-width:170px;">
            <span style="font-size:12px;color:var(--text-muted);">Anbieter</span>
            <select name="provider" class="field">
                @foreach($providers as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label style="flex:1;min-width:170px;">
            <span style="font-size:12px;color:var(--text-muted);">Modell (leer = Standard)</span>
            <input type="text" name="model" class="field" maxlength="120">
        </label>
        <label style="flex:1;min-width:200px;">
            <span style="font-size:12px;color:var(--text-muted);">Schlüssel</span>
            <input type="password" name="api_key" class="field" autocomplete="new-password">
        </label>
        <label style="display:flex;gap:6px;align-items:center;font-size:14px;padding-bottom:8px;">
            <input type="checkbox" name="is_active" value="1"> sofort aktiv
        </label>
        <button type="submit" class="btn btn-gold" style="margin-bottom:2px;">Anlegen</button>
    </form>
</div>
@endsection

{{-- Geschäftszeiten (Abschnitt 64) --}}
<div class="card" style="margin-top:16px;">
    <div style="font-weight:700;margin-bottom:4px;">Geschäftszeiten</div>
    <div style="color:var(--text-muted);font-size:13px;margin-bottom:10px;">
        Außerhalb der Geschäftszeiten antwortet die KI nicht inhaltlich. Der Kunde bekommt
        den hinterlegten Abwesenheitshinweis (höchstens einmal je Unterhaltung und 12 Stunden),
        und der Vorgang liegt am Morgen im Posteingang.
        Zeiten gelten in <strong>{{ $displayTimezone }}</strong>.
        @if($hoursEnforced)
            Aktuell: <strong style="color:{{ $hoursOpenNow ? 'var(--emerald)' : '#C0392B' }};">
                {{ $hoursOpenNow ? 'geöffnet' : 'geschlossen' }}</strong>.
        @else
            Die Regel ist derzeit <strong>ausgeschaltet</strong> – die KI antwortet rund um die Uhr.
        @endif
    </div>

    <form method="POST" action="{{ route('admin.ai_providers.hours') }}">
        @csrf @method('PUT')
        <label style="display:flex;gap:6px;align-items:center;font-size:14px;margin-bottom:12px;">
            <input type="checkbox" name="enforced" value="1" @checked($hoursEnforced)>
            Geschäftszeiten beachten
        </label>

        @foreach($days as $key => $label)
            @php $tag = $hours[$key]; @endphp
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px;">
                <label style="display:flex;gap:6px;align-items:center;min-width:150px;font-size:14px;">
                    <input type="checkbox" name="days[{{ $key }}][open]" value="1" @checked($tag['open'])>
                    {{ $label }}
                </label>
                <input type="time" name="days[{{ $key }}][from]" class="field"
                       value="{{ $tag['from'] }}" style="max-width:130px;">
                <span style="color:var(--text-muted);">bis</span>
                <input type="time" name="days[{{ $key }}][to]" class="field"
                       value="{{ $tag['to'] }}" style="max-width:130px;">
            </div>
        @endforeach

        <div style="color:var(--text-muted);font-size:12px;margin:8px 0 12px;">
            Ein Ende vor dem Anfang (z. B. 20:00 bis 02:00) gilt als Zeitraum über Mitternacht.
            Feiertage werden bewusst nicht geführt – eine halbe Feiertagsliste wäre schlechter
            als keine, weil sie an Ostern „geöffnet" behaupten würde.
        </div>
        <button type="submit" class="btn btn-gold">Geschäftszeiten speichern</button>
    </form>
</div>

{{-- Textbausteine (Abschnitt 65) --}}
<div class="card" style="margin-top:16px;">
    <div style="font-weight:700;margin-bottom:4px;">Textbausteine</div>
    <div style="color:var(--text-muted);font-size:13px;margin-bottom:12px;">
        Leer lassen = die geprüfte Vorgabe gilt. Der Text folgt der <strong>erkannten Sprache
        der Kundennachricht</strong>, nicht der Oberflächensprache – wer nur Deutsch pflegt,
        bekommt für Arabisch weiterhin die Vorgabe und nie einen deutschen Text an einen
        arabisch schreibenden Kunden.
    </div>

    <form method="POST" action="{{ route('admin.ai_providers.texts') }}">
        @csrf @method('PUT')
        @foreach($texts as $key => $sprachen)
            <div style="border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:10px;">
                <div style="font-weight:600;margin-bottom:8px;">{{ $textLabels[$key] ?? $key }}</div>
                @foreach($languages as $sprache)
                    @php $eintrag = $sprachen[$sprache]; @endphp
                    <label style="display:block;margin-bottom:8px;">
                        <span style="font-size:12px;color:var(--text-muted);text-transform:uppercase;">{{ $sprache }}</span>
                        <textarea name="texts[{{ $key }}][{{ $sprache }}]" class="field" rows="2"
                                  maxlength="2000"
                                  placeholder="{{ $eintrag['default'] }}">{{ $eintrag['custom'] }}</textarea>
                    </label>
                @endforeach
            </div>
        @endforeach
        <button type="submit" class="btn btn-gold">Textbausteine speichern</button>
    </form>
</div>
