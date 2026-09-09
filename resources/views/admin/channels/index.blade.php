@extends('layouts.admin')
@section('content')
@php
    $ampel = [
        'ok'   => ['var(--emerald)', 'rgba(23,166,91,.10)'],
        'warn' => ['var(--gold)', 'rgba(184,161,107,.14)'],
        'fail' => ['#C0392B', 'rgba(192,57,43,.10)'],
        'info' => ['#5F6B62', 'rgba(95,107,98,.10)'],
    ];
@endphp

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Kanäle</span></div>
    <div class="page-title">Kanäle</div>
    <div class="page-sub">
        Anbindungen, Geschäftskonten und KI-Betriebsart. Zugangsdaten werden verschlüsselt
        gespeichert und nie wieder angezeigt – nur „gesetzt" oder „fehlt".
    </div>
</div>

{{-- Globale KI-Betriebsart: die unterste Ebene der Hierarchie. --}}
<div class="card" style="margin-bottom:16px;">
    <div style="font-weight:700;margin-bottom:6px;">🤖 KI – globale Betriebsart</div>
    <div style="color:var(--text-muted);font-size:13px;margin-bottom:10px;">
        Gilt überall dort, wo Kanal, Kanalkonto, Kunde und Unterhaltung nichts Eigenes vorgeben.
        Der Hauptschalter unter Einstellungen bleibt die Notbremse und steht über allem.
    </div>
    <form method="POST" action="{{ route('admin.channels.ai_mode') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        @csrf @method('PUT')
        <select name="ai_mode" class="field" style="max-width:340px;">
            <option value="">Aus den bestehenden Schaltern ableiten (aktuell: {{ $modes[$globalMode] ?? $globalMode }})</option>
            @foreach($modes as $key => $label)
                <option value="{{ $key }}" @selected($globalModeExplicit === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-gold">Speichern</button>
    </form>
    <div style="color:var(--text-muted);font-size:12px;margin-top:8px;">
        @foreach($modeHints as $key => $hint)
            <div><strong>{{ $modes[$key] }}:</strong> {{ $hint }}</div>
        @endforeach
    </div>
</div>

@foreach($channels as $channel)
    @php
        $hatAdapter = $manager->has($channel->key);
        $ton = ! $hatAdapter ? 'info' : ($channel->is_active ? 'ok' : 'warn');
        $farbe = $ampel[$ton];
    @endphp
    <div class="card" style="margin-bottom:16px;border-left:5px solid {{ $farbe[0] }};">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div style="font-weight:700;font-size:16px;flex:1;min-width:200px;">
                {{ $channel->name }}
                <span style="color:var(--text-muted);font-weight:400;font-size:13px;">({{ $channel->key }})</span>
            </div>
            <span class="badge" style="background:{{ $farbe[1] }};color:{{ $farbe[0] }};">
                {{ $channel->is_active ? 'Aktiv' : 'Deaktiviert' }}
            </span>
            <span style="color:var(--text-muted);font-size:13px;">
                {{ $counts[$channel->id] ?? 0 }} Unterhaltung(en)
            </span>
        </div>

        @unless($hatAdapter)
            <div style="margin-top:8px;color:var(--text-muted);font-size:13px;">
                Für diesen Kanal ist noch kein Adapter registriert – er kann weder senden noch empfangen.
            </div>
        @endunless

        <form method="POST" action="{{ route('admin.channels.update', $channel->id) }}"
              style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px;">
            @csrf @method('PUT')
            <label style="display:flex;gap:6px;align-items:center;font-size:14px;">
                <input type="checkbox" name="is_active" value="1" @checked($channel->is_active)>
                Kanal aktiv
            </label>
            <select name="ai_mode" class="field" style="max-width:300px;">
                <option value="">KI: erben (global)</option>
                @foreach($modes as $key => $label)
                    <option value="{{ $key }}" @selected($channel->ai_mode === $key)>KI: {{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn">Speichern</button>
        </form>

        {{-- Konten dieses Kanals --}}
        <div style="margin-top:16px;">
            <div style="font-weight:600;margin-bottom:8px;">Geschäftskonten</div>

            @if($channel->key === 'whatsapp')
                @include('admin.channels._whatsapp_onboarding', [
                    'signupReady' => $signupReady,
                    'signupConfig' => $signupConfig,
                ])
            @endif

            @forelse($channel->accounts as $account)
                @php
                    $keys = array_keys($account->credentials ?? []);
                    $abgelaufen = $account->tokenExpired();
                @endphp
                <div style="border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:10px;">
                    <form method="POST" action="{{ route('admin.channels.accounts.update', $account->id) }}">
                        @csrf @method('PUT')
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                            <label style="flex:1;min-width:180px;">
                                <span style="font-size:12px;color:var(--text-muted);">Name</span>
                                <input type="text" name="name" class="field" value="{{ $account->name }}" required maxlength="120">
                            </label>
                            <label style="flex:1;min-width:180px;">
                                <span style="font-size:12px;color:var(--text-muted);">Kennung der Plattform</span>
                                <input type="text" name="external_account_id" class="field"
                                       value="{{ $account->external_account_id }}" maxlength="190">
                            </label>
                            <label style="flex:1;min-width:200px;">
                                <span style="font-size:12px;color:var(--text-muted);">KI-Betriebsart</span>
                                <select name="ai_mode" class="field">
                                    <option value="">erben (Kanal)</option>
                                    @foreach($modes as $key => $label)
                                        <option value="{{ $key }}" @selected($account->ai_mode === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label style="display:flex;gap:6px;align-items:center;font-size:14px;padding-bottom:8px;">
                                <input type="checkbox" name="is_active" value="1" @checked($account->is_active)>
                                aktiv
                            </label>
                        </div>

                        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                            <label style="flex:1;min-width:220px;">
                                <span style="font-size:12px;color:var(--text-muted);">
                                    Zugangs-Token
                                    @if(in_array('access_token', $keys, true))
                                        <strong style="color:var(--emerald);">· gesetzt</strong>
                                    @else
                                        <strong style="color:#C0392B;">· fehlt</strong>
                                    @endif
                                </span>
                                <input type="password" name="credentials[access_token]" class="field"
                                       autocomplete="new-password" placeholder="leer lassen = unverändert">
                            </label>
                            <span style="color:var(--text-muted);font-size:12px;padding-bottom:10px;">
                                @if($abgelaufen)
                                    <strong style="color:#C0392B;">Zugang abgelaufen
                                        ({{ $account->token_expires_at?->lokal()->format('d.m.Y') }})</strong>
                                @elseif($account->token_expires_at)
                                    gültig bis {{ $account->token_expires_at->lokal()->format('d.m.Y') }}
                                @else
                                    kein Ablauf hinterlegt
                                @endif
                            </span>
                            <button type="submit" class="btn btn-gold" style="margin-bottom:2px;">Speichern</button>
                        </div>
                    </form>

                    {{-- ZUSTAND UND ANBINDUNGSART - zwei getrennte Aussagen.
                         "Cloud API verbunden" ist NICHT "Coexistence verbunden";
                         das sind zwei Freigaben von Meta (Auftrag 35). --}}
                    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;
                                border-top:1px solid var(--line);padding-top:10px;">
                        <div style="min-width:220px;">
                            <span style="font-size:12px;color:var(--text-muted);">Verbindung</span><br>
                            @php $ton = \App\Support\ChannelConnection::tone($account->connection_status); @endphp
                            <strong style="color:{{ $ton === 'ok' ? 'var(--emerald)' : ($ton === 'error' ? '#C0392B' : ($ton === 'warn' ? '#92400E' : 'var(--text-muted)')) }};">
                                {{ $account->connectionLabel() }}
                            </strong>
                            @if($account->connection_error)
                                <div style="font-size:12px;color:#C0392B;">{{ $account->connection_error }}</div>
                            @endif
                            @if($account->tokenExpired())
                                <div style="font-size:12px;color:#C0392B;">
                                    <strong>Zugang abgelaufen</strong> — die Nummer muss neu verbunden werden.
                                </div>
                            @elseif($account->tokenExpiresSoon())
                                <div style="font-size:12px;color:#92400E;">
                                    <strong>Zugang läuft bald ab</strong>
                                    ({{ $account->token_expires_at->lokal()->format('d.m.Y') }})
                                    — bitte rechtzeitig neu verbinden.
                                </div>
                            @endif
                            @if($account->connection_checked_at)
                                <div style="font-size:11px;color:var(--text-muted);">
                                    geprüft {{ $account->connection_checked_at->lokal()->format('d.m.Y H:i') }}
                                </div>
                            @endif
                            @if($account->waba_id)
                                <div style="font-size:11px;color:var(--text-muted);">WABA {{ $account->waba_id }}</div>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.channels.accounts.connection_type', $account->id) }}"
                              style="display:flex;gap:6px;align-items:flex-end;">
                            @csrf @method('PUT')
                            <label style="min-width:240px;">
                                <span style="font-size:12px;color:var(--text-muted);">Anbindungsart</span>
                                <select name="connection_type" class="field">
                                    @foreach(\App\Support\ChannelConnection::TYPES as $key => $label)
                                        <option value="{{ $key }}" @selected($account->connection_type === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="btn" style="margin-bottom:2px;">Setzen</button>
                        </form>
                    </div>

                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                        <form method="POST" action="{{ route('admin.channels.accounts.test', $account->id) }}">
                            @csrf
                            <button type="submit" class="btn">Verbindung testen</button>
                        </form>
                        <form method="POST" action="{{ route('admin.channels.accounts.disconnect', $account->id) }}"
                              data-confirm="Zugangsdaten dieses Kontos löschen und es abschalten? Unterhaltungen und Nachrichten bleiben vollständig erhalten.">
                            @csrf
                            <button type="submit" class="btn">Zugang trennen</button>
                        </form>
                    </div>
                </div>
            @empty
                <div style="color:var(--text-muted);font-size:13px;margin-bottom:10px;">
                    Noch kein Konto hinterlegt.
                </div>
            @endforelse

            <details>
                <summary style="cursor:pointer;font-size:14px;">+ Konto hinzufügen</summary>
                <form method="POST" action="{{ route('admin.channels.accounts.store', $channel->id) }}"
                      style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    @csrf
                    <label style="flex:1;min-width:180px;">
                        <span style="font-size:12px;color:var(--text-muted);">Name</span>
                        <input type="text" name="name" class="field" required maxlength="120" placeholder="z. B. Geschäfts-Nummer 1">
                    </label>
                    <label style="flex:1;min-width:180px;">
                        <span style="font-size:12px;color:var(--text-muted);">Kennung der Plattform</span>
                        <input type="text" name="external_account_id" class="field" maxlength="190">
                    </label>
                    <label style="flex:1;min-width:200px;">
                        <span style="font-size:12px;color:var(--text-muted);">Zugangs-Token</span>
                        <input type="password" name="credentials[access_token]" class="field" autocomplete="new-password">
                    </label>
                    <label style="display:flex;gap:6px;align-items:center;font-size:14px;padding-bottom:8px;">
                        <input type="checkbox" name="is_active" value="1"> aktiv
                    </label>
                    <button type="submit" class="btn btn-gold" style="margin-bottom:2px;">Anlegen</button>
                </form>
            </details>
        </div>
    </div>
@endforeach
@endsection
