@extends('layouts.admin')
@section('content')

@php
// Zentrale Sparten-Definition (Contract::TYPES) - eine Quelle für alle Views.
$typeConfig = \App\Models\Contract::TYPES;
$activeTypes = $customer->contracts->where('status','active')->pluck('type')->unique()->toArray();
@endphp

<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <span>{{ $customer->user?->name }}</span>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">{{ $customer->user?->name }}</div>
            <div style="font-size:14px;color:var(--ink-soft);">{{ $customer->customer_number }} · {{ $customer->user?->email }}</div>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="{{ route('admin.customer.edit', $customer->id) }}" class="btn btn-ghost">✏️ Bearbeiten</a>
            <a href="{{ route('admin.contract.create', $customer->id) }}" class="btn btn-gold">+ Vertrag hinzufügen</a>
        </div>
    </div>
</div>


{{-- Portal-Status (echter Zustand statt "aktiv/inaktiv"-Raten) --}}
@php $portalUser = $customer->user; $ps = $customer->portalStatus(); @endphp
<div class="card" style="margin-bottom:20px;padding:18px 20px;">
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;">
        <div style="min-width:260px;">
            <div style="font-weight:700;margin-bottom:8px;">Portal-Zugang</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
                <span style="background:{{ $ps['bg'] }};color:{{ $ps['color'] }};border-radius:12px;padding:3px 12px;font-size:12.5px;font-weight:600;">{{ $ps['label'] }}</span>
                <span style="font-size:12.5px;color:var(--ink-soft);">Account vorhanden: <strong>{{ $portalUser && $portalUser->hasRealEmail() ? 'Ja' : 'Nein' }}</strong></span>
                <span style="font-size:12.5px;color:var(--ink-soft);">Passwort gesetzt: <strong>{{ $portalUser?->portal_password_set_at ? 'Ja' : 'Nein' }}</strong></span>
            </div>
            <div style="display:grid;grid-template-columns:auto auto;gap:2px 18px;font-size:12.5px;color:var(--ink-soft);">
                <span>Einladung gesendet:</span><strong>{{ $portalUser?->invitation_sent_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                <span>Account aktiviert:</span><strong>{{ $portalUser?->portal_password_set_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                <span>Erster Login:</span><strong>{{ $portalUser?->first_login_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                <span>Letzter Login:</span><strong>{{ $portalUser?->last_login_at?->format('d.m.Y H:i') ?? '—' }}</strong>
            </div>
        </div>
        @if(auth()->user()->role === 'admin')
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;">
            <form method="POST" action="{{ route('admin.customer.portal.invite', $customer->id) }}"
                onsubmit="return confirm('Einladung mit Startpasswort (Geburtsdatum TT.MM.JJJJ) bzw. Passwort-Link an den Kunden senden?');">
                @csrf<button type="submit" class="btn btn-gold btn-sm">📧 Einladung senden</button>
            </form>
            <form method="POST" action="{{ route('admin.customer.portal.reset_link', $customer->id) }}">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">🔑 Reset-Link senden</button>
            </form>
            <form method="POST" action="{{ route('admin.customer.portal.reset', $customer->id) }}"
                onsubmit="return confirm('Portal wirklich zurücksetzen? Das Passwort wird neu gesetzt und die Einladung erneut versendet.');">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">↺ Portal zurücksetzen</button>
            </form>
            <form method="POST" action="{{ route('admin.customer.portal.toggle', $customer->id) }}"
                onsubmit="return confirm('{{ ($portalUser?->is_active ?? true) ? 'Portal-Login für diesen Kunden deaktivieren?' : 'Portal-Login wieder aktivieren?' }}');">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">{{ ($portalUser?->is_active ?? true) ? '🚫 Deaktivieren' : '✅ Aktivieren' }}</button>
            </form>
        </div>
        @endif
    </div>
</div>

{{-- Tab-Navigation (Kundenprofil) --}}
<style>
.cust-tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:6px;margin-bottom:20px;overflow-x:auto;}
.cust-tab{flex:1;text-align:center;padding:10px 16px;border-radius:8px;font-size:13.5px;font-weight:600;color:var(--ink-soft);cursor:pointer;white-space:nowrap;text-decoration:none;transition:.15s;border:none;background:none;}
.cust-tab:hover{background:var(--canvas);color:var(--ink);}
.cust-tab.active{background:var(--petrol);color:#fff;}
.chat-bubble{max-width:75%;padding:10px 14px;border-radius:12px;font-size:13.5px;line-height:1.55;}
.chat-row{display:flex;gap:10px;margin-bottom:14px;align-items:flex-end;}
.chat-avatar{width:32px;height:32px;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex:none;}
</style>
<div class="cust-tabs">
    <button type="button" class="cust-tab active" data-tab="tab-uebersicht" onclick="showCustTab('tab-uebersicht',this)">📄 Übersicht</button>
    <button type="button" class="cust-tab" data-tab="tab-vertraege" onclick="showCustTab('tab-vertraege',this)">📑 Verträge <span style="opacity:.7;">({{ $customer->contracts->count() }})</span></button>
    <button type="button" class="cust-tab" data-tab="tab-dokumente" onclick="showCustTab('tab-dokumente',this)">📎 Dokumente <span style="opacity:.7;">({{ $customer->documents->count() }})</span></button>
    <button type="button" class="cust-tab" data-tab="tab-tickets" onclick="showCustTab('tab-tickets',this)">🎫 Tickets <span style="opacity:.7;">({{ $customer->tickets->count() }})</span></button>
    <button type="button" class="cust-tab" data-tab="tab-intern" onclick="showCustTab('tab-intern',this)">💬 Intern <span style="opacity:.7;">({{ $internalChat->count() }})</span></button>
    <button type="button" class="cust-tab" data-tab="tab-notizen" onclick="showCustTab('tab-notizen',this)">📝 Notizen</button>
    <button type="button" class="cust-tab" data-tab="tab-verlauf" onclick="showCustTab('tab-verlauf',this)">🔄 Verlauf</button>
</div>
<div class="tab-section" id="tab-uebersicht">
{{-- Vertragsstruktur Icons --}}
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:16px;">Vertragsstruktur</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        @foreach($typeConfig as $key => $cfg)
        @php $isActive = in_array($key, $activeTypes); @endphp
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;"
            onclick="document.getElementById('filter-type').value='{{ $key }}';filterContracts()">
            <div style="width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;
                background:{{ $isActive ? $cfg['bg'] : '#EEF0F3' }};
                border:2px solid {{ $isActive ? $cfg['color'] : '#E4E6EA' }};
                opacity:{{ $isActive ? '1' : '0.4' }};
                transition:.2s;">
                {{ $cfg['icon'] }}
            </div>
            <span style="font-size:11px;color:{{ $isActive ? $cfg['color'] : 'var(--ink-soft)' }};font-weight:{{ $isActive ? '600' : '400' }};">
                {{ $cfg['label'] }}
            </span>
        </div>
        @endforeach
    </div>
</div>

<div class="grid-2">

{{-- Persönliche Daten --}}
<div class="card">
    <div class="card-title" style="margin-bottom:16px;">Persönliche Daten</div>
    <table style="width:100%;font-size:14px;">
        <tr><td style="color:var(--ink-soft);padding:8px 0;width:130px;">Telefon</td><td>{{ $customer->phone ?? '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Adresse</td><td style="border-top:1px solid var(--line);">{{ $customer->address ?? '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">IBAN</td><td style="border-top:1px solid var(--line);">{{ $customer->iban ? '••••' . substr($customer->iban,-4) : '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Geburtsdatum</td><td style="border-top:1px solid var(--line);">{{ $customer->birth_date ? \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y') : '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Familienstand</td><td style="border-top:1px solid var(--line);">{{ $customer->marital_status ?? '—' }}</td></tr>
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Sprache</td><td style="border-top:1px solid var(--line);">{{ strtoupper($customer->preferred_lang) }}</td></tr>
        @if($customer->company_name)
        <tr><td style="color:var(--ink-soft);padding:8px 0;border-top:1px solid var(--line);">Firma</td><td style="border-top:1px solid var(--line);">{{ $customer->company_name }} @if($customer->company_type)<span style="font-size:12px;color:var(--ink-soft);">({{ $customer->company_type }})</span>@endif</td></tr>
        @endif
    </table>
</div>

{{-- Kundenakte: Kranken-/Renten-/Steuerdaten (sensibel, verschlüsselt gespeichert) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">🏥 Kranken-, Renten- & Steuerdaten</div>
        <span style="font-size:11.5px;background:#EAF2FB;color:#185FA5;border:1px solid #CFE2F5;padding:3px 10px;border-radius:999px;">🔐 Verschlüsselt gespeichert</span>
    </div>
    <div class="item-row"><span style="font-size:13px;color:var(--ink-soft);">Versicherungsart</span><span style="font-size:13.5px;font-weight:600;">{{ ['gesetzlich'=>'Gesetzlich','privat'=>'Privat'][$customer->health_insurance_type] ?? '—' }}</span></div>
    <div class="item-row"><span style="font-size:13px;color:var(--ink-soft);">Krankenkasse</span><span style="font-size:13.5px;font-weight:600;">{{ $customer->health_insurance_company ?? '—' }}</span></div>
    <div class="item-row"><span style="font-size:13px;color:var(--ink-soft);">KV-Nummer</span><span style="font-size:13.5px;font-weight:600;font-family:monospace;">{{ $customer->health_insurance_number ?? '—' }}</span></div>
    <div class="item-row"><span style="font-size:13px;color:var(--ink-soft);">Rentenversicherungsnr.</span><span style="font-size:13.5px;font-weight:600;font-family:monospace;">{{ $customer->pension_insurance_number ?? '—' }}</span></div>
    <div class="item-row"><span style="font-size:13px;color:var(--ink-soft);">Steuer-ID</span><span style="font-size:13.5px;font-weight:600;font-family:monospace;">{{ $customer->tax_id ?? '—' }}</span></div>
    <p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px;">Bearbeitung über „Kunde bearbeiten" – jede Änderung wird auditiert.</p>
</div>

{{-- E-Mail-Verlauf (Priorität 8: alle Informationen am Kunden verbunden) --}}
@php $customerMails = \App\Models\EmailMessage::where('customer_id', $customer->id)->latest('received_at')->limit(6)->get(); @endphp
@if($customerMails->isNotEmpty())
<div class="card">
    <div class="card-title" style="font-size:14px;margin-bottom:10px;">E-Mails ({{ $customerMails->count() }})</div>
    @foreach($customerMails as $cm)
    <div style="padding:8px 0;border-bottom:1px solid var(--line);font-size:13px;">
        <div style="display:flex;justify-content:space-between;gap:10px;">
            <span style="font-weight:600;">{{ Str::limit($cm->subject ?: '(kein Betreff)', 60) }}</span>
            <span class="badge badge-pending" style="flex:none;">{{ $cm->categoryLabel() }}</span>
        </div>
        <div style="color:var(--ink-soft);font-size:12px;margin-top:2px;">
            von {{ $cm->from_name ?: $cm->from_address }} · {{ ($cm->received_at ?? $cm->created_at)->format('d.m.Y H:i') }}
            @if($cm->match_status === 'suggested') · <span style="color:#92400E;">Zuordnung unbestätigt</span>@endif
        </div>
    </div>
    @endforeach
</div>
@endif
</div>{{-- .grid-2 --}}
</div>{{-- #tab-uebersicht --}}

{{-- ================= Dokumente-Tab ================= --}}
<div class="tab-section" id="tab-dokumente" style="display:none;">
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="card-title" style="margin-bottom:0;">📎 Dokumente</div>
        <div style="display:flex;gap:8px;">
            <button onclick="document.getElementById('request-doc-modal').style.display='flex'" class="btn btn-ghost btn-sm">📩 Dokument anfordern</button>
            <button onclick="document.getElementById('smart-doc-modal').style.display='flex'" class="btn btn-primary btn-sm">⚡ Smart-Upload (KI)</button>
            <button onclick="document.getElementById('add-doc-modal').style.display='flex'" class="btn btn-gold btn-sm">+ Hochladen</button>
        </div>
    </div>
    @php $docs = $customer->documents; @endphp
    @forelse($docs as $d)
    @php
        $dotColor = ['red'=>'#E24B4A','yellow'=>'#F0A500','green'=>'#3B7A57'][$d->color ?? 'green'];
        $docContract = $d->contract_id ? $customer->contracts->firstWhere('id', $d->contract_id) : null;
    @endphp
    <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line);">
        <div style="width:10px;height:10px;border-radius:50%;background:{{ $dotColor }};flex:none;" title="Priorität"></div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:14px;font-weight:600;">{{ $d->file_name }}</div>
            <div style="font-size:12px;color:var(--ink-soft);display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:2px;">
                <span>{{ \App\Models\Document::CATEGORIES[$d->category] ?? ucfirst($d->category) }}</span>
                <span>· {{ $d->created_at->format('d.m.Y') }}</span>
                @if(($d->visibility ?? 'customer') === 'internal')<span style="background:#F7E7D6;color:#B5651D;padding:1px 6px;border-radius:4px;">🔒 intern</span>@else<span style="background:#EAF2FB;color:#185FA5;padding:1px 6px;border-radius:4px;">👤 Kunde</span>@endif
                @if($docContract)<span style="background:#E4F0E7;color:#3B7A57;padding:1px 6px;border-radius:4px;">{{ $docContract->typeIcon() }} {{ $docContract->insurer }}</span>@endif
                @if($d->aiInProgress())<span style="background:#FEF3C7;color:#92400E;padding:1px 6px;border-radius:4px;">⏳ KI-Analyse läuft</span>
                @elseif($d->aiTypeLabel())<span style="background:#d9f4e6;color:#128a4b;padding:1px 6px;border-radius:4px;" title="{{ $d->ai_summary }}">⚡ {{ $d->aiTypeLabel() }}</span>@endif
                @if($d->uploader)<span>· {{ $d->uploader->name }}</span>@endif
            </div>
        </div>
        <div style="display:flex;gap:4px;flex:none;align-items:center;">
            <a href="{{ route('admin.documents.download', $d->id) }}" class="btn btn-ghost btn-sm" title="Herunterladen">⬇</a>
            <button type="button" class="btn btn-ghost btn-sm" title="Bearbeiten"
                data-doc-id="{{ $d->id }}" data-doc-name="{{ $d->file_name }}" data-doc-category="{{ $d->category }}"
                data-doc-visibility="{{ $d->visibility ?? 'customer' }}" data-doc-color="{{ $d->color ?? 'green' }}" data-doc-contract="{{ $d->contract_id }}"
                onclick="openDocEditFromBtn(this)">✏️</button>
            <form method="POST" action="{{ route('admin.documents.replace', $d->id) }}" enctype="multipart/form-data" style="display:inline;margin:0;">
                @csrf
                <label class="btn btn-ghost btn-sm" style="cursor:pointer;margin:0;" title="Datei ersetzen">↺<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="display:none;" onchange="this.form.submit()"></label>
            </form>
            <form method="POST" action="{{ route('admin.documents.destroy', $d->id) }}" onsubmit="return confirm('Dokument „{{ $d->file_name }}“ wirklich löschen?');" style="display:inline;margin:0;">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;" title="Löschen">🗑</button>
            </form>
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Keine Dokumente. Über „+ Hochladen" können Sie Dateien hinzufügen und einem Vertrag zuordnen.</p>
    @endforelse

    {{-- Dokumentenanfragen (Priorität 7): Dokument beim Kunden anfordern --}}
    @php $docRequests = \App\Models\DocumentRequest::with('contract')->where('customer_id', $customer->id)->latest()->limit(8)->get(); @endphp
    <div class="card-title" style="font-size:14px;margin:22px 0 10px;">Angeforderte Dokumente</div>
    @forelse($docRequests as $dr)
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px;">
        <div>
            <span style="font-weight:600;">{{ $dr->title }}</span>
            @if($dr->deadline)<span style="color:var(--ink-soft);"> · Frist {{ $dr->deadline->format('d.m.Y') }}</span>@endif
        </div>
        <span class="badge {{ $dr->status === 'approved' ? 'badge-active' : ($dr->status === 'rejected' ? 'badge-danger' : 'badge-pending') }}">{{ $dr->statusLabel() }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:13px;">Keine Dokumentenanfragen.</p>
    @endforelse
</div>
</div>

<div class="tab-section" id="tab-vertraege" style="display:none;">
{{-- Beitrags-Statistik: was zahlt der Kunde ueber alle AKTIVEN Vertraege
     (auf den Monat normiert, damit unterschiedliche Zahlweisen vergleichbar sind). --}}
@php
    $activeContracts = $customer->contracts->where('status', 'active');
    $monthlyTotal = $activeContracts->sum(fn($c) => $c->monthlyPremium());
    $yearlyTotal  = $activeContracts->sum(fn($c) => $c->yearlyPremium());
    $withPremium  = $activeContracts->filter(fn($c) => $c->hasPremium())->count();
    $eur = fn($v) => number_format((float) $v, 2, ',', '.') . ' €';
@endphp
@if($withPremium > 0)
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:14px;">💶 Beitragsübersicht (aktive Verträge)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;">
        <div style="background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px 16px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">Monatlich gesamt</div>
            <div style="font-size:22px;font-weight:700;">{{ $eur($monthlyTotal) }}</div>
        </div>
        <div style="background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px 16px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">Jährlich gesamt</div>
            <div style="font-size:22px;font-weight:700;">{{ $eur($yearlyTotal) }}</div>
        </div>
        <div style="background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px 16px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">Verträge mit Beitrag</div>
            <div style="font-size:22px;font-weight:700;">{{ $withPremium }} <span style="font-size:13px;color:var(--ink-soft);font-weight:500;">von {{ $activeContracts->count() }}</span></div>
        </div>
    </div>
</div>
@endif
{{-- Verträge --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="card-title">Verträge</div>
        <select id="filter-type" onchange="filterContracts()" style="padding:6px 12px;border:1px solid var(--line);border-radius:6px;font-size:13px;">
            <option value="">Alle Typen</option>
            @foreach($typeConfig as $key => $cfg)
            <option value="{{ $key }}">{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
            @endforeach
        </select>
    </div>
    <table id="contracts-table" style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#F8F9FA;">
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Typ</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Versicherer</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Vertragsnr.</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Beginn</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Beitrag</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Status</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Hinzugefügt von</th>
            <th style="text-align:right;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Aktionen</th>
        </tr></thead>
        <tbody>
        @forelse($customer->contracts as $c)
        @php $cfg = $typeConfig[$c->type] ?? $typeConfig['andere']; @endphp
        <tr class="contract-row" data-type="{{ $c->type }}" style="border-bottom:1px solid var(--line);">
            <td style="padding:12px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;border-radius:8px;background:{{ $cfg['bg'] }};display:flex;align-items:center;justify-content:center;font-size:16px;">{{ $c->typeIcon() }}</span>
                    <div>
                        <span style="font-weight:600;">{{ $c->typeLabel() }}</span>
                        @if($c->subtypeLabel())<div style="font-size:11.5px;color:var(--ink-soft);">{{ $c->subtypeLabel() }}</div>@endif
                    </div>
                </div>
            </td>
            <td style="padding:12px;">{{ $c->insurer }}</td>
            <td style="padding:12px;font-family:monospace;font-size:13px;">{{ $c->contract_number }}</td>
            <td style="padding:12px;color:var(--ink-soft);font-size:13px;">{{ $c->start_date ? \Carbon\Carbon::parse($c->start_date)->format('d.m.Y') : '—' }}</td>
            <td style="padding:12px;font-size:13px;white-space:nowrap;">
                @if($c->hasPremium())
                    <div style="font-weight:600;">{{ $eur($c->premium_amount) }}</div>
                    <div style="font-size:11.5px;color:var(--ink-soft);">{{ $c->premiumIntervalLabel() }}@if($c->premium_interval !== 'monthly') · {{ $eur($c->monthlyPremium()) }}/Mon.@endif</div>
                @else
                    <span style="color:var(--ink-soft);">—</span>
                @endif
            </td>
            <td style="padding:12px;">
                <span class="badge badge-{{ $c->status === 'active' ? 'active' : ($c->status === 'cancelled' ? 'rejected' : 'pending') }}">
                    {{ ['active'=>'Aktiv','pending'=>'In Bearb.','cancelled'=>'Gekündigt','expired'=>'Abgelaufen'][$c->status] ?? $c->status }}
                </span>
            </td>
            <td style="padding:12px;font-size:12px;color:var(--ink-soft);">
                {{ $c->added_by ?? 'System' }}
                @php $openReminder = $c->switchReminders->whereNull('responded_at')->isNotEmpty(); @endphp
                @if($openReminder)
                {{-- Wechsel-Erinnerung offen: Klick stoppt das Follow-up (Paket C2) --}}
                <form method="POST" action="{{ route('admin.contracts.switch_responded', $c->id) }}" style="margin-top:6px;">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:4px 8px;font-size:11px;" title="Wechsel-Erinnerung wurde beantwortet – keine Folge-Erinnerung senden">✋ Kunde hat reagiert</button>
                </form>
                @elseif($c->switchReminders->isNotEmpty())
                <div style="margin-top:6px;font-size:11px;color:#3B7A57;">✓ Erinnerung beantwortet</div>
                @endif
            </td>
            <td style="padding:12px;text-align:right;white-space:nowrap;">
                <a href="{{ route('admin.contract.edit', $c->id) }}" class="btn btn-ghost btn-sm" title="Vertrag bearbeiten">✏️ Bearbeiten</a>
                <form method="POST" action="{{ route('admin.contract.destroy', $c->id) }}" onsubmit="return confirm('Vertrag {{ $c->insurer }} wirklich löschen?');" style="display:inline;margin:0;">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;" title="Vertrag löschen">🗑</button>
                </form>
            </td>
        </tr>
        @if($c->vehicleDetail || $c->energyDetail || $c->internetDetail)
        <tr class="contract-row" data-type="{{ $c->type }}">
            <td colspan="8" style="padding:4px 12px 12px;">
                <div style="font-size:12.5px;color:var(--ink-soft);background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:8px 12px;">
                    @if($v = $c->vehicleDetail)
                        @php $vMileage = $v->mileageStatus(); $vLatest = $v->latestMileageReading(); @endphp
                        {{ $v->vehicleTypeIcon() }} <b>{{ $v->license_plate ?? '—' }}</b> · {{ $v->manufacturer }} {{ $v->model }}@if($v->vehicleTypeLabel()) ({{ $v->vehicleTypeLabel() }})@endif
                        @if($v->first_registration) · EZ {{ $v->first_registration->format('m/Y') }}@endif
                        · <b>{{ $v->coverageLabel() }}</b>
                        @if($v->sf_liability_class)
                            <br>📊 SF Haftpflicht: <b>{{ \App\Models\ContractVehicleDetail::sfLabel($v->sf_liability_class) }}</b>@if($v->sf_liability_valid_from) (ab {{ $v->sf_liability_valid_from->format('d.m.Y') }})@endif {{ $v->sfTransferable('haftpflicht') ? '🟢' : '🔴 Sondereinstufung' }}
                            @if($v->has_vollkasko && $v->sf_comprehensive_class) · SF Vollkasko: <b>{{ \App\Models\ContractVehicleDetail::sfLabel($v->sf_comprehensive_class) }}</b> {{ $v->sfTransferable('vollkasko') ? '🟢' : '🔴 Sondereinstufung' }}@endif
                        @endif
                        @if($v->extrasLabels())
                            <br>🧩 {{ implode(' · ', $v->extrasLabels()) }}
                        @endif
                        @if($vLatest || $v->annual_mileage)
                            <br>🧭 @if($vLatest){{ number_format($vLatest->mileage, 0, ',', '.') }} km ({{ $vLatest->reading_date->format('d.m.Y') }})@endif
                            @if($v->annual_mileage) · vereinbart {{ number_format($v->annual_mileage, 0, ',', '.') }} km/Jahr @endif
                            @if($vMileage && $vMileage['exceeded']) <b style="color:#A32D2D;">⚠️ hochgerechnet {{ number_format($vMileage['projected'], 0, ',', '.') }} km/Jahr – Limit überschritten</b>@endif
                        @endif
                        @if($v->claims->isNotEmpty())
                            <br>⚠️ {{ $v->claims->count() }} Schaden{{ $v->claims->count() > 1 ? 'fälle' : 'fall' }}: @foreach($v->claims->take(4) as $claim){{ $claim->claim_date?->format('m/Y') ?? '—' }} ({{ $claim->typeLabel() }}@if($claim->status), {{ $claim->statusLabel() }}@endif)@if(!$loop->last), @endif @endforeach
                        @endif
                    @elseif($e = $c->energyDetail)
                        {{ $c->typeIcon() }} {{ $e->tariff ?? 'Tarif —' }}@if($e->consumption_kwh) · {{ number_format($e->consumption_kwh, 0, ',', '.') }} kWh/Jahr @endif
                        @if($e->customer_number) · Kd-Nr.: {{ $e->customer_number }}@endif
                        @if($e->meter_number) · Zähler: {{ $e->meter_number }}@endif
                        @if($e->malo_id) · MaLo-ID: <b>{{ $e->malo_id }}</b>@endif
                        @if($e->grid_operator) · Netz: {{ $e->grid_operator }}@endif
                    @elseif($i = $c->internetDetail)
                        📶 {{ $i->tariff ?? 'Tarif —' }}@if($i->speed) · {{ $i->speed }}@endif
                    @endif
                </div>
            </td>
        </tr>
        @endif

        @empty
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Verträge.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</div>

<div class="tab-section" id="tab-tickets" style="display:none;">
{{-- Anträge --}}
<div class="card">
    <div class="card-title" style="margin-bottom:16px;">Anträge</div>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead><tr style="background:#F8F9FA;">
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Betreff</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Typ</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Datum</th>
            <th style="text-align:left;padding:10px 12px;font-size:12px;color:var(--ink-soft);border-bottom:1px solid var(--line);">Status</th>
            <th style="border-bottom:1px solid var(--line);"></th>
        </tr></thead>
        <tbody>
        @forelse($customer->tickets as $t)
        <tr>
            <td style="padding:12px;font-weight:600;">{{ $t->subject }}</td>
            <td style="padding:12px;color:var(--ink-soft);">{{ ucfirst(str_replace('_',' ',$t->type)) }}</td>
            <td style="padding:12px;color:var(--ink-soft);font-size:13px;">{{ $t->created_at->format('d.m.Y') }}</td>
            <td style="padding:12px;"><span class="badge badge-{{ $t->status === 'open' ? 'open' : 'closed' }}">{{ ['open'=>'Offen','in_progress'=>'In Bearb.','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span></td>
            <td style="padding:12px;"><a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        @empty
        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Anträge.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</div>

<div class="tab-section" id="tab-intern" style="display:none;">

{{-- Interner Chat: NUR für Mitarbeiter, wird niemals im Kundenportal angezeigt --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
        <div class="card-title">💬 Interner Chat</div>
        <span style="font-size:11.5px;background:#FFF8E6;color:#B5651D;border:1px solid #F7E7D6;padding:3px 10px;border-radius:999px;">🔒 Nur für Mitarbeiter sichtbar</span>
    </div>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">Erwähnen Sie Kollegen mit <b>@Name</b>, <b>@Support</b>, <b>@Manager</b> oder <b>@Admin</b> – sie erhalten eine Benachrichtigung.</p>
    <div id="internal-chat-scroll" style="max-height:420px;overflow-y:auto;padding:6px 2px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:14px;">
        @forelse($internalChat as $msg)
        @php $own = $msg->sender_id === auth()->id(); @endphp
        <div class="chat-row" style="{{ $own ? 'flex-direction:row-reverse;' : '' }}">
            <div class="chat-avatar" style="{{ $own ? 'background:var(--gold);' : '' }}">{{ strtoupper(mb_substr($msg->sender?->name ?? '??', 0, 2)) }}</div>
            <div style="max-width:75%;">
                <div style="font-size:11px;color:var(--ink-soft);margin-bottom:3px;{{ $own ? 'text-align:right;' : '' }}">
                    {{ $msg->sender?->name ?? 'Gelöschter Nutzer' }} · {{ $msg->created_at->format('d.m.Y H:i') }}
                </div>
                <div class="chat-bubble" style="{{ $own ? 'background:var(--petrol);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;border:1px solid var(--line);border-bottom-left-radius:4px;' }}">
                    {!! $msg->renderedMessage() !!}
                </div>
                @can('delete', $msg)
                <form method="POST" action="{{ route('admin.internal.destroy', $msg->id) }}" onsubmit="return confirm('Nachricht wirklich löschen?');" style="margin-top:2px;{{ $own ? 'text-align:right;' : '' }}">
                    @csrf @method('DELETE')
                    <button type="submit" style="border:none;background:none;color:var(--ink-soft);font-size:11px;cursor:pointer;">Löschen</button>
                </form>
                @endcan
            </div>
        </div>
        @empty
        <p style="color:var(--ink-soft);font-size:14px;text-align:center;padding:20px 0;">Noch keine internen Nachrichten zu diesem Kunden.</p>
        @endforelse
    </div>
    <form method="POST" action="{{ route('admin.internal.store', $customer->id) }}" style="display:flex;gap:10px;margin-top:14px;align-items:flex-end;">
        @csrf
        <input type="hidden" name="type" value="chat">
        <textarea name="message" required maxlength="5000" placeholder="Interne Nachricht… z.B. @Support Bitte prüfen, warum die Police noch nicht aktiviert wurde." style="flex:1;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;min-height:52px;"></textarea>
        <button type="submit" class="btn btn-primary" style="height:44px;">Senden</button>
    </form>
</div>
</div>

<div class="tab-section" id="tab-notizen" style="display:none;">
<div class="grid-2">
{{-- Notizen & Aufgaben --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="card-title">Notizen & Aufgaben</div>
        <button onclick="document.getElementById('add-note-modal').style.display='flex'" style="width:28px;height:28px;border-radius:50%;border:none;background:var(--petrol);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
    </div>
    @php $notes = \App\Models\CustomerNote::where('customer_id',$customer->id)->with('createdBy')->latest()->get(); @endphp
    @forelse($notes as $n)
    <div style="padding:10px 0;border-bottom:1px solid var(--line);display:flex;align-items:flex-start;gap:10px;">
        <span style="font-size:16px;">{{ $n->type === 'task' ? '✅' : '📝' }}</span>
        <div style="flex:1;">
            <div style="font-size:13px;line-height:1.5;">{{ $n->note }}</div>
            <div style="font-size:11px;color:var(--ink-soft);margin-top:3px;">
                {{ $n->createdBy?->name }} · {{ $n->created_at->format('d.m.Y H:i') }}
                @if($n->due_date) · Fällig: {{ \Carbon\Carbon::parse($n->due_date)->format('d.m.Y') }} @endif
            </div>
        </div>
        @if($n->type === 'task')
        <form method="POST" action="{{ route('admin.customer.note.done', $n->id) }}">
            @csrf @method('PUT')
            <button type="submit" style="border:none;background:none;cursor:pointer;font-size:14px;color:{{ $n->is_done ? '#3B7A57' : 'var(--ink-soft)' }};">
                {{ $n->is_done ? '✓' : '○' }}
            </button>
        </form>
        @endif
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Notizen.</p>
    @endforelse
</div>

{{-- Interne Notizen (dauerhaft, nur Mitarbeiter) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title">📌 Interne Notizen</div>
        <span style="font-size:11.5px;background:#FFF8E6;color:#B5651D;border:1px solid #F7E7D6;padding:3px 10px;border-radius:999px;">🔒 Nur intern</span>
    </div>
    @forelse($internalNotes as $note)
    <div style="padding:10px 12px;border:1px solid var(--line);border-left:3px solid var(--gold);border-radius:8px;margin-bottom:10px;background:#FDFDFB;">
        <div style="font-size:13px;line-height:1.55;">{!! $note->renderedMessage() !!}</div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:5px;">
            <span style="font-size:11px;color:var(--ink-soft);">{{ $note->sender?->name ?? 'Gelöschter Nutzer' }} · {{ $note->created_at->format('d.m.Y H:i') }}</span>
            @can('delete', $note)
            <form method="POST" action="{{ route('admin.internal.destroy', $note->id) }}" onsubmit="return confirm('Notiz wirklich löschen?');" style="margin:0;">
                @csrf @method('DELETE')
                <button type="submit" style="border:none;background:none;color:var(--ink-soft);font-size:11px;cursor:pointer;">Löschen</button>
            </form>
            @endcan
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine internen Notizen. Beispiele: „Kunde bevorzugt Kontakt per WhatsApp.", „Nicht telefonisch kontaktieren, nur E-Mail."</p>
    @endforelse
    <form method="POST" action="{{ route('admin.internal.store', $customer->id) }}" style="margin-top:14px;">
        @csrf
        <input type="hidden" name="type" value="note">
        <div class="field"><textarea name="message" required maxlength="5000" placeholder="Wichtige dauerhafte Information zum Kunden…" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;min-height:64px;"></textarea></div>
        <button type="submit" class="btn btn-primary">Notiz speichern</button>
    </form>
</div>
</div>
</div>


<div class="tab-section" id="tab-verlauf" style="display:none;">
{{-- Änderungsverlauf: alle Self-Service-Anträge dieses Kunden --}}
<div class="card">
    <div class="card-title">🔄 Änderungsverlauf</div>
    @forelse($customer->changeRequests->sortByDesc('created_at') as $cr)
    <div class="item-row">
        <div>
            <div style="font-size:14px;font-weight:600;">{{ $cr->typeLabel() }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">
                Eingereicht {{ $cr->created_at->format('d.m.Y H:i') }}
                @if($cr->reviewer) · {{ $cr->status === 'approved' ? 'genehmigt' : 'abgelehnt' }} von {{ $cr->reviewer->name }} am {{ $cr->reviewed_at?->format('d.m.Y H:i') }}@endif
                @if($cr->notes) · Notiz: {{ $cr->notes }}@endif
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            @if($cr->status === 'pending')<span class="badge badge-pending">Offen</span>
            @elseif($cr->status === 'approved')<span class="badge badge-active">Genehmigt</span>
            @else<span class="badge" style="background:#F9E3E3;color:#A32D2D;">Abgelehnt</span>@endif
            @if($cr->status === 'pending')<a href="{{ route('admin.change_requests') }}" class="btn btn-ghost btn-sm">Prüfen</a>@endif
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Änderungsanfragen dieses Kunden.</p>
    @endforelse
</div>
</div>

{{-- Vertrag anlegen läuft jetzt über die vollständige Seite
     (route admin.contract.create) mit allen Spartenfeldern statt eines
     abgespeckten Modals - siehe Button im Seitenkopf. --}}

{{-- Add Note Modal --}}
<div id="add-note-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="document.getElementById('add-note-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Notiz / Aufgabe hinzufügen</div>
        <form method="POST" action="{{ route('admin.customer.note.store', $customer->id) }}">
            @csrf
            <div class="field"><label>Typ</label>
                <div style="display:flex;gap:12px;margin-bottom:4px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="type" value="note" checked style="width:auto;"> 📝 Notiz</label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="type" value="task" style="width:auto;"> ✅ Aufgabe</label>
                </div>
            </div>
            <div class="field"><label>Text *</label><textarea name="note" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:90px;font-family:inherit;resize:vertical;" placeholder="Notiz oder Aufgabenbeschreibung..."></textarea></div>
            <div class="field"><label>Fälligkeitsdatum (optional)</label><input type="date" name="due_date"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('add-note-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

{{-- Smart-Upload Modal (KI): Typ wird automatisch erkannt, Daten extrahiert --}}
<div id="smart-doc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:520px;position:relative;">
        <button onclick="document.getElementById('smart-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">⚡ Smart-Upload (KI)</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;">
            Dateien hochladen – die KI erkennt den Dokumenttyp, liest die Daten und ordnet passende Verträge automatisch zu.
            Mehrere Bilder werden zu EINEM mehrseitigen Dokument gebündelt.
        </p>
        <div id="smart-dropzone" style="border:2px dashed var(--line);border-radius:10px;padding:26px;text-align:center;cursor:pointer;margin-bottom:14px;transition:.15s;">
            <div style="font-size:30px;margin-bottom:6px;">⚡</div>
            <div style="font-size:13.5px;color:var(--ink-soft);">Dateien hierher ziehen oder <span style="color:var(--gold);font-weight:600;">durchsuchen</span></div>
            <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">PDF, JPG, PNG, WEBP · max. 10 MB pro Datei</div>
            <input type="file" id="smart-doc-input" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;">
        </div>
        <div id="smart-file-list" style="margin-bottom:14px;"></div>
        <div class="field"><label>Sichtbarkeit</label>
            <select id="smart-visibility" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="internal">🔒 Nur intern (nach Prüfung freigeben)</option>
                <option value="customer">👤 Kundensichtbar</option>
            </select>
        </div>
        <div id="smart-progress" style="display:none;margin-bottom:14px;">
            <div style="height:8px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;">
                <div id="smart-progress-bar" style="height:100%;width:0;background:var(--gold);transition:width .2s;"></div>
            </div>
            <div id="smart-progress-label" style="font-size:12px;color:var(--ink-soft);margin-top:5px;">0%</div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('smart-doc-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
            <button type="button" class="btn btn-primary" id="smart-upload-btn">⚡ Hochladen &amp; analysieren</button>
        </div>
    </div>
</div>
<script>
(function() {
    const dz = document.getElementById('smart-dropzone');
    const input = document.getElementById('smart-doc-input');
    const list = document.getElementById('smart-file-list');
    const btn = document.getElementById('smart-upload-btn');
    if (!dz) return;
    const fmt = b => b < 1024*1024 ? (b/1024).toFixed(0)+' KB' : (b/1024/1024).toFixed(1)+' MB';
    let files = [];
    function render() {
        list.innerHTML = '';
        files.forEach(f => {
            const tooBig = f.size > 10*1024*1024;
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;font-size:12.5px;padding:6px 10px;border:1px solid var(--line);border-radius:6px;margin-bottom:6px;'+(tooBig?'background:#F9E3E3;':'');
            const left = document.createElement('span');
            left.textContent = '📄 ' + f.name;
            const right = document.createElement('span');
            right.style.color = tooBig ? '#A32D2D' : 'var(--ink-soft)';
            right.textContent = fmt(f.size) + (tooBig ? ' · zu groß' : '');
            row.appendChild(left); row.appendChild(right);
            list.appendChild(row);
        });
    }
    dz.addEventListener('click', () => input.click());
    input.addEventListener('change', () => { files = Array.from(input.files); render(); });
    ['dragover','dragenter'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.style.borderColor = 'var(--gold)'; dz.style.background = 'var(--canvas)'; }));
    ['dragleave','drop'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.style.borderColor = 'var(--line)'; dz.style.background = 'transparent'; }));
    dz.addEventListener('drop', ev => { ev.preventDefault(); files = Array.from(ev.dataTransfer.files); render(); });

    btn.addEventListener('click', function() {
        if (!files.length) { input.click(); return; }
        const data = new FormData();
        data.append('_token', @json(csrf_token()));
        data.append('customer_id', @json($customer->id));
        data.append('visibility', document.getElementById('smart-visibility').value);
        files.forEach(f => data.append('files[]', f, f.name));
        const xhr = new XMLHttpRequest();
        const wrap = document.getElementById('smart-progress');
        const bar = document.getElementById('smart-progress-bar');
        const label = document.getElementById('smart-progress-label');
        wrap.style.display = 'block';
        btn.disabled = true;
        xhr.upload.addEventListener('progress', function(e) {
            if (!e.lengthComputable) return;
            const pct = Math.round(e.loaded / e.total * 100);
            bar.style.width = pct + '%';
            label.textContent = pct + '%';
        });
        xhr.addEventListener('load', function() {
            btn.disabled = false;
            if (xhr.status >= 200 && xhr.status < 300) {
                label.textContent = '✓ Hochgeladen – KI-Analyse läuft im Hintergrund';
                bar.style.background = '#3B7A57';
                setTimeout(() => { window.location.href = window.location.pathname + '#tab-dokumente'; window.location.reload(); }, 800);
            } else {
                let msg = 'Fehler beim Upload.';
                try { const j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch(e) {}
                label.textContent = '⚠ ' + msg;
                bar.style.background = '#A32D2D';
            }
        });
        xhr.addEventListener('error', function() {
            btn.disabled = false;
            label.textContent = '⚠ Netzwerkfehler beim Upload.';
            bar.style.background = '#A32D2D';
        });
        xhr.open('POST', @json(route('admin.documents.smart_upload')));
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(data);
    });
})();
</script>

{{-- Add Document Modal --}}
<div id="add-doc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:520px;position:relative;">
        <button onclick="document.getElementById('add-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Dokumente hochladen</div>
        <form method="POST" action="{{ route('admin.customer.document.store', $customer->id) }}" enctype="multipart/form-data" id="doc-upload-form">
            @csrf
            {{-- Drag & Drop Zone (Multi-Upload) --}}
            <div id="dropzone" style="border:2px dashed var(--line);border-radius:10px;padding:26px;text-align:center;cursor:pointer;margin-bottom:14px;transition:.15s;">
                <div style="font-size:30px;margin-bottom:6px;">📎</div>
                <div style="font-size:13.5px;color:var(--ink-soft);">Dateien hierher ziehen oder <span style="color:var(--petrol);font-weight:600;">durchsuchen</span></div>
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">PDF, JPG, PNG, DOC, XLS · max. 10 MB pro Datei · bis zu 20 Dateien</div>
                <input type="file" name="documents[]" id="doc-input" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="display:none;">
            </div>
            <div id="file-list" style="margin-bottom:14px;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Kategorie</label>
                    <select name="category" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        @foreach(\App\Models\Document::CATEGORIES as $ckey => $clabel)
                        <option value="{{ $ckey }}">{{ $clabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Sichtbarkeit</label>
                    <select name="visibility" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="customer">👤 Kundensichtbar</option>
                        <option value="internal">🔒 Nur intern</option>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Priorität</label>
                    <select name="color" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="green">🟢 Normal</option>
                        <option value="yellow">🟡 Wichtig</option>
                        <option value="red">🔴 Dringend</option>
                    </select>
                </div>
                <div class="field"><label>Zu Vertrag zuordnen (optional)</label>
                    <select name="contract_id" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="">— kein Vertrag —</option>
                        @foreach($customer->contracts as $ct)
                        <option value="{{ $ct->id }}">{{ $ct->typeIcon() }} {{ $ct->insurer }}@if($ct->contract_number) · {{ $ct->contract_number }}@endif</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div id="upload-progress" style="display:none;margin-bottom:14px;">
                <div style="height:8px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;">
                    <div id="upload-progress-bar" style="height:100%;width:0;background:var(--petrol);transition:width .2s;"></div>
                </div>
                <div id="upload-progress-label" style="font-size:12px;color:var(--ink-soft);margin-top:5px;">0%</div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('add-doc-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hochladen</button>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    const form = document.getElementById('doc-upload-form');
    const dz = document.getElementById('dropzone');
    const input = document.getElementById('doc-input');
    const list = document.getElementById('file-list');
    if (!dz) return;
    const fmt = b => b < 1024*1024 ? (b/1024).toFixed(0)+' KB' : (b/1024/1024).toFixed(1)+' MB';
    function render() {
        list.innerHTML = '';
        Array.from(input.files).forEach(f => {
            const tooBig = f.size > 10*1024*1024;
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;font-size:12.5px;padding:6px 10px;border:1px solid var(--line);border-radius:6px;margin-bottom:6px;'+(tooBig?'background:#F9E3E3;':'');
            row.innerHTML = '<span>📄 '+f.name+'</span><span style="color:'+(tooBig?'#A32D2D':'var(--ink-soft)')+';">'+fmt(f.size)+(tooBig?' · zu groß':'')+'</span>';
            list.appendChild(row);
        });
    }
    dz.addEventListener('click', () => input.click());
    input.addEventListener('change', render);
    ['dragover','dragenter'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.style.borderColor = 'var(--petrol)'; dz.style.background = 'var(--canvas)'; }));
    ['dragleave','drop'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.style.borderColor = 'var(--line)'; dz.style.background = 'transparent'; }));
    dz.addEventListener('drop', ev => { ev.preventDefault(); input.files = ev.dataTransfer.files; render(); });

    // Echter Upload-Fortschritt via XHR (Punkt 3): Prozent + verbleibende Zeit
    form.addEventListener('submit', function(ev) {
        if (!input.files.length) return; // normales Verhalten, Server validiert
        ev.preventDefault();
        const xhr = new XMLHttpRequest();
        const data = new FormData(form);
        const bar = document.getElementById('upload-progress-bar');
        const wrap = document.getElementById('upload-progress');
        const label = document.getElementById('upload-progress-label');
        wrap.style.display = 'block';
        const started = Date.now();
        xhr.upload.addEventListener('progress', function(e) {
            if (!e.lengthComputable) return;
            const pct = Math.round(e.loaded / e.total * 100);
            bar.style.width = pct + '%';
            const elapsed = (Date.now() - started) / 1000;
            const rate = e.loaded / Math.max(elapsed, 0.1);
            const remain = rate > 0 ? Math.max(0, Math.round((e.total - e.loaded) / rate)) : 0;
            label.textContent = pct + '% · noch ~' + remain + ' s';
        });
        xhr.addEventListener('load', function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                label.textContent = '✓ Erfolgreich hochgeladen';
                bar.style.background = '#3B7A57';
                setTimeout(() => window.location.reload(), 600);
            } else {
                let msg = 'Fehler beim Upload.';
                try { const j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch(e) {}
                label.textContent = '⚠ ' + msg;
                bar.style.background = '#A32D2D';
            }
        });
        xhr.addEventListener('error', function() {
            label.textContent = '⚠ Netzwerkfehler beim Upload.';
            bar.style.background = '#A32D2D';
        });
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(data);
    });
})();
</script>
</div>

<script>
function filterContracts() {
    const type = document.getElementById('filter-type').value;
    document.querySelectorAll('.contract-row').forEach(row => {
        row.style.display = !type || row.dataset.type === type ? '' : 'none';
    });
}
</script>

<div style="display:flex;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid var(--line);">
    <a href="{{ route('admin.customer.merge', $customer->id) }}" class="btn btn-ghost">🔀 Mit Duplikat zusammenführen</a>
    <form method="POST" action="{{ route('admin.customers.delete', $customer->id) }}" onsubmit="return confirm('Kunde {{ $customer->user?->name }} wirklich ENDGÜLTIG löschen? Alle Verträge, Tickets und Dokumente gehen verloren!') && confirm('Wirklich sicher? Diese Aktion kann NICHT rückgängig gemacht werden.');" style="margin:0;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost" style="color:#A32D2D;border-color:#A32D2D;">🗑 Kunde löschen</button>
    </form>
</div>


<script>
function showCustTab(id, btn) {
    document.querySelectorAll('.tab-section').forEach(el => el.style.display = el.id === id ? '' : 'none');
    document.querySelectorAll('.cust-tab').forEach(el => el.classList.toggle('active', el === btn));
    history.replaceState(null, '', '#' + id);
    if (id === 'tab-intern') scrollChatDown();
}
function scrollChatDown() {
    const box = document.getElementById('internal-chat-scroll');
    if (box) box.scrollTop = box.scrollHeight;
}
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '');
    if (hash && document.getElementById(hash) && hash.startsWith('tab-')) {
        const btn = document.querySelector('.cust-tab[data-tab="' + hash + '"]');
        if (btn) showCustTab(hash, btn);
    }
    scrollChatDown();
});
</script>

{{-- Modal: Dokument anfordern (Priorität 7) --}}
<div id="request-doc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:440px;max-width:92vw;position:relative;">
        <button onclick="document.getElementById('request-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:17px;font-weight:700;margin-bottom:6px;">Dokument anfordern</div>
        <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">Der Kunde wird per E-Mail informiert und kann direkt im Portal hochladen.</div>
        <form method="POST" action="{{ route('admin.document_requests.store', $customer->id) }}">
            @csrf
            <div style="display:grid;gap:12px;">
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Welches Dokument? *</label>
                    <input type="text" name="title" required maxlength="255" placeholder="z. B. Kopie des Personalausweises" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                </div>
                <div>
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Hinweis für den Kunden</label>
                    <textarea name="description" rows="2" maxlength="2000" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Frist</label>
                        <input type="date" name="deadline" min="{{ now()->format('Y-m-d') }}" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                    </div>
                    <div>
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">Betrifft Vertrag</label>
                        <select name="contract_id" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;">
                            <option value="">— keiner —</option>
                            @foreach($customer->contracts as $ct)
                            <option value="{{ $ct->id }}">{{ $ct->contract_number }} ({{ $ct->insurer }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('request-doc-modal').style.display='none'">Abbrechen</button>
                <button type="submit" class="btn btn-gold">Anfordern & Kunde informieren</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Dokument bearbeiten (Vertragszuordnung, Kategorie, Sichtbarkeit, Priorität, Name) --}}
<div id="doc-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="document.getElementById('doc-edit-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Dokument bearbeiten</div>
        <form method="POST" id="doc-edit-form" action="">
            @csrf @method('PUT')
            <div class="field"><label>Dateiname</label><input type="text" name="file_name" id="doc-edit-name" maxlength="255" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Kategorie</label>
                    <select name="category" id="doc-edit-category" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        @foreach(\App\Models\Document::CATEGORIES as $ckey => $clabel)
                        <option value="{{ $ckey }}">{{ $clabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Sichtbarkeit</label>
                    <select name="visibility" id="doc-edit-visibility" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="customer">👤 Kundensichtbar</option>
                        <option value="internal">🔒 Nur intern</option>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Priorität</label>
                    <select name="color" id="doc-edit-color" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="green">🟢 Normal</option>
                        <option value="yellow">🟡 Wichtig</option>
                        <option value="red">🔴 Dringend</option>
                    </select>
                </div>
                <div class="field"><label>Zu Vertrag zuordnen</label>
                    <select name="contract_id" id="doc-edit-contract" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="">— kein Vertrag —</option>
                        @foreach($customer->contracts as $ct)
                        <option value="{{ $ct->id }}">{{ $ct->typeIcon() }} {{ $ct->insurer }}@if($ct->contract_number) · {{ $ct->contract_number }}@endif</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('doc-edit-modal').style.display='none'">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>
<script>
function openDocEditFromBtn(btn) {
    openDocEdit(btn.dataset.docId, btn.dataset.docName, btn.dataset.docCategory, btn.dataset.docVisibility, btn.dataset.docColor, btn.dataset.docContract);
}
function openDocEdit(id, name, category, visibility, color, contractId) {
    document.getElementById('doc-edit-form').action = '/admin/documents/' + id;
    document.getElementById('doc-edit-name').value = name || '';
    document.getElementById('doc-edit-category').value = category || 'other';
    document.getElementById('doc-edit-visibility').value = visibility || 'customer';
    document.getElementById('doc-edit-color').value = color || 'green';
    document.getElementById('doc-edit-contract').value = contractId || '';
    document.getElementById('doc-edit-modal').style.display = 'flex';
}
</script>
@endsection
