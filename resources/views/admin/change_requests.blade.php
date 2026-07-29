@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="page-title">🔄 Kundenänderungen</div>
    <div class="page-sub">Self-Service-Anfragen prüfen, genehmigen oder ablehnen. Daten werden erst nach Genehmigung übernommen. Nachweise werden automatisch gegen die beantragten Angaben geprüft.</div>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;">
    @foreach(['pending' => '⏳ Offen', 'approved' => '✓ Genehmigt', 'rejected' => '✗ Abgelehnt'] as $key => $label)
    <a href="{{ route('admin.change_requests', ['status' => $key]) }}" class="btn {{ $status === $key ? 'btn-primary' : 'btn-ghost' }}" style="font-size:13px;">
        {{ $label }} ({{ $counts[$key] }})
    </a>
    @endforeach
</div>

@php
$fieldLabels = [
    'name'=>'Name','relation'=>'Beziehung','birth_date'=>'Geburtsdatum','type'=>'Typ','street'=>'Straße',
    'zip'=>'PLZ','city'=>'Stadt','country'=>'Land','label'=>'Bezeichnung','value'=>'Wert','iban'=>'IBAN',
    'account_holder'=>'Kontoinhaber','insurer'=>'Gesellschaft','contract_number'=>'Vertragsnummer',
    'start_date'=>'Startdatum','end_date'=>'Enddatum','cancellation_date'=>'Kündigungsdatum','notes'=>'Anmerkung',
    'gender'=>'Geschlecht','marital_status'=>'Familienstand','document_name'=>'Dokument','id'=>null,'document_path'=>null,
    'document_disk'=>null,
    'first_name'=>'Vorname','last_name'=>'Nachname','email'=>'E-Mail','birth_place'=>'Geburtsort','nationality'=>'Nationalität',
    'phone'=>'Telefon','address_street'=>'Straße','address_house_number'=>'Hausnummer','address_house_suffix'=>'Zusatz',
    'address_zip'=>'PLZ','address_city'=>'Ort',
];
$valueLabels = [
    'ehepartner'=>'Ehepartner','kind'=>'Kind','andere'=>'Andere','main'=>'Hauptadresse','billing'=>'Rechnungsadresse',
    'postal'=>'Postadresse','other'=>'Andere Adresse','privat'=>'Privat','geschaeftlich'=>'Geschäftlich',
    'sonstige'=>'Sonstige','male'=>'Männlich','female'=>'Weiblich','diverse'=>'Divers','email'=>'E-Mail','phone'=>'Telefon',
];
// Vertrags-Sparten (kfz, krankenversicherung, ...) lesbar machen, ohne die
// bestehenden Zuordnungen zu ueberschreiben.
foreach (\App\Models\Contract::TYPES as $ck => $cfg) { $valueLabels[$ck] ??= $cfg['label']; }
$fmt = fn($v) => $valueLabels[$v] ?? $v;
@endphp

@forelse($requests as $r)
@php $proof = $r->proofState(); @endphp
<div class="card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:280px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap;">
                <span style="font-size:15px;font-weight:700;">{{ $r->typeLabel() }}</span>
                @if($r->status === 'pending')<span class="badge badge-pending">Offen</span>
                @elseif($r->status === 'approved')<span class="badge badge-active">Genehmigt</span>
                @else<span class="badge" style="background:#F9E3E3;color:#A32D2D;">Abgelehnt</span>@endif
                @if($r->auto_approved)<span class="badge" style="background:#EFEAD8;color:#7A6A34;">🤖 Automatisch freigegeben</span>@endif
                @if($r->proof_status !== 'none')
                <span class="badge" style="background:#fff;border:1px solid {{ $proof['color'] }};color:{{ $proof['color'] }};">{{ $proof['icon'] }} {{ $proof['label'] }}</span>
                @endif
            </div>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:12px;">
                Kunde: <a href="{{ route('admin.customer', $r->customer_id) }}" style="color:var(--petrol);font-weight:600;">{{ $r->customer?->user?->name ?? '—' }}</a>
                · Eingereicht: {{ $r->created_at->format('d.m.Y H:i') }}
                @if($r->effective_from) · <b>Gültig ab: {{ $r->effective_from->format('d.m.Y') }}</b>
                @elseif($r->requiresProof()) · <span style="color:#B5651D;">Gültig-ab fehlt</span> @endif
                @if($r->reviewer) · Bearbeitet von {{ $r->reviewer->name }} am {{ $r->reviewed_at?->format('d.m.Y H:i') }} @endif
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:12px 14px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">Alt</div>
                    @if($r->old_data)
                        @foreach($r->old_data as $k => $v)
                            @if(($fieldLabels[$k] ?? '') !== null && !is_null($v) && $v !== '')
                            <div style="font-size:13px;padding:2px 0;"><span style="color:var(--ink-soft);">{{ $fieldLabels[$k] ?? $k }}:</span> {{ $fmt($v) }}</div>
                            @endif
                        @endforeach
                    @else
                    <div style="font-size:13px;color:var(--ink-soft);">— Neuanlage —</div>
                    @endif
                </div>
                <div style="background:#F0F7F3;border:1px solid #CDE7D8;border-radius:8px;padding:12px 14px;">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#17A65B;margin-bottom:8px;">Neu</div>
                    @foreach($r->new_data as $k => $v)
                        @php $changed = !$r->old_data || ($r->old_data[$k] ?? null) != $v; @endphp
                        @if(($fieldLabels[$k] ?? '') !== null && !is_null($v) && $v !== '')
                        <div style="font-size:13px;padding:2px 0;{{ $changed ? 'background:#FFF3D6;border-radius:4px;padding-left:6px;margin:1px -6px 1px 0;' : '' }}"><span style="color:var(--ink-soft);">{{ $fieldLabels[$k] ?? $k }}:</span> <b>{{ $fmt($v) }}</b>@if($changed)<span style="color:#B5651D;font-size:11px;"> · geändert</span>@endif</div>
                        @endif
                    @endforeach
                    @if(!empty($r->new_data['document_path']))
                    <div style="font-size:13px;padding:4px 0;">📎 <a href="{{ route('admin.change_requests.document', $r->id) }}" style="color:var(--petrol);">{{ $r->new_data['document_name'] ?? 'Dokument öffnen' }}</a></div>
                    @endif
                </div>
            </div>

            {{-- Nachweis + automatische Pruefung: der Mitarbeiter sieht sofort,
                 ob der beantragte Wert wirklich im Dokument steht. --}}
            @if($r->requiresProof() || $r->documents->isNotEmpty())
            <div style="margin-top:12px;border:1px solid {{ $proof['color'] }}33;border-left:3px solid {{ $proof['color'] }};border-radius:8px;padding:12px 14px;background:#FBFAF6;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                    <div style="font-size:13px;font-weight:700;color:{{ $proof['color'] }};">{{ $proof['icon'] }} {{ $proof['label'] }}</div>
                    @if($r->documents->isNotEmpty())
                    <form method="POST" action="{{ route('admin.change_requests.recheck', $r->id) }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn btn-ghost" style="font-size:11.5px;padding:5px 10px;">🔁 Nachweis erneut prüfen</button>
                    </form>
                    @endif
                </div>

                @forelse($r->documents as $doc)
                <div style="font-size:13px;padding:3px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span>📄</span>
                    <a href="{{ route('admin.change_requests.proof', $doc->id) }}" target="_blank" rel="noopener" style="color:var(--petrol);font-weight:600;">{{ $doc->kindLabel() }}</a>
                    <span style="color:var(--ink-soft);font-size:12px;">{{ $doc->file_name }}</span>
                    <a href="{{ route('admin.change_requests.proof', ['id' => $doc->id, 'download' => 1]) }}" style="font-size:11.5px;color:var(--ink-soft);">herunterladen</a>
                    @if($doc->check_status !== 'pending')
                    <span style="font-size:11.5px;color:{{ $doc->check_status === 'match' ? '#17A65B' : ($doc->check_status === 'no_match' ? '#A32D2D' : '#5F6B62') }};">· {{ $doc->checkLabel() }}</span>
                    @endif
                </div>
                @empty
                <div style="font-size:13px;color:#A32D2D;">Kein Nachweis eingereicht – bitte beim Kunden anfordern.</div>
                @endforelse

                @if($r->proofChecks())
                <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">
                    @foreach($r->proofChecks() as $check)
                    <span style="font-size:12px;padding:3px 9px;border-radius:20px;background:{{ $check['passed'] ? '#E8F5EE' : '#F9E3E3' }};color:{{ $check['passed'] ? '#17A65B' : '#A32D2D' }};">
                        {{ $check['passed'] ? '✓' : '✗' }} {{ $check['label'] }}
                        @if(empty($check['required'])) <span style="opacity:.7;">(optional)</span> @endif
                        @if(!empty($check['tolerant'])) <span style="opacity:.7;">· OCR-Toleranz</span> @endif
                    </span>
                    @endforeach
                </div>
                @endif
                @if($r->proof_checked_at)
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:8px;">Automatisch geprüft am {{ $r->proof_checked_at->format('d.m.Y H:i') }} · Ein Treffer belegt, dass die Angabe im Dokument steht – die Echtheit des Dokuments prüft weiterhin der Mensch.</div>
                @endif
            </div>
            @endif

            @if($r->status !== 'pending' && $r->notes)
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:10px;">📝 Notiz: {{ $r->notes }}</div>
            @endif

            @if(($r->notifications_count ?? 0) > 0)
            <div style="margin-top:10px;">
                <a href="{{ route('admin.change_requests.notifications', $r->id) }}" class="btn btn-gold" style="font-size:12.5px;padding:7px 14px;">
                    📨 Mitteilungen an Gesellschaften
                    @if($r->open_notifications > 0) ({{ $r->open_notifications }} offen) @else (alle erledigt) @endif
                </a>
            </div>
            @endif
        </div>

        <div style="min-width:250px;">
            {{-- Rueckfrage: landet als Chat-Nachricht beim Kunden und fuehrt
                 direkt in die Unterhaltung ("ab wann gilt das?"). --}}
            <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
                <a href="{{ route('admin.customer_chat', ['kunde' => $r->customer_id]) }}" class="btn btn-ghost" style="font-size:12px;padding:6px 12px;">💬 Chat öffnen</a>
                <button type="button" onclick="document.getElementById('ask-{{ $r->id }}').style.display = document.getElementById('ask-{{ $r->id }}').style.display === 'none' ? 'block' : 'none';" class="btn btn-ghost" style="font-size:12px;padding:6px 12px;">❓ Rückfrage</button>
            </div>

            <form method="POST" action="{{ route('admin.change_requests.ask', $r->id) }}" id="ask-{{ $r->id }}" style="display:none;margin-bottom:14px;">
                @csrf
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                    @foreach([
                        'Ab wann gilt die Änderung?' => 'Guten Tag, vielen Dank für Ihre Änderungsanfrage. Ab wann gilt die neue Angabe (' . $r->typeLabel() . ')? Bitte teilen Sie uns das Datum kurz mit.',
                        'Nachweis anfordern' => 'Guten Tag, für Ihre Änderung (' . $r->typeLabel() . ') benötigen wir noch einen Nachweis – Ausweis (Vorder- und Rückseite), Meldebescheinigung oder Kontonachweis. Bitte laden Sie ihn im Portal hoch.',
                        'Besseres Foto' => 'Guten Tag, Ihr Nachweis ist leider nicht gut lesbar. Bitte senden Sie uns ein schärferes Foto oder ein PDF, auf dem alle Angaben deutlich zu erkennen sind.',
                    ] as $label => $text)
                    <button type="button" onclick="document.getElementById('ask-body-{{ $r->id }}').value = @js($text);" class="btn btn-ghost" style="font-size:11px;padding:4px 9px;">{{ $label }}</button>
                    @endforeach
                </div>
                <textarea name="body" id="ask-body-{{ $r->id }}" required maxlength="2000" placeholder="Frage an den Kunden …" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-height:70px;font-family:inherit;resize:vertical;"></textarea>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;font-size:12.5px;">Senden & Chat öffnen</button>
            </form>

            @if($r->status === 'pending')
            <form method="POST" action="{{ route('admin.change_requests.action', $r->id) }}">
                @csrf
                <div class="field" style="margin-bottom:10px;">
                    <label style="font-size:12px;">Notiz (optional, bei Ablehnung sichtbar für den Kunden)</label>
                    <textarea name="notes" maxlength="1000" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-height:60px;font-family:inherit;resize:vertical;"></textarea>
                </div>
                @if(in_array($r->proof_status, ['mismatch', 'missing'], true))
                <div style="font-size:12px;color:#A32D2D;margin-bottom:8px;">⚠️ {{ $r->proof_status === 'missing' ? 'Es liegt kein Nachweis vor.' : 'Der Nachweis passt nicht zu den beantragten Angaben.' }} Bitte vor einer Freigabe klären.</div>
                @endif
                <div style="display:flex;gap:8px;">
                    <button type="submit" name="action" value="approve" class="btn btn-primary" style="flex:1;background:#17A65B;" onclick="return confirm('Anfrage genehmigen? Die Kundendaten werden sofort aktualisiert.');">✓ Genehmigen</button>
                    <button type="submit" name="action" value="reject" class="btn btn-ghost" style="flex:1;color:#A32D2D;border-color:#A32D2D;" onclick="return confirm('Anfrage ablehnen?');">✗ Ablehnen</button>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>
@empty
<div class="card"><p style="color:var(--ink-soft);font-size:14px;">Keine {{ ['pending'=>'offenen','approved'=>'genehmigten','rejected'=>'abgelehnten'][$status] }} Anfragen.</p></div>
@endforelse

{{ $requests->links() }}
@endsection
