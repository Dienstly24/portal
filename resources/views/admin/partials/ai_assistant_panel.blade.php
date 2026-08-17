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

    @if($aiConversation->summary)
        <div class="kx-ai-sum">{{ $aiConversation->summary }}</div>
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
