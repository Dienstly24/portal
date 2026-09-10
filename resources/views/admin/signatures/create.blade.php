@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span><span>Neu</span>
    </div>
    <div>
        <div class="page-title">Neue Signaturanfrage</div>
        <div class="page-sub">
            PDF hochladen, Unterzeichner erfassen – die Felder setzen Sie im nächsten Schritt.
            @if(!$customer)Ein Kunde ist <strong>nicht</strong> erforderlich; zuordnen können Sie das Dokument nach der Unterschrift.@endif
        </div>
    </div>
</div>

@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif
@if($errors->any())
<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">
    <ul style="margin:0;padding-left:18px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('admin.signatures.store') }}" enctype="multipart/form-data" style="max-width:820px;">
    @csrf
    @if($customer)<input type="hidden" name="customer_id" value="{{ $customer->id }}">@endif
    @if($contract)<input type="hidden" name="contract_id" value="{{ $contract->id }}">@endif

    <div class="card" style="margin-bottom:16px;">
        <div class="card-head-bar">Dokument</div>
        <div style="padding:18px 20px;display:grid;gap:14px;">
            @if($customer)
            <div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 14px;border-radius:8px;font-size:13.5px;">
                Für Kunde <strong>{{ $customer->user?->name ?? $customer->customer_number }}</strong>
                ({{ $customer->customer_number }})@if($contract) · Vertrag {{ $contract->contract_number ?: $contract->insurer }}@endif
            </div>
            @endif

            <div>
                <label for="document">PDF-Datei <span style="color:#B3261E;">*</span></label>
                <input id="document" type="file" name="document" accept="application/pdf" required
                       style="width:100%;padding:9px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                <div class="muted-sm">Nur PDF, maximal 10 MB. Geschützte (verschlüsselte) PDFs können nicht unterschrieben werden.</div>
            </div>

            <div>
                <label for="title">Titel <span style="color:#B3261E;">*</span></label>
                <input id="title" type="text" name="title" required maxlength="180" value="{{ old('title') }}"
                       placeholder="z. B. Maklervollmacht Mustermann"
                       style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
                <div>
                    <label for="document_type">Dokumentart (optional)</label>
                    <input id="document_type" type="text" name="document_type" maxlength="60" value="{{ old('document_type') }}"
                           style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                <div>
                    <label for="reference">Referenz (optional)</label>
                    <input id="reference" type="text" name="reference" maxlength="120" value="{{ old('reference') }}"
                           style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                <div>
                    <label for="expires_at">Ablaufdatum (optional)</label>
                    <input id="expires_at" type="date" name="expires_at" value="{{ old('expires_at') }}"
                           style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    <div class="muted-sm">Danach ist der Link tot.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-head-bar">Unterzeichner</div>
        <div style="padding:18px 20px;display:grid;gap:12px;">
            <div id="signer-rows" style="display:grid;gap:10px;">
                @for($i = 0; $i < 2; $i++)
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <input type="text" name="signers[{{ $i }}][name]" maxlength="160" value="{{ old("signers.$i.name", $i === 0 ? ($customer->user?->name ?? '') : '') }}"
                           placeholder="Name{{ $i === 0 ? '' : ' (optional)' }}"
                           style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    <input type="email" name="signers[{{ $i }}][email]" maxlength="190" value="{{ old("signers.$i.email", $i === 0 ? ($customer->user?->email ?? '') : '') }}"
                           placeholder="E-Mail{{ $i === 0 ? '' : ' (optional)' }}"
                           style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                @endfor
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigAddSigner">+ Weiteren Unterzeichner</button>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:6px;">
                <div>
                    <label for="signing_order">Reihenfolge</label>
                    <select id="signing_order" name="signing_order"
                            style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                        <option value="sequential">Nacheinander – der Nächste wird erst nach der Unterschrift eingeladen</option>
                        <option value="parallel" @selected(old('signing_order') === 'parallel')>Gleichzeitig – alle bekommen die Einladung sofort</option>
                    </select>
                </div>
                <div>
                    <label>Sicherheit</label>
                    <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;">
                        <input type="checkbox" name="require_email_verification" value="1" @checked(old('require_email_verification', true)) style="margin-top:3px;">
                        <span style="font-size:13.5px;">Bestätigungscode per E-Mail verlangen<br>
                        <span class="muted-sm">Belegt, dass die Person Zugriff auf das eingeladene Postfach hat.</span></span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="card-head-bar">Hinweis vor der Unterschrift</div>
        <div style="padding:18px 20px;">
            <textarea name="consent_text" rows="4" maxlength="2000"
                      style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;font-family:inherit;">{{ old('consent_text', $consentText) }}</textarea>
            {{-- Der Text ist bewusst aenderbar und behauptet von sich aus KEINE
                 qualifizierte elektronische Signatur - was fuer den jeweiligen
                 Geschaeftsfall noetig ist, entscheidet die Rechtspruefung. --}}
            <div class="muted-sm" style="margin-top:8px;">
                Dieser Text steht direkt über dem Bestätigen-Knopf. Das Modul erzeugt eine
                <strong>einfache elektronische Signatur</strong> mit technischem Nachweis – keine qualifizierte
                Signatur im Sinne der eIDAS-Verordnung. Ob das für den Geschäftsfall genügt, ist rechtlich zu prüfen.
            </div>
        </div>
    </div>

    <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-emerald">Weiter zu den Feldern</button>
        <a href="{{ route('admin.signatures.index') }}" class="btn btn-ghost">Abbrechen</a>
    </div>
</form>

{{-- Ereignis-Verdrahtung (SEC-4): ein onclick-Attribut kann keinen Nonce
     tragen; dieses <script @cspNonce> kann es. Der Block steht VOR dem
     @stack des Layouts - ein spaeterer @push fiele still weg. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["sigAddSigner"] = function () {
    var rows = document.getElementById('signer-rows');
    var index = rows.children.length;
    if (index >= 10) { return; }
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:10px;';
    var name = document.createElement('input');
    name.type = 'text'; name.name = 'signers[' + index + '][name]';
    name.maxLength = 160; name.placeholder = 'Name (optional)';
    name.style.cssText = 'padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;';
    var mail = document.createElement('input');
    mail.type = 'email'; mail.name = 'signers[' + index + '][email]';
    mail.maxLength = 190; mail.placeholder = 'E-Mail (optional)';
    mail.style.cssText = name.style.cssText;
    row.appendChild(name); row.appendChild(mail); rows.appendChild(row);
};
</script>
@endPushOnce
@endsection
