@extends('layouts.admin')
@section('content')

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Kanäle</span></div>
    <div class="page-title">Kanäle</div>
    <div class="page-sub">
        Anbindungen, Geschäftskonten und KI-Betriebsart. Zugangsdaten werden verschlüsselt
        gespeichert und nie wieder angezeigt — nur „gesetzt“ oder „fehlt“.
    </div>
</div>

{{--
  Globale KI-Betriebsart: die unterste Ebene der Hierarchie.
  Steht bewusst GANZ OBEN und einmal - sie gilt fuer alle Kanaele
  darunter, und wer sie am Ende der Seite suchen muss, findet sie nicht.
--}}
<div class="card">
    <div class="card-title" style="margin-bottom:6px;">🤖 KI – globale Betriebsart</div>
    <p style="color:var(--ink-soft);font-size:13px;margin:0 0 16px;">
        Gilt überall dort, wo Kanal, Kanalkonto, Kunde und Unterhaltung nichts Eigenes vorgeben.
        Der Hauptschalter unter Einstellungen bleibt die Notbremse und steht über allem.
    </p>

    <form method="POST" action="{{ route('admin.channels.ai_mode') }}">
        @csrf @method('PUT')
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="field" style="flex:1;min-width:280px;max-width:560px;margin-bottom:0;">
                <label for="globalAiMode">Betriebsart</label>
                <select name="ai_mode" id="globalAiMode">
                    <option value="">Aus den bestehenden Schaltern ableiten (aktuell: {{ $modes[$globalMode] ?? $globalMode }})</option>
                    @foreach($modes as $key => $label)
                        <option value="{{ $key }}" @selected($globalModeExplicit === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-emerald">Speichern</button>
        </div>
    </form>

    <dl style="margin:16px 0 0;font-size:12.5px;color:var(--ink-soft);display:grid;gap:5px;">
        @foreach($modeHints as $key => $hint)
            <div><strong style="color:var(--ink);">{{ $modes[$key] }}:</strong> {{ $hint }}</div>
        @endforeach
    </dl>
</div>

@foreach($channels as $channel)
    @php
        $hatAdapter = $manager->has($channel->key);
        // Drei Zustaende, drei Aussagen: ohne Adapter ist der Kanal nicht
        // "aus", sondern gar nicht anschlussfaehig - das ist etwas anderes.
        $badge = ! $hatAdapter ? 'badge-closed' : ($channel->is_active ? 'badge-active' : 'badge-pending');
        $badgeText = ! $hatAdapter ? 'Kein Adapter' : ($channel->is_active ? 'Aktiv' : 'Deaktiviert');
    @endphp

    <div class="card">
        <div class="card-header" style="margin-bottom:6px;">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <span class="card-title" style="font-size:16px;">{{ $channel->name }}</span>
                <span class="badge {{ $badge }}">{{ $badgeText }}</span>
            </div>
            <span style="color:var(--ink-soft);font-size:12.5px;white-space:nowrap;">
                {{ $counts[$channel->id] ?? 0 }} Unterhaltung(en)
            </span>
        </div>

        @unless($hatAdapter)
            <div class="alert-info" style="margin-bottom:16px;">
                Für diesen Kanal ist noch kein Adapter registriert — er kann weder senden noch empfangen.
            </div>
        @endunless

        <form method="POST" action="{{ route('admin.channels.update', $channel->id) }}"
              style="border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:16px;">
            @csrf @method('PUT')
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div class="field" style="flex:1;min-width:240px;max-width:340px;margin-bottom:0;">
                    <label for="aiMode{{ $channel->id }}">KI-Betriebsart dieses Kanals</label>
                    <select name="ai_mode" id="aiMode{{ $channel->id }}">
                        <option value="">erben (global)</option>
                        @foreach($modes as $key => $label)
                            <option value="{{ $key }}" @selected($channel->ai_mode === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="check-inline">
                    <input type="checkbox" name="is_active" value="1" @checked($channel->is_active)>
                    <span>Kanal aktiv</span>
                </label>
                <button type="submit" class="btn btn-ghost">Speichern</button>
            </div>
        </form>

        <div class="card-title" style="font-size:13.5px;margin-bottom:12px;">Geschäftskonten</div>

        @if($channel->key === 'whatsapp')
            @include('admin.channels._whatsapp_onboarding', [
                'signupReady' => $signupReady,
                'signupConfig' => $signupConfig,
            ])
        @endif

        @forelse($channel->accounts as $account)
            @php
                $keys = array_keys($account->credentials ?? []);
                $tokenGesetzt = in_array('access_token', $keys, true);
                $ton = \App\Support\ChannelConnection::tone($account->connection_status);
                $verbindungsBadge = match ($ton) {
                    'ok' => 'badge-active',
                    'error' => 'badge-danger',
                    'warn' => 'badge-pending',
                    default => 'badge-closed',
                };
            @endphp

            <div class="konto-karte">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
                    <strong style="font-size:14px;">{{ $account->name }}</strong>
                    <span class="badge {{ $verbindungsBadge }}">{{ $account->connectionLabel() }}</span>
                    @unless($account->is_active)
                        <span class="badge badge-closed">Konto inaktiv</span>
                    @endunless
                    @if($account->waba_id)
                        <span style="color:var(--ink-soft);font-size:11.5px;">WABA {{ $account->waba_id }}</span>
                    @endif
                </div>

                {{-- Was der Betreiber WISSEN muss, bevor er etwas aendert:
                     Fehler, Ablauf, letzte Pruefung. Steht deshalb VOR den
                     Feldern, nicht darunter. --}}
                @if($account->connection_error)
                    <div class="alert alert-error">{{ $account->connection_error }}</div>
                @endif
                @if($account->tokenExpired())
                    <div class="alert alert-error">
                        <strong>Zugang abgelaufen</strong>
                        ({{ $account->token_expires_at?->lokal()->format('d.m.Y') }})
                        — die Nummer muss neu verbunden werden.
                    </div>
                @elseif($account->tokenExpiresSoon())
                    <div class="alert alert-warning">
                        <strong>Zugang läuft bald ab</strong>
                        ({{ $account->token_expires_at->lokal()->format('d.m.Y') }})
                        — bitte rechtzeitig neu verbinden.
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.channels.accounts.update', $account->id) }}">
                    @csrf @method('PUT')
                    <div class="feld-raster">
                        <div class="field">
                            <label for="name{{ $account->id }}">Name</label>
                            <input type="text" name="name" id="name{{ $account->id }}"
                                   value="{{ $account->name }}" required maxlength="120">
                        </div>
                        <div class="field">
                            <label for="ext{{ $account->id }}">Kennung der Plattform</label>
                            <input type="text" name="external_account_id" id="ext{{ $account->id }}"
                                   value="{{ $account->external_account_id }}" maxlength="190">
                        </div>
                        <div class="field">
                            <label for="kontoAi{{ $account->id }}">KI-Betriebsart</label>
                            <select name="ai_mode" id="kontoAi{{ $account->id }}">
                                <option value="">erben (Kanal)</option>
                                @foreach($modes as $key => $label)
                                    <option value="{{ $key }}" @selected($account->ai_mode === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="token{{ $account->id }}">
                                Zugangs-Token
                                @if($tokenGesetzt)
                                    <span style="color:var(--emerald);font-weight:600;">· gesetzt</span>
                                @else
                                    <span style="color:#A32D2D;font-weight:600;">· fehlt</span>
                                @endif
                            </label>
                            <input type="password" name="credentials[access_token]" id="token{{ $account->id }}"
                                   autocomplete="new-password" placeholder="leer lassen = unverändert">
                            <div class="feld-hinweis">
                                @if($account->tokenExpired() || $account->tokenExpiresSoon())
                                    gültig bis {{ $account->token_expires_at->lokal()->format('d.m.Y') }}
                                @elseif($account->token_expires_at)
                                    gültig bis {{ $account->token_expires_at->lokal()->format('d.m.Y') }}
                                @else
                                    kein Ablauf hinterlegt
                                @endif
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                        <label class="check-inline">
                            <input type="checkbox" name="is_active" value="1" @checked($account->is_active)>
                            <span>Konto aktiv</span>
                        </label>
                        <button type="submit" class="btn btn-emerald">Speichern</button>
                    </div>
                </form>

                {{-- ANBINDUNGSART - eigene Aussage, eigener Speicherweg.
                     "Cloud API verbunden" ist NICHT "Coexistence verbunden";
                     das sind zwei Freigaben von Meta (Auftrag 35). --}}
                <div class="konto-fuss">
                    <form method="POST" action="{{ route('admin.channels.accounts.connection_type', $account->id) }}"
                          style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                        @csrf @method('PUT')
                        <div class="field" style="min-width:240px;margin-bottom:0;">
                            <label for="art{{ $account->id }}">Anbindungsart</label>
                            <select name="connection_type" id="art{{ $account->id }}">
                                @foreach(\App\Support\ChannelConnection::TYPES as $key => $label)
                                    <option value="{{ $key }}" @selected($account->connection_type === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-ghost">Setzen</button>
                    </form>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        <form method="POST" action="{{ route('admin.channels.accounts.test', $account->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">Verbindung testen</button>
                        </form>
                        <form method="POST" action="{{ route('admin.channels.accounts.disconnect', $account->id) }}"
                              data-confirm="Zugangsdaten dieses Kontos löschen und es abschalten? Unterhaltungen und Nachrichten bleiben vollständig erhalten.">
                            @csrf
                            <button type="submit" class="btn btn-danger">Zugang trennen</button>
                        </form>
                    </div>
                </div>

                @if($account->connection_checked_at)
                    <div style="font-size:11px;color:var(--ink-soft);margin-top:10px;">
                        zuletzt geprüft {{ $account->connection_checked_at->lokal()->format('d.m.Y H:i') }}
                    </div>
                @endif
            </div>
        @empty
            <p style="color:var(--ink-soft);font-size:13px;margin:0 0 12px;">
                Noch kein Konto hinterlegt.
            </p>
        @endforelse

        <details class="konto-neu">
            <summary>+ Konto von Hand hinterlegen</summary>
            <form method="POST" action="{{ route('admin.channels.accounts.store', $channel->id) }}" style="margin-top:14px;">
                @csrf
                <div class="feld-raster">
                    <div class="field">
                        <label for="neuName{{ $channel->id }}">Name</label>
                        <input type="text" name="name" id="neuName{{ $channel->id }}" required
                               maxlength="120" placeholder="z. B. Geschäfts-Nummer 1">
                    </div>
                    <div class="field">
                        <label for="neuExt{{ $channel->id }}">Kennung der Plattform</label>
                        <input type="text" name="external_account_id" id="neuExt{{ $channel->id }}" maxlength="190">
                    </div>
                    <div class="field">
                        <label for="neuToken{{ $channel->id }}">Zugangs-Token</label>
                        <input type="password" name="credentials[access_token]" id="neuToken{{ $channel->id }}"
                               autocomplete="new-password">
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
        </details>
    </div>
@endforeach

@push('styles')
<style @cspNonce>
    /* Bausteine dieser Seite. Bewusst hier und nicht in components.css:
       sie beschreiben die Kanal-Verwaltung, nicht das ganze System. */
    .konto-karte {
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 12px;
        background: var(--surface-soft);
    }
    .konto-fuss {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: flex-end;
        border-top: 1px solid var(--line);
        margin-top: 4px;
        padding-top: 14px;
    }
    /* Ein Raster statt flex-wrap: die Felder stehen damit in einer Spalte
       untereinander, statt je nach Fensterbreite umzuspringen. */
    .feld-raster {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0 16px;
    }
    .feld-hinweis { font-size: 11.5px; color: var(--ink-soft); margin-top: -12px; margin-bottom: 4px; }
    .check-inline { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; cursor: pointer; }
    .check-inline input { width: 16px; height: 16px; accent-color: var(--emerald); }
    .konto-neu > summary {
        cursor: pointer; font-size: 13px; color: var(--ink-soft);
        padding: 8px 0; list-style: none; font-weight: 500;
    }
    .konto-neu > summary::-webkit-details-marker { display: none; }
    .konto-neu > summary:hover { color: var(--emerald); }
</style>
@endpush
@endsection
