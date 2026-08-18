{{-- KI-Panel der Unterhaltung (Spezifikation Abschnitt 27).

     Der Mitarbeiter sieht auf einen Blick: antwortet die KI, was hat sie
     zuletzt getan, warum wurde uebergeben, was fehlt dem Kunden - und kann
     mit einem Klick uebernehmen oder die KI schalten.

     Die Zusammenfassung (Abschnitt 14) kommt aus echten Daten, nicht aus
     einer KI-Formulierung: sie ersetzt das Lesen des ganzen Chats.

     Erwartet: $active (Customer), $aiConversation (AiConversation),
     $aiSettings (AssistantSettings), $aiDocuments (Array aus
     DocumentStatusReader::overview), $aiLastLog (AiAssistantLog|null). --}}
@php
    $uebergabe = $aiConversation->handover_required;
    $aktiv = $aiSettings->enabled() && $aiConversation->canAutoReply();
    // Verkaufsassistent: Stand des Vorgangs (Abschnitte 12-16).
    $briefing = $aiBriefing ?? null;
    $angebote = $aiConversation->offers;
    $stoerung = $briefing['stoerung'] ?? null;
@endphp
<div class="kx-ai {{ $uebergabe ? 'handover' : ($aktiv ? 'on' : 'off') }}">
    <div class="kx-ai-head">
        <span class="kx-ai-state">
            @if(!$aiSettings->enabled())
                🤖 KI-Assistent systemweit aus
            @elseif($uebergabe)
                🔔 KI → Mitarbeiter erforderlich
            @elseif($aktiv)
                🤖 KI aktiv
            @else
                🤖 KI deaktiviert – Mitarbeiter übernimmt
            @endif
        </span>
        @if($aiConversation->employee && !$aiConversation->ai_active)
            <span class="kx-ai-emp">👤 {{ $aiConversation->employee->name }}</span>
        @endif
        <span class="kx-ai-btns">
            @if($uebergabe || $aiConversation->ai_active)
            <form method="POST" action="{{ route('admin.ai_assistant.take_over', $active->id) }}">
                @csrf
                <button type="submit" class="kx-ai-btn primary">Übernehmen</button>
            </form>
            @endif
            @if($aiConversation->ai_active)
            <form method="POST" action="{{ route('admin.ai_assistant.deactivate', $active->id) }}">
                @csrf
                <button type="submit" class="kx-ai-btn">KI deaktivieren</button>
            </form>
            @else
            <form method="POST" action="{{ route('admin.ai_assistant.reactivate', $active->id) }}">
                @csrf
                <button type="submit" class="kx-ai-btn">KI wieder aktivieren</button>
            </form>
            @endif
        </span>
    </div>

    @if($uebergabe && $aiConversation->reasonLabel())
        <div class="kx-ai-reason">Übergabegrund: {{ $aiConversation->reasonLabel() }}@if($aiConversation->handover_at) · {{ $aiConversation->handover_at->format('d.m.Y H:i') }}@endif</div>
    @endif

    {{-- Abschnitt 13: eine Stoerung darf NIE unsichtbar bleiben. Der
         Mitarbeiter sieht Grund, letzten erfolgreichen Schritt und den
         Schritt, an dem es scheiterte - und kann es erneut versuchen. --}}
    @if($stoerung)
        <div class="kx-ai-error">
            <strong>⚠️ KI angehalten</strong>
            <div>Grund: {{ $stoerung['grund'] ?: 'unbekannt' }}</div>
            @if($stoerung['letzter_schritt'])
                <div>Letzter erfolgreicher Schritt: {{ $stoerung['letzter_schritt'] }}</div>
            @endif
            @if($stoerung['aktueller_schritt'])
                <div>Gescheitert bei: {{ $stoerung['aktueller_schritt'] }}</div>
            @endif
            @if($stoerung['zeitpunkt'])
                <div>Zeitpunkt: {{ $stoerung['zeitpunkt'] }}</div>
            @endif
            <form method="POST" action="{{ route('admin.ai_assistant.retry', $active->id) }}" style="margin-top:8px;">
                @csrf
                <button type="submit" class="kx-ai-btn primary">Erneut versuchen</button>
            </form>
        </div>
    @endif

    @if($aiConversation->summary)
        <div class="kx-ai-sum">{{ $aiConversation->summary }}</div>
    @endif

    {{-- Vorgangsstand: der Mitarbeiter soll den Fall in Sekunden erfassen,
         ohne den Chat zu lesen (Abschnitt 15). --}}
    @if($briefing && $aiConversation->intent)
        <div class="kx-ai-sales">
            <div class="kx-ai-sales-head">
                <span class="kx-ai-badge">{{ $briefing['anliegen'] }}</span>
                <span class="kx-ai-badge state">{{ $briefing['zustand'] }}</span>
                <span class="kx-ai-badge">Angaben {{ $briefing['fortschritt'] }}</span>
                @if($briefing['kunde_hat_zugestimmt'])
                    <span class="kx-ai-badge ok">Kunde hat zugestimmt</span>
                @endif
                @if($briefing['pruefung'])
                    <span class="kx-ai-badge {{ $briefing['pruefung'] === 'VERIFICATION_PASSED' ? 'ok' : 'warn' }}">
                        Prüfung: {{ $briefing['pruefung'] === 'VERIFICATION_PASSED' ? 'bestanden' : ($briefing['pruefung'] === 'VERIFICATION_FAILED' ? 'abweichend' : 'offen') }}
                    </span>
                @endif
            </div>

            @if(!empty($briefing['bekannt']))
                <div class="kx-ai-kv">
                    @foreach($briefing['bekannt'] as $label => $wert)
                        <div><span>{{ $label }}:</span> {{ $wert }}</div>
                    @endforeach
                </div>
            @endif

            @if(!empty($briefing['fehlend']))
                <div class="kx-ai-missing">Noch offen: {{ implode(', ', $briefing['fehlend']) }}</div>
            @endif

            <div class="kx-ai-next">Nächster Schritt: <strong>{{ $briefing['naechster_schritt'] }}</strong></div>

            {{-- Angebote: Phase 1 hinterlegt sie der Mitarbeiter. Solange
                 keines vorliegt, nennt die KI bewusst keine Preise. --}}
            <div class="kx-ai-offers">
                @forelse($angebote as $angebot)
                    <div class="kx-ai-offer {{ $angebot->isSelected() ? 'chosen' : '' }}">
                        <span>{{ $angebot->summary() }}</span>
                        @if($angebot->isSelected())
                            <span class="kx-ai-badge ok">gewählt</span>
                        @else
                            <form method="POST" action="{{ route('admin.ai_assistant.offer.destroy', [$active->id, $angebot->id]) }}"
                                  onsubmit="return confirm('Angebot {{ $angebot->label }} entfernen?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="kx-ai-btn">Entfernen</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div class="kx-ai-missing">Noch kein Angebot hinterlegt – der Kunde wartet darauf.</div>
                @endforelse

                <details class="kx-ai-offerform">
                    <summary>Angebot hinterlegen</summary>
                    <form method="POST" action="{{ route('admin.ai_assistant.offer.store', $active->id) }}">
                        @csrf
                        <div class="kx-ai-grid">
                            <label>Kennung<input type="text" name="label" value="{{ chr(65 + $angebote->count()) }}" maxlength="10" required></label>
                            <label>Anbieter<input type="text" name="provider" maxlength="120"></label>
                            <label>Produkt/Tarif<input type="text" name="product" maxlength="160" required></label>
                            <label>Geschwindigkeit<input type="text" name="speed" maxlength="60" placeholder="z. B. 250 MBit/s"></label>
                            <label>Preis (EUR)<input type="number" name="price" step="0.01" min="0" max="99999"></label>
                            <label>Zeitraum<input type="text" name="price_period" value="Monat" maxlength="20"></label>
                            <label>Laufzeit (Monate)<input type="number" name="duration_months" min="0" max="120"></label>
                            <label class="wide">Bedingungen<input type="text" name="terms" maxlength="1000" placeholder="z. B. 3 Monate Aktionspreis"></label>
                        </div>
                        <button type="submit" class="kx-ai-btn primary">Angebot speichern</button>
                    </form>
                </details>
            </div>
        </div>
    @endif

    <div class="kx-ai-facts">
        @if(!empty($aiDocuments['fehlt']))
            <span class="kx-ai-fact warn">📄 Fehlt: {{ collect($aiDocuments['fehlt'])->pluck('titel')->join(', ') }}</span>
        @endif
        @if(!empty($aiDocuments['in_pruefung']))
            <span class="kx-ai-fact">🔎 In Prüfung: {{ collect($aiDocuments['in_pruefung'])->pluck('titel')->join(', ') }}</span>
        @endif
        @if($aiConversation->last_ai_action)
            <span class="kx-ai-fact">🕓 Letzte KI-Aktion: {{ $aiConversation->last_ai_action }}@if($aiConversation->last_ai_at) ({{ $aiConversation->last_ai_at->diffForHumans() }})@endif</span>
        @endif
        @if($aiLastLog)
            <span class="kx-ai-fact">📋 {{ $aiLastLog->outcomeLabel() }}@if($aiLastLog->tools) · {{ implode(', ', $aiLastLog->tools) }}@endif</span>
        @endif
        @if($aiConversation->auto_reply_count > 0)
            <span class="kx-ai-fact">💬 {{ $aiConversation->auto_reply_count }} automatische Antworten</span>
        @endif
    </div>
</div>
