{{-- Kopf der Kundenakte (Betreiber-Wunsch 27.11.2026, Vorlage "modernes CRM").
     Alles aus der Akte, nichts erfunden: keine Fotos (die Akte fuehrt keine -
     stattdessen die Initialen), keine Karte (ein Kartendienst wuerde jede
     Besucher-IP an einen Fremden senden, CSP), Status nur aus echten
     Vertraegen. Die Kontaktleiste kopiert per Klick den Originalwert. --}}
@php
    $kkName = trim((string) ($customer->user->name ?? ''));
    $kkInitialen = collect(preg_split('/\s+/', $kkName) ?: [])->filter()->map(fn ($t) => mb_strtoupper(mb_substr($t, 0, 1)))->take(2)->implode('');
    $kkAdresse = $customer->effectiveContact('address')['value'] ?: trim((string) $customer->address);
    $kkMail = ($customer->user && $customer->user->hasRealEmail()) ? (string) $customer->user->email : (string) $customer->email2;
    $kkTelefon = $customer->effectiveContact('mobile')['value'] ?: $customer->effectiveContact('phone')['value'];
    $kkIban = strtoupper((string) preg_replace('/\s+/', '', (string) $customer->iban));
    $kkAktiv = $aktiveVertraege->count();
    $kkFam = $customer->familyStatus();
    $kkSperre = $customer->eigenstaendigkeitsSperre();
@endphp
<div class="kk-kopf">
    <div class="breadcrumb" style="margin-bottom:12px;">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <span>{{ $kkName }}</span>
    </div>

    <div class="kk-oben">
        <div class="kk-person">
            <div class="kk-avatar" aria-hidden="true">{{ $kkInitialen ?: '?' }}</div>
            <div style="min-width:0;">
                <div class="kk-badges">
                    @if($kkAktiv > 0)
                    <span class="kk-badge kk-badge-ok">● Aktiv · {{ $kkAktiv }} {{ $kkAktiv === 1 ? 'Vertrag' : 'Verträge' }}</span>
                    @else
                    <span class="kk-badge">Kein aktiver Vertrag</span>
                    @endif
                    @if($kkFam['key'] !== 'eigenstaendig')
                    <span class="kk-badge" style="color:{{ $kkFam['color'] }};background:{{ $kkFam['bg'] }};">{{ $kkFam['label'] }}</span>
                    @endif
                </div>
                <h1 class="page-title kk-name">{{ $kkName }}</h1>
                <div class="kk-meta">
                    @if(filled($customer->customer_number))
                    <span>Kundennummer: <strong>{{ $customer->customer_number }}</strong></span>
                    @else
                    <span>Keine eigene Kundennummer (abhängiges Familienmitglied)</span>
                    @endif
                    @if($customer->birth_date)
                    <span class="kk-punkt">·</span><span>Geburtsdatum: <strong>{{ \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y') }}</strong></span>
                    @endif
                    @if($customer->created_at)
                    <span class="kk-punkt">·</span><span>Kunde seit {{ $customer->created_at->lokal()->format('d.m.Y') }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="kk-aktionen">
            @if(! $kkSperre)
            <a href="{{ route('admin.contract.create', $customer->id) }}" class="btn btn-emerald">＋ Vertrag hinzufügen</a>
            @endif
            <a href="{{ route('admin.customer.edit', $customer->id) }}" class="btn btn-ghost">✏️ Bearbeiten</a>
            <button type="button" class="btn btn-ghost" data-h-click="2e6547eb33">✉️ Nachricht</button>
            <a href="{{ route('admin.tasks', ['customer_id' => $customer->id, 'neu' => 1]) }}" class="btn btn-ghost">☑️ Aufgabe</a>
            <div data-menu style="position:relative;">
                <button type="button" class="btn btn-ghost" data-menu-trigger aria-haspopup="true" aria-expanded="false" title="Weitere Aktionen">•••</button>
                <div data-menu-panel hidden class="kk-menue">
                    @if(in_array(auth()->user()->role, ['admin','manager','support']) || auth()->user()->can_send_emails)
                    <a href="{{ route('admin.email.compose', ['customer_id' => $customer->id]) }}">✉️ E-Mail verfassen</a>
                    @endif
                    <a href="{{ route('admin.customer.merge', $customer->id) }}">🔀 Mit Duplikat zusammenführen</a>
                    <a href="#kk-portal">🌐 Portal-Zugang</a>
                </div>
            </div>
        </div>
    </div>

    {{-- Kontaktleiste: Klick kopiert den Originalwert (IBAN ohne Leerzeichen). --}}
    <div class="kk-leiste">
        <button type="button" class="kk-feld kd-kopieren" data-kopie="{{ $kkAdresse }}" @disabled($kkAdresse === '') title="Adresse kopieren">
            <span class="kk-icon">📍</span><span class="kk-text">{{ $kkAdresse !== '' ? $kkAdresse : 'Keine Adresse' }}</span>
        </button>
        <button type="button" class="kk-feld kd-kopieren" data-kopie="{{ $kkMail }}" @disabled($kkMail === '') title="E-Mail kopieren">
            <span class="kk-icon">✉️</span><span class="kk-text">{{ $kkMail !== '' ? $kkMail : 'Keine E-Mail' }}</span>
        </button>
        <button type="button" class="kk-feld kd-kopieren" data-kopie="{{ $kkTelefon }}" @disabled($kkTelefon === '') title="Telefon kopieren">
            <span class="kk-icon">📞</span><span class="kk-text">{{ $kkTelefon !== '' ? $kkTelefon : 'Keine Telefonnummer' }}</span>
        </button>
        <button type="button" class="kk-feld kd-kopieren" data-kopie="{{ $kkIban }}" data-kd-feld="{{ $kkIban !== '' ? 'iban' : '' }}" @disabled($kkIban === '') title="IBAN kopieren">
            <span class="kk-icon">🏦</span><span class="kk-text">{{ $kkIban !== '' ? 'IBAN •••• '.substr($kkIban, -4) : 'Keine IBAN' }}</span>
        </button>
    </div>

    @if($kkSperre)
    <div class="kk-hinweis">
        🧒 {{ $kkSperre }}
        @if($customer->familyGuardians()->isEmpty())
        <strong>Noch kein Elternteil verknüpft</strong> – bitte unter „Familie" den Vater (sonst die Mutter) als Bezugsperson verknüpfen.
        @endif
        @if(blank($customer->customer_number) && $customer->age() !== null && $customer->age() >= \App\Support\FamilienAlter::selbststaendig())
        <form method="POST" action="{{ route('admin.customer.kundennummer_vergeben', $customer->id) }}" style="margin-top:6px;"
              data-confirm="Eigene Kundennummer vergeben? Die Familienbeziehung bleibt bestehen.">
            @csrf
            <button type="submit" class="btn btn-emerald btn-sm">Kundennummer vergeben</button>
        </form>
        @endif
    </div>
    @endif
</div>

<style>
.kk-kopf{background:linear-gradient(135deg,var(--surface,#FBFAF6) 0%,var(--canvas,#F1EEE5) 100%);border:1px solid var(--line);border-radius:16px;padding:18px 22px 16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(19,26,23,.05);}
.kk-oben{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;}
.kk-person{display:flex;gap:16px;align-items:center;min-width:0;}
.kk-avatar{width:64px;height:64px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--emerald,#17A65B),var(--graphite,#131A17));box-shadow:0 0 0 4px #fff,0 2px 8px rgba(19,26,23,.18);}
.kk-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px;}
.kk-badge{font-size:12px;font-weight:600;border-radius:999px;padding:2px 10px;background:var(--canvas,#F1EEE5);color:var(--ink-soft);border:1px solid var(--line);}
.kk-badge-ok{background:var(--emerald-soft,#E8F7EF);color:var(--emerald-dark,#128a4b);border-color:transparent;}
.kk-name{margin:0;font-size:24px;line-height:1.2;overflow-wrap:anywhere;}
.kk-meta{font-size:13.5px;color:var(--ink-soft);margin-top:4px;display:flex;flex-wrap:wrap;gap:4px 6px;}
.kk-meta strong{color:var(--ink);}
.kk-aktionen{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.kk-menue{position:absolute;right:0;top:calc(100% + 6px);z-index:30;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(19,26,23,.12);min-width:230px;padding:6px;}
.kk-menue a{display:block;padding:8px 10px;border-radius:6px;font-size:13.5px;color:var(--ink);text-decoration:none;}
.kk-menue a:hover{background:var(--canvas);}
.kk-leiste{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;margin-top:16px;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden;}
.kk-feld{display:flex;align-items:center;gap:10px;padding:12px 16px;border:0;border-left:1px solid var(--line);background:none;text-align:left;cursor:pointer;font:inherit;font-size:13.5px;font-weight:600;color:var(--ink);min-width:0;}
.kk-feld:first-child{border-left:0;}
.kk-feld:hover:not(:disabled){background:var(--canvas);}
.kk-feld:disabled{cursor:default;color:var(--ink-soft);font-weight:400;}
.kk-feld.kd-ok{background:var(--emerald-soft,#E8F7EF);}
.kk-icon{flex:none;}
.kk-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.kk-hinweis{margin-top:12px;background:#FEF3C7;border:1px solid #F0E0B0;border-radius:8px;padding:8px 12px;font-size:12.5px;color:#92400E;}
@media (max-width:900px){.kk-leiste{grid-template-columns:1fr 1fr}.kk-feld:nth-child(3){border-left:0}.kk-feld:nth-child(n+3){border-top:1px solid var(--line)}}
@media (max-width:560px){.kk-leiste{grid-template-columns:1fr}.kk-feld{border-left:0}.kk-feld+.kk-feld{border-top:1px solid var(--line)}.kk-avatar{width:52px;height:52px;font-size:18px}.kk-name{font-size:20px}}
</style>
