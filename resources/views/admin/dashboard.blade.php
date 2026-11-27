@extends('layouts.admin')
@push('charts')
{{-- Diese Seite zeichnet Diagramme - deshalb wird Chart.js hier
     ausdruecklich angefordert (Audit 15.09.2026). Es liegt lokal
     (DSGVO: keine Besucher-IP an ein CDN). --}}
<script src="/js/chart.umd.min.js"></script>
@endpush

@section('content')
{{-- Verwaltungszentrale (UI-Modernisierung 27.11.2026).
     Jede Zahl stammt aus DashboardUebersicht und fuehrt auf die Liste, deren
     Zeilen sie zaehlt. Provisionen erscheinen NUR fuer Administratoren. --}}
@php
    $k = $kennzahlen;
    $zahl = fn ($n) => number_format((int) $n, 0, ',', '.');
    $initialen = function (?string $name): string {
        $teile = preg_split('/\s+/', trim((string) $name)) ?: [];
        $a = mb_substr($teile[0] ?? '', 0, 1);
        $b = count($teile) > 1 ? mb_substr(end($teile), 0, 1) : '';
        return mb_strtoupper($a.$b) ?: '?';
    };
    $stunde = (int) now()->lokal()->format('G');
    $gruss = $stunde < 11 ? 'Guten Morgen' : ($stunde < 18 ? 'Guten Tag' : 'Guten Abend');
@endphp

<div class="kopf-zeile">
    <div class="page-header">
        <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Dashboard</span></div>
        <h1 class="page-title">{{ $gruss }}, {{ auth()->user()->name }}</h1>
        <div class="page-sub">{{ now()->lokal()->locale('de')->translatedFormat('l, d. F Y') }} — Ihre Übersicht</div>
    </div>
    <div class="kopf-aktionen">
        <a href="{{ route('admin.customers.create') }}" class="btn btn-ghost btn-sm"><x-admin.symbol name="users" :size="16" /> Kunde anlegen</a>
        <a href="{{ route('admin.contract.new') }}" class="btn btn-ghost btn-sm"><x-admin.symbol name="contract" :size="16" /> Vertrag anlegen</a>
        <a href="{{ route('admin.signatures.create') }}" class="btn btn-primary btn-sm"><x-admin.symbol name="inbox" :size="16" /> Zur Unterschrift senden</a>
    </div>
</div>

<div class="kpi-raster">
    <x-admin.kpi label="Kunden gesamt" :wert="$zahl($totalCustomers)" symbol="users" :href="route('admin.customers')">
        <span>im sichtbaren Bestand</span>
    </x-admin.kpi>
    <x-admin.kpi label="Aktive Verträge" :wert="$zahl($activeContracts)" symbol="contract" :href="route('admin.contracts')">
        <span>Eigenbestand @if(($activeExternalContracts ?? 0) > 0) · zzgl. {{ $activeExternalContracts }} {{ $activeExternalContracts === 1 ? 'Fremdvertrag' : 'Fremdverträge' }}@endif</span>
    </x-admin.kpi>
    <x-admin.kpi label="Neue Kunden (Monat)" :wert="$zahl($k['neukunden'])" symbol="lead" ton="info"
        :href="$k['neukunden_link']" :trend="$k['neukunden_trend']" :vergleich="'vs. Vormonat ('.$zahl($k['neukunden_vormonat']).')'" />
    <x-admin.kpi label="Verträge in Bearbeitung" :wert="$zahl($k['in_bearbeitung'])" symbol="clock" ton="warn"
        :href="route('admin.contracts', ['gruppe' => \App\Models\Contract::GROUP_PENDING])">
        <span>Anträge/Angebote, noch nicht im Bestand</span>
    </x-admin.kpi>
    <x-admin.kpi label="Meine offenen Aufgaben" :wert="$zahl($k['aufgaben'])" symbol="task" ton="gold" :href="route('admin.tasks')">
        @if($k['aufgaben_ueberfaellig'] > 0)<span class="warn-text">{{ $k['aufgaben_ueberfaellig'] }} überfällig</span>@else<span>nichts überfällig</span>@endif
    </x-admin.kpi>
    @if($k['provision'] !== null)
    <x-admin.kpi label="Provisionen (Monat)" :wert="number_format($k['provision']['netto'], 2, ',', '.').' €'" symbol="money" ton="gold"
        :href="route('admin.provisionsmanagement.dashboard')" :trend="$k['provision']['trend']"
        :vergleich="'vs. Vormonat ('.number_format($k['provision']['vormonat'], 2, ',', '.').' €)'">
        <span class="kpi-nur-admin" title="Nur für Administratoren sichtbar">nur Admin</span>
    </x-admin.kpi>
    @endif
</div>

{{-- Arbeitsvorrat: alles, was auf eine menschliche Entscheidung wartet. --}}
@php
    $inboxSuggested = \App\Models\EmailMessage::where('match_status', 'suggested')->count();
    // Provisionsgutschriften nur fuer Berechtigte (`provisionen-verwalten`).
    $inboxCommissions = \Illuminate\Support\Facades\Gate::allows('provisionen-verwalten')
        ? \App\Models\Commission::pendingReview()->count() : 0;
    $inboxDocRequests = \App\Models\DocumentRequest::awaitingReview()->count();
    $unverifiedOrigin = $unverifiedOriginContracts ?? 0;
    $offeneTickets = $openTickets;
@endphp
@if($inboxSuggested + $inboxCommissions + $inboxDocRequests + $unverifiedOrigin + $pendingApprovals + $offeneTickets + $offeneSignaturen['anzahl'] > 0)
<div class="card" style="padding:14px 18px;">
    <div class="card-header" style="margin-bottom:10px;"><div class="card-title">Wartet auf Ihre Entscheidung</div></div>
    <div class="aufgaben-chips">
        @if($offeneTickets > 0)<a href="{{ route('admin.tickets', ['status' => 'aktiv']) }}" class="chip-link"><span class="punkt punkt-blau"></span>{{ $offeneTickets }} offene {{ $offeneTickets === 1 ? 'Anfrage' : 'Anfragen' }}</a>@endif
        @if($pendingApprovals > 0)<a href="{{ route('admin.change_requests') }}" class="chip-link"><span class="punkt punkt-gelb"></span>{{ $pendingApprovals }} {{ $pendingApprovals === 1 ? 'Änderungsantrag' : 'Änderungsanträge' }}</a>@endif
        @if($offeneSignaturen['anzahl'] > 0)<a href="{{ route('admin.signatures.index', ['reiter' => 'gesendet']) }}" class="chip-link"><span class="punkt punkt-gelb"></span>{{ $offeneSignaturen['anzahl'] }} {{ $offeneSignaturen['anzahl'] === 1 ? 'Signatur wartet' : 'Signaturen warten' }}</a>@endif
        @if($inboxSuggested > 0)<a href="{{ route('admin.email_inbox') }}" class="chip-link"><span class="punkt punkt-blau"></span>{{ $inboxSuggested }} E-Mail-Zuordnung(en) bestätigen</a>@endif
        @if($inboxCommissions > 0)<a href="{{ route('admin.commissions') }}" class="chip-link"><span class="punkt punkt-gruen"></span>{{ $inboxCommissions }} Provisionsgutschrift(en) buchen</a>@endif
        @if($inboxDocRequests > 0)<a href="{{ route('admin.document_requests') }}" class="chip-link"><span class="punkt punkt-blau"></span>{{ $inboxDocRequests }} Dokument-Upload(s) prüfen</a>@endif
        {{-- Vertragsherkunft (28.09.2026): nur ANGENOMMENE Herkunft - bestaetigen oder korrigieren. --}}
        @if($unverifiedOrigin > 0)<a href="{{ route('admin.contracts.origin_review') }}" class="chip-link"><span class="punkt punkt-gelb"></span>{{ $unverifiedOrigin }} {{ $unverifiedOrigin === 1 ? 'Vertrag' : 'Verträge' }} mit ungeprüfter Herkunft</a>@endif
    </div>
</div>
@endif

<div class="dash-raster">
    <div class="card dash-8">
        <div class="card-header">
            <div class="card-title">Neue Verträge je Monat</div>
            <a href="{{ route('admin.reports') }}" class="card-link">Analyse öffnen →</a>
        </div>
        <div style="position:relative;height:300px;"><canvas id="verlaufChart" aria-label="Neue Verträge je Monat"></canvas></div>
        <div class="muted-2xs" style="margin-top:8px;">Eigener Bestand, nach Abschlussdatum (Unterschrift → Antrag → Beginn → Anlage) - dieselbe Zählweise wie die Analyse.</div>
    </div>

    <div class="card dash-4">
        <div class="card-header">
            <div class="card-title">Aktive Verträge nach Sparte</div>
            <a href="{{ route('admin.contracts') }}" class="card-link">Alle →</a>
        </div>
        @if(count($sparten) === 0)
            <div class="leer-hinweis">Noch keine aktiven Verträge.</div>
        @else
            <div style="position:relative;height:170px;"><canvas id="spartenChart" aria-label="Aktive Verträge nach Sparte"></canvas></div>
            @php $spartenSumme = array_sum(array_column($sparten, 'wert')); @endphp
            <div style="margin-top:10px;">
            @foreach($sparten as $i => $s)
                <a class="liste-zeile" style="padding:6px 0;" href="{{ $s['sparte'] !== '' ? route('admin.contracts', ['sparte' => $s['sparte']]) : route('admin.contracts') }}">
                    <span class="punkt" data-sparten-farbe="{{ $i }}"></span>
                    <span class="liste-inhalt liste-titel" style="font-weight:500;">{{ $s['label'] }}</span>
                    <span class="liste-ende"><strong style="color:var(--ink);">{{ $zahl($s['wert']) }}</strong> {{ $spartenSumme > 0 ? round($s['wert'] / $spartenSumme * 100) : 0 }} %</span>
                </a>
            @endforeach
            </div>
        @endif
    </div>

    <div class="card dash-4">
        <div class="card-header">
            <div class="card-title">Meine Aufgaben</div>
            <a href="{{ route('admin.tasks') }}" class="card-link">Alle →</a>
        </div>
        @forelse($meineAufgaben as $t)
            @php
                $ueber = $t->isOverdue();
                $heute = $t->due_date && $t->due_date->isToday();
            @endphp
            <a class="liste-zeile" href="{{ route('admin.tasks') }}#task-{{ $t->id }}">
                <span class="punkt {{ $ueber ? 'punkt-rot' : ($heute ? 'punkt-gelb' : 'punkt-gruen') }}" title="{{ $ueber ? 'Überfällig' : ($heute ? 'Heute fällig' : 'Offen') }}"></span>
                <span class="liste-inhalt">
                    <span class="liste-titel" style="display:block;">{{ $t->title }}</span>
                    <span class="liste-unter" style="display:block;">{{ $t->customer?->user?->name ?? 'ohne Kunde' }}</span>
                </span>
                <span class="liste-ende {{ $ueber ? 'warn-text' : '' }}">{{ $t->due_date ? $t->due_date->format('d.m.') : '—' }}</span>
            </a>
        @empty
            <div class="leer-hinweis">Keine offenen Aufgaben. 🎉</div>
        @endforelse
    </div>

    <div class="card dash-4">
        <div class="card-header">
            <div class="card-title">Offene Signaturen</div>
            <a href="{{ route('admin.signatures.index') }}" class="card-link">Alle →</a>
        </div>
        @forelse($offeneSignaturen['liste'] as $s)
            @php
                $fertig = $s->signers->where('status', 'signed')->count();
                $gesamt = $s->signers->count();
            @endphp
            <a class="liste-zeile" href="{{ route('admin.signatures.show', $s->id) }}">
                <span class="initialen initialen-sm">✍</span>
                <span class="liste-inhalt">
                    <span class="liste-titel" style="display:block;">{{ $s->title }}</span>
                    <span class="liste-unter" style="display:block;">{{ $s->customer?->user?->name ?? ($s->signers->first()?->name ?? '—') }} · {{ $fertig }}/{{ $gesamt }} unterschrieben</span>
                </span>
                <span class="liste-ende"><span class="badge {{ \App\Support\SignatureStatus::tone($s->status) }}">{{ \App\Support\SignatureStatus::label($s->status) }}</span></span>
            </a>
        @empty
            <div class="leer-hinweis">Keine Signatur wartet auf eine Unterschrift.</div>
        @endforelse
    </div>

    <div class="card dash-4">
        <div class="card-header">
            <div class="card-title">Änderungsanträge</div>
            <a href="{{ route('admin.change_requests') }}" class="card-link">Alle →</a>
        </div>
        @forelse($recentApprovals as $a)
            <a class="liste-zeile" href="{{ route('admin.change_requests') }}">
                <span class="initialen initialen-sm">{{ $initialen($a->customer?->user?->name) }}</span>
                <span class="liste-inhalt">
                    <span class="liste-titel" style="display:block;">{{ $a->customer?->user?->name ?? '—' }}</span>
                    <span class="liste-unter" style="display:block;">{{ $a->created_at->lokal()->format('d.m.Y H:i') }}</span>
                </span>
                <span class="liste-ende"><span class="badge badge-pending">Ausstehend</span></span>
            </a>
        @empty
            <div class="leer-hinweis">Keine offenen Änderungsanträge.</div>
        @endforelse
    </div>

    <div class="card dash-6">
        <div class="card-header">
            <div class="card-title">Neueste Anfragen</div>
            <a href="{{ route('admin.tickets') }}" class="card-link">Alle →</a>
        </div>
        @forelse($recentTickets as $t)
            <a class="liste-zeile" href="{{ route('admin.ticket', $t->id) }}">
                <span class="initialen initialen-sm">{{ $initialen($t->customer?->user?->name ?? $t->guest_name ?? null) }}</span>
                <span class="liste-inhalt">
                    <span class="liste-titel" style="display:block;">{{ $t->subject }}</span>
                    <span class="liste-unter" style="display:block;">{{ $t->customer?->user?->name ?? 'Gast-Anfrage' }} · {{ $t->created_at->lokal()->format('d.m.Y') }}</span>
                </span>
                <span class="liste-ende"><span class="badge badge-{{ $t->status === 'open' ? 'open' : ($t->status === 'closed' ? 'closed' : 'pending') }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span></span>
            </a>
        @empty
            <div class="leer-hinweis">Keine Anfragen vorhanden.</div>
        @endforelse
    </div>

    <div class="card dash-6">
        <div class="card-header">
            <div class="card-title">Neueste Kunden</div>
            <a href="{{ route('admin.customers') }}" class="card-link">Alle →</a>
        </div>
        @forelse($neuesteKunden as $c)
            <a class="liste-zeile" href="{{ route('admin.customer', $c->id) }}">
                <span class="initialen initialen-sm">{{ $initialen($c->user?->name) }}</span>
                <span class="liste-inhalt">
                    <span class="liste-titel" style="display:block;">{{ $c->user?->name ?? '—' }}</span>
                    <span class="liste-unter" style="display:block;">{{ $c->customer_number ?: 'ohne Kundennummer' }} · angelegt {{ $c->created_at->lokal()->format('d.m.Y') }}</span>
                </span>
                <span class="liste-ende">{{ $c->active_contracts_count }} {{ $c->active_contracts_count === 1 ? 'Vertrag' : 'Verträge' }}</span>
            </a>
        @empty
            <div class="leer-hinweis">Noch keine Kunden.</div>
        @endforelse
    </div>

    <div class="card dash-12" data-zuletzt-geoeffnet>
        <div class="card-header">
            <div class="card-title">Zuletzt geöffnete Kunden</div>
            <a href="{{ route('admin.customers') }}" class="card-link">Alle Kunden anzeigen →</a>
        </div>
        <div class="customer-cards">
            @forelse($recentCustomers as $c)
            <a href="{{ route('admin.customer', $c->id) }}" class="customer-card" style="display:flex;align-items:center;gap:10px;">
                <span class="initialen initialen-sm">{{ $initialen($c->user?->name) }}</span>
                <span style="min-width:0;">
                    <span class="name" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $c->user?->name }}</span>
                    <span class="meta" style="display:block;">Aktive Verträge: {{ $c->active_contracts_count }}</span>
                </span>
            </a>
            @empty
            <p class="leer-hinweis">Noch keine Kunden geöffnet.</p>
            @endforelse
        </div>
        <!-- /zuletzt-geoeffnet -->
    </div>
</div>

<style>
.aufgaben-chips{display:flex;gap:8px;flex-wrap:wrap;}
.chip-link{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;border:1px solid var(--line);background:var(--tiefe-hell);box-shadow:var(--tiefe-schatten);font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;transition:transform .08s,box-shadow .12s;}
.chip-link:hover{transform:translateY(-1px);box-shadow:var(--tiefe-schatten-hover);}
@media (max-width:1100px){.customer-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media (max-width:560px){.customer-cards{grid-template-columns:minmax(0,1fr);}}
</style>

<script @cspNonce>
(function () {
    if (typeof Chart === 'undefined') return;
    var gruen = brandColor('emerald');
    var farben = [gruen, brandColor('gold'), brandColor('graphite'), '#2F6FB5', '#C98A17', '#B4B2A9'];
    var verlauf = @json($vertragsVerlauf);
    var el = document.getElementById('verlaufChart');
    if (el) {
        new Chart(el, {
            type: 'bar',
            data: { labels: verlauf.labels, datasets: [{ label: 'Neue Verträge', data: verlauf.werte, backgroundColor: gruen, borderRadius: 6, maxBarThickness: 46 }] },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
                onClick: function (e, el) {
                    if (!el.length) return;
                    var monat = verlauf.monate[el[0].index];
                    window.location = @json(route('admin.reports.liste')) + '?liste=vertraege_neu&zeitraum=monatswahl&monat=' + encodeURIComponent(monat);
                },
                onHover: function (e, el) { e.native.target.style.cursor = el.length ? 'pointer' : 'default'; }
            }
        });
    }
    var sparten = @json($sparten);
    var sp = document.getElementById('spartenChart');
    if (sp && sparten.length) {
        new Chart(sp, {
            type: 'doughnut',
            data: { labels: sparten.map(function (s) { return s.label; }), datasets: [{ data: sparten.map(function (s) { return s.wert; }), backgroundColor: farben, borderWidth: 0 }] },
            options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false } } }
        });
    }
    document.querySelectorAll('[data-sparten-farbe]').forEach(function (p) {
        p.style.background = farben[parseInt(p.dataset.spartenFarbe, 10) % farben.length];
    });
})();
</script>
@endsection
