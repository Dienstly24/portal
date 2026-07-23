@extends('layouts.admin')
@section('content')
{{-- Zentraler Kunden-Chat: links alle Unterhaltungen (sichtbares
     Portfolio), rechts der Verlauf im Messenger-Stil. Nutzt dieselben
     d24c-Bausteine wie der Portal-Chat des Kunden. --}}
@include('partials.chat_styles')
<style>
.kchat{display:grid;grid-template-columns:340px minmax(0,1fr);background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden;height:calc(100vh - var(--header-h) - 56px);min-height:460px;}
.kchat-side{border-inline-end:1px solid var(--line);display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--surface);}
.kchat-side-head{padding:14px 14px 10px;border-bottom:1px solid var(--line);}
.kchat-title{font-size:15px;font-weight:700;margin-bottom:10px;}
.kchat-search input{width:100%;padding:9px 13px;border:1px solid var(--line);border-radius:999px;font-size:13px;background:var(--canvas);color:var(--ink);}
.kchat-search input:focus{outline:2px solid var(--gold);outline-offset:1px;background:#fff;}
.kchat-convs{overflow-y:auto;flex:1;}
.kchat-group{font-size:11px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;padding:12px 14px 6px;}
.kchat-conv{display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink);align-items:center;transition:background .12s;}
.kchat-conv:hover{background:var(--canvas);}
.kchat-conv.active{background:#E7F4EC;}
.kchat-conv .d24c-av{width:40px;height:40px;font-size:13px;}
.kchat-meta{min-width:0;flex:1;}
.kchat-name{display:flex;justify-content:space-between;gap:8px;font-size:13.5px;font-weight:700;}
.kchat-time{font-size:11px;color:var(--ink-soft);font-weight:500;flex:none;}
.kchat-snippet{font-size:12.5px;color:var(--ink-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.kchat-unread{background:#E24B4A;color:#fff;border-radius:999px;font-size:11px;font-weight:800;min-width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;flex:none;}
.kchat-none{padding:26px 18px;text-align:center;color:var(--ink-soft);font-size:13.5px;line-height:1.7;}
.kchat-thread{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--surface);position:relative;}
.kchat-head{display:flex;align-items:center;gap:11px;padding:11px 14px;background:linear-gradient(135deg,var(--petrol),var(--petrol-dark));color:#fff;}
.kchat-head-name{font-weight:700;font-size:14.5px;}
.kchat-head-sub{font-size:11.5px;color:var(--akzent-hell);}
.kchat-head-link{margin-left:auto;color:rgba(255,255,255,.75);font-size:12.5px;text-decoration:none;border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:5px 12px;flex:none;}
.kchat-head-link:hover{background:rgba(255,255,255,.1);color:#fff;}
.kchat-back{display:none;color:rgba(255,255,255,.8);font-size:19px;text-decoration:none;flex:none;width:34px;height:34px;border-radius:9px;align-items:center;justify-content:center;}
.kchat-opts{display:flex;gap:10px;align-items:center;padding:8px 12px;background:var(--surface);border-top:1px solid var(--line);flex-wrap:wrap;}
.kchat-opts select{padding:7px 11px;border:1px solid var(--line);border-radius:999px;font-size:12.5px;background:#fff;color:var(--ink);max-width:240px;}
.kchat-opts .opt-label{font-size:12px;color:var(--ink-soft);}
.kchat-idle{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--ink-soft);background:#EFEBDF;text-align:center;padding:20px;}
.kchat-idle .big{font-size:44px;}
/* Omnichannel-Leiste: Kanal-Filter links, Schnellaktionen rechts */
.kx-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 12px;border-bottom:1px solid var(--line);background:var(--surface);flex-wrap:wrap;}
.kx-filters{display:flex;gap:6px;flex-wrap:wrap;}
.kx-f{border:1px solid var(--line);background:#fff;border-radius:999px;padding:4px 11px;font-size:12px;font-weight:600;color:var(--ink-soft);cursor:pointer;}
.kx-f.active{background:var(--petrol);border-color:var(--petrol);color:#fff;}
.kx-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.kx-status{display:inline-flex;gap:6px;align-items:center;font-size:12px;color:var(--ink-soft);margin:0;}
.kx-status select{padding:5px 9px;border:1px solid var(--line);border-radius:999px;font-size:12px;background:#fff;}
.kx-note-btn{border:1px solid #E7D9A8;background:#FBF6E4;border-radius:999px;padding:4px 11px;font-size:12px;font-weight:600;color:#8a7846;cursor:pointer;}
.kx-ticket-btn{border:1px solid var(--petrol);background:var(--petrol);color:#fff;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;}
.kx-ticket-btn:hover{background:var(--petrol-dark);}
/* Problem-Cockpit: aktiver Vorgang als Balken unter dem Kopf */
.kx-cockpit{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:8px 14px;background:#F3F1E8;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink);font-size:12.5px;}
.kx-cockpit:hover{background:#ECE9DC;}
.kx-cockpit.overdue{background:#FBECEC;border-bottom-color:#E4B4B4;}
.kx-cp-ticket{font-weight:800;color:var(--petrol);}
.kx-cp-prio{font-weight:600;}
.kx-cp-subject{color:var(--ink-soft);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.kx-cp-sla{margin-inline-start:auto;font-weight:700;color:var(--ink-soft);flex:none;}
.kx-cp-sla.overdue{color:#B3261E;}
.kx-note{display:flex;gap:8px;padding:8px 12px;background:#FBF6E4;border-bottom:1px solid #E7D9A8;align-items:flex-end;}
.kx-note[hidden]{display:none;}
.kx-note textarea{flex:1;border:1px solid #E7D9A8;border-radius:9px;padding:8px 11px;font-size:13px;font-family:inherit;resize:vertical;background:#fff;}
/* Timeline-Karten (kx-card/kx-tag/kx-internal) kommen aus chat_styles. */
.kx-refresh{position:absolute;top:10px;left:50%;transform:translateX(-50%);z-index:5;border:none;border-radius:999px;background:var(--petrol);color:#fff;font-size:12px;font-weight:600;padding:7px 14px;cursor:pointer;box-shadow:0 6px 18px rgba(0,0,0,.25);}
.kx-refresh[hidden]{display:none;}
.kx-chanwahl{display:inline-flex;gap:0;border:1px solid var(--line);border-radius:999px;overflow:hidden;}
.kx-chanwahl button{border:none;background:#fff;padding:5px 12px;font-size:12px;font-weight:600;color:var(--ink-soft);cursor:pointer;}
.kx-chanwahl button.active{background:var(--petrol);color:#fff;}
@media (max-width: 900px) {
    .kchat{grid-template-columns:1fr;height:calc(100dvh - var(--header-h) - 40px);}
    .kchat.mode-thread .kchat-side{display:none;}
    .kchat.mode-list .kchat-thread{display:none;}
    .kchat-back{display:inline-flex;}
}
</style>

<div class="kchat {{ $active ? 'mode-thread' : 'mode-list' }}">
    <aside class="kchat-side">
        <div class="kchat-side-head">
            <div class="kchat-title">💬 Kundenkommunikation</div>
            <form method="GET" action="{{ route('admin.customer_chat') }}" class="kchat-search">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Kunde suchen (Name, Nr., E-Mail) …" aria-label="Kunde suchen">
            </form>
        </div>
        <div class="kchat-convs">
            @if($searchResults !== null)
                <div class="kchat-group">Suchergebnisse</div>
                @forelse($searchResults as $c)
                <a href="{{ route('admin.customer_chat', ['kunde' => $c->id]) }}" class="kchat-conv {{ $active?->id === $c->id ? 'active' : '' }}">
                    <div class="d24c-av">{{ strtoupper(mb_substr($c->user?->name ?? '??', 0, 2)) }}</div>
                    <div class="kchat-meta">
                        <div class="kchat-name"><span>{{ $c->user?->name ?? 'Ohne Namen' }}</span></div>
                        <div class="kchat-snippet">Nr. {{ $c->customer_number }} · Unterhaltung starten</div>
                    </div>
                </a>
                @empty
                <div class="kchat-none">Kein Kunde gefunden.</div>
                @endforelse
                <a href="{{ route('admin.customer_chat') }}" style="display:block;padding:12px 14px;font-size:12.5px;color:var(--ink-soft);text-decoration:none;">← Alle Unterhaltungen</a>
            @else
                @forelse($conversations as $c)
                @php $last = $c->messages->first(); @endphp
                <a href="{{ route('admin.customer_chat', ['kunde' => $c->id]) }}" class="kchat-conv {{ $active?->id === $c->id ? 'active' : '' }}">
                    <div class="d24c-av">{{ strtoupper(mb_substr($c->user?->name ?? '??', 0, 2)) }}</div>
                    <div class="kchat-meta">
                        <div class="kchat-name">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $c->user?->name ?? 'Ohne Namen' }}</span>
                            <span class="kchat-time">{{ $last?->created_at->isToday() ? $last->created_at->format('H:i') : $last?->created_at->format('d.m.y') }}</span>
                        </div>
                        <div class="kchat-snippet">{{ $last?->from_staff ? 'Sie: ' : '' }}{{ \Illuminate\Support\Str::limit(trim($last->body ?? ''), 44) ?: '📎 Anhang' }}</div>
                    </div>
                    @if($c->unread_count > 0)<span class="kchat-unread">{{ $c->unread_count > 9 ? '9+' : $c->unread_count }}</span>@endif
                </a>
                @empty
                <div class="kchat-none">Noch keine Unterhaltungen.<br>Suchen Sie oben einen Kunden, um den Chat zu starten – der Kunde sieht die Nachricht sofort im Portal.</div>
                @endforelse
            @endif
        </div>
    </aside>

    <section class="kchat-thread">
        @if($active)
        <div class="kchat-head">
            <a href="{{ route('admin.customer_chat') }}" class="kchat-back" title="Zur Übersicht">‹</a>
            <div class="d24c-av" style="width:36px;height:36px;font-size:12px;">{{ strtoupper(mb_substr($active->user?->name ?? '??', 0, 2)) }}</div>
            <div style="min-width:0;">
                <div class="kchat-head-name">{{ $active->user?->name ?? 'Ohne Namen' }}</div>
                <div class="kchat-head-sub">Nr. {{ $active->customer_number }}@unless($active->user?->hasRealEmail()) · ⚠ keine echte E-Mail @endunless</div>
            </div>
            <a class="kchat-head-link" href="{{ route('admin.customer', $active->id) }}">Kundenakte →</a>
        </div>
        {{-- Problem-Cockpit: der aktive Vorgang auf einen Blick. Macht die
             Unterhaltung zur Problemloese-Zentrale (Status, Prioritaet,
             SLA/Ueberfaellig) statt nur zum Chatfenster. --}}
        @if($activeTicket)
        <a class="kx-cockpit {{ $activeTicket->isOverdue() ? 'overdue' : '' }}" href="{{ route('admin.ticket', $activeTicket->id) }}" title="Ticket öffnen">
            <span class="kx-cp-ticket">🎫 #{{ $activeTicket->ticket_number }}</span>
            <span class="badge badge-{{ $activeTicket->statusBadge() }}">{{ $activeTicket->statusLabel() }}</span>
            <span class="kx-cp-prio">{{ $activeTicket->priorityLabel() }}</span>
            <span class="kx-cp-subject">{{ \Illuminate\Support\Str::limit($activeTicket->subject, 48) }}</span>
            @if($activeTicket->isOverdue())
            <span class="kx-cp-sla overdue">⏰ SLA überfällig – Frist war {{ $activeTicket->due_at->format('d.m. H:i') }}</span>
            @elseif($activeTicket->due_at && !$activeTicket->first_response_at && !$activeTicket->isFinished())
            <span class="kx-cp-sla">⏱ Erstantwort bis {{ $activeTicket->due_at->format('d.m. H:i') }}</span>
            @endif
        </a>
        @endif
        {{-- Omnichannel: Kanal-Filter + Schnellaktionen (Ticket-Status,
             interne Notiz) - ohne die Seite zu verlassen. --}}
        <div class="kx-bar">
            <div class="kx-filters" id="kx-filters">
                <button type="button" class="kx-f active" data-f="alle">Alle</button>
                <button type="button" class="kx-f" data-f="chat">💬 Chat</button>
                <button type="button" class="kx-f" data-f="ticket">🎫 Tickets</button>
                @if(in_array(auth()->user()->role, ['admin','manager','support']))
                <button type="button" class="kx-f" data-f="email">✉️ E-Mail</button>
                @endif
                <button type="button" class="kx-f" data-f="document">📄 Dokumente</button>
                <button type="button" class="kx-f" data-f="note">🔒 Intern</button>
            </div>
            <div class="kx-actions">
                @if($activeTicket && (auth()->user()->role !== 'employee' || auth()->user()->can_manage_tickets))
                <form method="POST" action="{{ route('admin.ticket.status', $activeTicket->id) }}" class="kx-status">
                    @csrf
                    <span>Status ändern:</span>
                    <select name="status" onchange="this.form.submit()" aria-label="Ticket-Status">
                        @foreach(\App\Models\Ticket::STATUSES as $val => $label)
                        <option value="{{ $val }}" @selected($activeTicket->status === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
                @endif
                @php
                    // WhatsApp OHNE Systemanbindung (Betreiber-Entscheidung
                    // 23.07.2026): reiner Klick-Link zum Business-Konto des
                    // Teams - das System speichert und sendet nichts.
                    $waNummer = preg_replace('/\D+/', '', (string) ($active->mobile ?: $active->phone));
                    if (str_starts_with($waNummer, '00')) { $waNummer = substr($waNummer, 2); }
                    elseif (str_starts_with($waNummer, '0')) { $waNummer = '49' . substr($waNummer, 1); }
                @endphp
                @if($waNummer !== '')
                <a class="kx-note-btn" style="background:#E9F8EF;border-color:#BFE8CE;color:#128a4b;text-decoration:none;" href="https://wa.me/{{ $waNummer }}" target="_blank" rel="noopener" title="Öffnet WhatsApp Business – nicht mit dem System verbunden">📱 WhatsApp</a>
                @endif
                @if(in_array(auth()->user()->role, ['admin','manager','support']) || auth()->user()->can_send_emails)
                <a class="kx-note-btn" style="background:#fff;border-color:var(--line);color:var(--ink-soft);text-decoration:none;" href="{{ route('admin.email.compose', ['customer_id' => $active->id]) }}">✉️ E-Mail verfassen</a>
                @endif
                @if(auth()->user()->role !== 'employee' || auth()->user()->can_manage_tickets)
                <button type="button" class="kx-ticket-btn" onclick="document.getElementById('kx-ticket-modal').style.display='flex'">🎫 Vorgang erstellen</button>
                @endif
                <button type="button" class="kx-note-btn" onclick="const n=document.getElementById('kx-note');n.hidden=!n.hidden;if(!n.hidden)n.querySelector('textarea').focus();">🔒 Notiz</button>
            </div>
        </div>
        <form id="kx-note" method="POST" action="{{ route('admin.customer.note.store', $active->id) }}" class="kx-note" hidden
              data-note-action="{{ route('admin.customer.note.store', $active->id) }}"
              data-chat-action="{{ route('admin.internal.store', $active->id) }}">
            @csrf
            <div style="display:flex;flex-direction:column;gap:6px;flex:1;min-width:0;">
                <div class="kx-chanwahl" id="kx-note-ziel" style="align-self:flex-start;">
                    <button type="button" class="active" data-ziel="note">🔒 Notiz</button>
                    <button type="button" data-ziel="chat">💬 Intern teilen (@Name erwähnt Kollegen)</button>
                </div>
                <textarea name="note" required rows="2" maxlength="5000" placeholder="Interne Notiz – nur für das Team sichtbar …"></textarea>
            </div>
            <input type="hidden" name="type" value="chat" disabled id="kx-note-type">
            <button type="submit" class="btn btn-gold" style="padding:7px 16px;font-size:13px;">Speichern</button>
        </form>
        <button type="button" id="kx-refresh" class="kx-refresh" hidden onclick="location.reload()">⟳ Neue Ereignisse – Ansicht aktualisieren</button>

        {{-- "Anfrage -> Conversation -> Ticket": Vorgang aus der laufenden
             Unterhaltung eroeffnen, Beschreibung aus der letzten
             Kundennachricht vorbefuellt. Der Chatverlauf bleibt erhalten. --}}
        @if(auth()->user()->role !== 'employee' || auth()->user()->can_manage_tickets)
        <div id="kx-ticket-modal" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
            <form method="POST" action="{{ route('admin.customer_chat.ticket', $active->id) }}" style="background:#fff;border-radius:14px;width:100%;max-width:520px;max-height:92vh;overflow-y:auto;padding:24px;">
                @csrf
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                    <div style="font-size:16px;font-weight:700;">🎫 Vorgang erstellen</div>
                    <button type="button" onclick="document.getElementById('kx-ticket-modal').style.display='none'" style="border:none;background:none;font-size:18px;cursor:pointer;color:var(--ink-soft);">✕</button>
                </div>
                <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;">Für {{ $active->user?->name ?? 'diesen Kunden' }} (Nr. {{ $active->customer_number }}). Der Chatverlauf bleibt erhalten – das Ticket ist nur der Bearbeitungs-Status.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <label style="font-size:12.5px;color:var(--ink-soft);">Art
                        <select name="type" required style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                            @foreach(\App\Models\Ticket::TYPES as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label style="font-size:12.5px;color:var(--ink-soft);">Priorität
                        <select name="priority" required style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                            <option value="niedrig">🟢 Niedrig</option>
                            <option value="mittel" selected>🟡 Mittel</option>
                            <option value="hoch">🔴 Hoch</option>
                            <option value="dringend">🚨 Dringend</option>
                        </select>
                    </label>
                </div>
                <label style="font-size:12.5px;color:var(--ink-soft);display:block;margin-bottom:12px;">Betreff
                    <input type="text" name="subject" required maxlength="255" placeholder="Kurzbeschreibung des Anliegens" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </label>
                <label style="font-size:12.5px;color:var(--ink-soft);display:block;margin-bottom:14px;">Beschreibung
                    <textarea name="description" required rows="4" maxlength="5000" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;">{{ $ticketPrefill }}</textarea>
                </label>
                <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;">
                    <input type="checkbox" name="assign_me" value="1" style="width:auto;"> Mir zuweisen und direkt in Bearbeitung nehmen
                </label>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="document.getElementById('kx-ticket-modal').style.display='none'" class="btn btn-ghost" style="padding:8px 16px;font-size:13px;">Abbrechen</button>
                    <button type="submit" class="btn btn-gold" style="padding:8px 18px;font-size:13px;">Vorgang eröffnen</button>
                </div>
            </form>
        </div>
        @endif

        <div class="d24c-scroll" id="kc-scroll">
            <div class="d24c-list" id="kc-list" data-last-day="">
                {{-- EINE Timeline ueber alle Kanaele (gemeinsames Partial
                     mit dem Kundenakte-Tab "Kommunikation") --}}
                @include('admin.partials.conversation_timeline')
            </div>
        </div>
        <div class="kchat-opts">
            {{-- Kanalwahl: Portal-Chat (AJAX) oder Antwort ins offene
                 Ticket (klassischer POST, gleiche Anhaenge-Regeln) --}}
            <div class="kx-chanwahl" id="kx-chan">
                <button type="button" class="active" data-chan="chat">💬 Portal-Chat</button>
                @if($activeTicket && (auth()->user()->role !== 'employee' || auth()->user()->can_manage_tickets))
                <button type="button" data-chan="ticket">🎫 Ticket #{{ $activeTicket->ticket_number }}</button>
                @endif
            </div>
            <select id="kc-template" aria-label="Vorlage einfügen">
                <option value="">📋 Vorlage einfügen…</option>
                @foreach($templates as $tpl)
                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                @endforeach
            </select>
            <span class="opt-label">✉️ E-Mail an den Kunden:</span>
            <select name="email_mode" form="kc-form" aria-label="E-Mail-Modus">
                <option value="hint" selected>Nur Hinweis (ohne Inhalt)</option>
                <option value="full">Kompletter Text per E-Mail</option>
                <option value="none">Keine E-Mail</option>
            </select>
        </div>
        <div class="d24c-files" id="kc-files" hidden></div>
        <form class="d24c-comp" id="kc-form" method="POST" action="{{ route('admin.customer.messages.store', $active->id) }}" enctype="multipart/form-data"
              @if($activeTicket) data-ticket-action="{{ route('admin.ticket.reply', $activeTicket->id) }}" data-ticket-nr="{{ $activeTicket->ticket_number }}" @endif>
            @csrf
            @if($activeTicket)<input type="hidden" name="status" value="{{ $activeTicket->status }}" disabled id="kc-ticket-status">@endif
            <label class="d24c-clip" title="{{ __('Anhang hinzufügen') }}">📎<input id="kc-file" type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" hidden></label>
            <textarea id="kc-input" name="body" class="d24c-inp" rows="1" maxlength="5000" placeholder="Nachricht an {{ $active->user?->name ?? 'den Kunden' }} …" required></textarea>
            <button type="submit" class="d24c-send" aria-label="{{ __('Senden') }}"><span class="snd-ico">➤</span></button>
        </form>
        @else
        <div class="kchat-idle">
            <div class="big">💬</div>
            <div style="font-weight:700;font-size:15px;color:var(--ink);">Unterhaltung auswählen</div>
            <div style="font-size:13px;max-width:320px;line-height:1.7;">Wählen Sie links einen Kunden oder suchen Sie nach Name, Kundennummer oder E-Mail, um eine neue Unterhaltung zu starten.</div>
        </div>
        @endif
    </section>
</div>

@include('partials.chat_core')
@include('partials.doc_preview')
@if($active)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('kc-list');
    const scroller = document.getElementById('kc-scroll');
    list.dataset.lastDay = @json($timeline->count() ? $timeline->last()['day'] : '');
    D24Chat.wireComposer({
        form: document.getElementById('kc-form'),
        input: document.getElementById('kc-input'),
        fileInput: document.getElementById('kc-file'),
        filesBar: document.getElementById('kc-files'),
        list: list, scroller: scroller
    });
    // Chat offen = Kundenantworten gelten als gelesen; neue Nachrichten
    // erscheinen live. Aendern sich NICHT-Chat-Kanaele (Ticket, E-Mail,
    // Dokument, Notiz), erscheint ein Aktualisieren-Hinweis.
    let timelineVersion = @json($timelineVersion ?? '');
    D24Chat.poll({
        list: list, scroller: scroller,
        url: '{{ route('admin.customer_chat.feed', $active->id) }}',
        every: 10000,
        markRead: function () { return true; },
        onData: function (d) {
            if (timelineVersion && d.timeline_version && d.timeline_version !== timelineVersion) {
                document.getElementById('kx-refresh').hidden = false;
            }
        }
    });

    // Kanalwahl im Composer: Portal-Chat (AJAX) oder Ticket-Antwort
    // (klassischer POST; Feld status ist beim Ticket Pflicht).
    const kcForm = document.getElementById('kc-form');
    const chatAction = kcForm.action;
    const inputEl = document.getElementById('kc-input');
    const chatPlaceholder = inputEl.placeholder;
    document.querySelectorAll('#kx-chan button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#kx-chan button').forEach(function (x) { x.classList.remove('active'); });
            b.classList.add('active');
            const ticket = b.dataset.chan === 'ticket';
            kcForm.action = ticket ? kcForm.dataset.ticketAction : chatAction;
            kcForm.dataset.plain = ticket ? '1' : '';
            const st = document.getElementById('kc-ticket-status');
            if (st) st.disabled = !ticket;
            const em = document.querySelector('select[name=email_mode]');
            if (em) em.disabled = ticket;
            inputEl.placeholder = ticket
                ? 'Antwort ins Ticket #' + kcForm.dataset.ticketNr + ' – der Kunde sieht sie im Portal …'
                : chatPlaceholder;
            inputEl.focus();
        });
    });

    // Notiz-Formular: Ziel umschalten (Kundenakte-Notiz vs. interner
    // Chat mit @Erwaehnungen - anderes Feld, anderer Endpoint).
    const noteForm = document.getElementById('kx-note');
    const noteArea = noteForm.querySelector('textarea');
    document.querySelectorAll('#kx-note-ziel button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#kx-note-ziel button').forEach(function (x) { x.classList.remove('active'); });
            b.classList.add('active');
            const chat = b.dataset.ziel === 'chat';
            noteForm.action = chat ? noteForm.dataset.chatAction : noteForm.dataset.noteAction;
            noteArea.name = chat ? 'message' : 'note';
            document.getElementById('kx-note-type').disabled = !chat;
            noteArea.placeholder = chat
                ? 'Nachricht in den internen Bereich dieser Kundenakte – mit @Name Kollegen erwähnen …'
                : 'Interne Notiz – nur für das Team sichtbar …';
            noteArea.focus();
        });
    });
    // Kanal-Filter: blendet Timeline-Elemente nach Kanal ein/aus
    // (rein clientseitig - die Daten sind bereits geladen).
    document.querySelectorAll('#kx-filters .kx-f').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#kx-filters .kx-f').forEach(function (x) { x.classList.remove('active'); });
            btn.classList.add('active');
            const f = btn.dataset.f;
            list.querySelectorAll('[data-kind]').forEach(function (el) {
                const k = el.dataset.kind;
                const show = f === 'alle'
                    || (f === 'ticket' && (k === 'ticket_msg' || k === 'event'))
                    || f === k;
                el.style.display = show ? '' : 'none';
            });
            // Tagestrenner nur in der Gesamtansicht (gefiltert wirken sie verwaist)
            list.querySelectorAll('.d24c-day').forEach(function (el) { el.style.display = f === 'alle' ? '' : 'none'; });
            scroller.scrollTop = scroller.scrollHeight;
        });
    });
    // Vorlage in das Eingabefeld uebernehmen (gleicher Endpoint wie in
    // der Kundenakte, Platzhalter werden fuer diesen Kunden gerendert).
    document.getElementById('kc-template').addEventListener('change', function () {
        if (!this.value) return;
        fetch('{{ url('admin/vorlagen') }}/' + this.value + '/render?customer_id={{ $active->id }}', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                const inp = document.getElementById('kc-input');
                inp.value = d.body || inp.value;
                inp.focus();
            })
            .catch(function () {});
        this.value = '';
    });
});
</script>
@endif
@endsection
