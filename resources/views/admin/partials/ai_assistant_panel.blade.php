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

    {{-- Wer ist gerade zustaendig - und ab wann wieder die KI? Ohne diese
         Zeile stand da nur "KI deaktiviert" und niemand wusste, dass der
         Kunde auf eine neue Frage keine automatische Antwort mehr bekommt
         (Lehre 20.08.2026). --}}
    @if(!$aiConversation->ai_active && ($aiSettings->autoResume() ?? false))
        @if(!$aiConversation->mayAutoResume())
            <div class="kx-ai-reason">🔒 Dauerhaft beim Team – die KI kommt nur über „KI wieder aktivieren“ zurück.</div>
        @elseif($aiConversation->resume_ticket_id || $aiConversation->resume_not_before)
            @php
                // Blade-Direktiven nie an ein Wort kleben ("Uhr@else") - sie
                // werden dann NICHT uebersetzt und die Seite bricht mit
                // einem Parse-Fehler ab. Text deshalb hier zusammenbauen.
                $wann = $aiConversation->resume_not_before
                    ? 'am ' . $aiConversation->resume_not_before->lokal()->format('d.m.Y H:i') . ' Uhr'
                    : 'nach der Ruhefrist';
                $satz = $aiConversation->resume_ticket_id
                    ? 'sobald der Vorgang abgeschlossen ist – spätestens ' . $wann
                    : $wann;
            @endphp
            <div class="kx-ai-next">
                🤖 Die KI übernimmt wieder, {{ $satz }}. Jede eigene Nachricht verlängert die Frist.
            </div>
        @endif
    @elseif($aiConversation->resumed_at)
        <div class="kx-ai-next">🤖 Die KI hat am {{ $aiConversation->resumed_at->lokal()->format('d.m.Y H:i') }} Uhr selbst wieder übernommen (Vorgang erledigt bzw. Ruhefrist abgelaufen).</div>
    @endif

    @if($uebergabe && $aiConversation->reasonLabel())
        <div class="kx-ai-reason">Übergabegrund: {{ $aiConversation->reasonLabel() }}@if($aiConversation->handover_at) · {{ $aiConversation->handover_at->lokal()->format('d.m.Y H:i') }}@endif</div>
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

    {{-- Der naechste Schritt bleibt IMMER sichtbar - er ist die eine
         Information, die der Mitarbeiter zum Weiterarbeiten braucht. --}}
    @if($briefing && $aiConversation->intent)
        <div class="kx-ai-next">Nächster Schritt: <strong>{{ $briefing['naechster_schritt'] }}</strong>@if($angebote->isEmpty() && $briefing['zustand'] !== 'Neu') · <span class="kx-ai-missing">noch kein Angebot hinterlegt</span>@endif</div>
    @endif

    {{-- Zusammenfassung und Vorgangsstand sind BEIDE einklappbar (Lehre
         19.08.2026 - gemeldet: "die Unterhaltung ist nicht zu sehen"): das
         Panel wuchs mit jedem Ausbau und drueckte den Nachrichtenverlauf im
         Flex-Layout auf null - der Mitarbeiter sah weder die Kundenfrage
         noch die eigene Antwort. Standard ist deshalb ZUGEKLAPPT; die
         Kopfzeile, ein etwaiger Uebergabegrund und eine Stoerung stehen
         immer da. Der Zustand wird je Browser gemerkt. --}}
    @if($aiConversation->summary || ($briefing && $aiConversation->intent))
    <details class="kx-ai-more" data-ki-panel>
        <summary>
            <span class="kx-ai-more-t">Zusammenfassung &amp; Vorgangsstand</span>
            @if($aiConversation->summary)
            <span class="kx-ai-more-p">{{ \Illuminate\Support\Str::limit(strtok($aiConversation->summary, "\n"), 90) }}</span>
            @endif
        </summary>

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
    </details>
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
