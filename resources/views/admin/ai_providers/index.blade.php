@extends('layouts.admin')
@section('content')

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>KI-Anbieter</span></div>
    <div class="page-title">KI-Anbieter</div>
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
