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
                @php
                    // VORSCHLAG, keine Uebernahme: die Portal-Sprache des Kunden
                    // ist ein guter erster Tipp, aber sie gehoert dem Kunden.
                    // Wer hier etwas anderes waehlt, aendert den Wert in der
                    // Kundenakte NICHT.
                    $spracheVorschlag = $customer?->preferred_lang ?: 'de';
                @endphp
                @for($i = 0; $i < 2; $i++)
                <div style="display:grid;grid-template-columns:1fr 1fr 130px 130px;gap:10px;">
                    <input type="text" name="signers[{{ $i }}][name]" maxlength="160" value="{{ old("signers.$i.name", $i === 0 ? ($customer->user?->name ?? '') : '') }}"
                           placeholder="Name{{ $i === 0 ? '' : ' (optional)' }}"
                           style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    <input type="email" name="signers[{{ $i }}][email]" maxlength="190" value="{{ old("signers.$i.email", $i === 0 ? ($customer->user?->email ?? '') : '') }}"
                           placeholder="E-Mail{{ $i === 0 ? '' : ' (optional)' }}"
                           style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                    <select name="signers[{{ $i }}][locale]" aria-label="Sprache des Unterzeichners"
                            style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                        @foreach(\App\Models\SignatureSigner::LOCALES as $code => $bezeichnung)
                        <option value="{{ $code }}" @selected(old("signers.$i.locale", $i === 0 ? $spracheVorschlag : 'de') === $code)>{{ $bezeichnung }}</option>
                        @endforeach
                    </select>
                    {{-- Nur bei Pruefung "Geburtsdatum" sichtbar. Der Wert
                         wird verschluesselt gespeichert und nie wieder
                         angezeigt - er dient ausschliesslich dem Vergleich. --}}
                    <input type="text" name="signers[{{ $i }}][date_of_birth]" data-dob-feld maxlength="20"
                           value="{{ old("signers.$i.date_of_birth", $i === 0 ? ($customer?->birth_date?->format('d.m.Y') ?? '') : '') }}"
                           placeholder="Geb. TT.MM.JJJJ" aria-label="Geburtsdatum des Unterzeichners" hidden
                           style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                @endfor
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigAddSigner">+ Weiteren Unterzeichner</button>
                <span class="muted-sm">Einladung, Unterschriftsseite und Abschluss-Mail erscheinen in der
                gewählten Sprache. Die Portal-Sprache des Kunden bleibt davon unberührt.</span>
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
                    <label for="identity_check">Zusätzliche Identitätsprüfung</label>
                    <select id="identity_check" name="identity_check"
                            style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                        @foreach(\App\Models\SignatureRequest::IDENTITY_CHECKS as $key => $label)
                        <option value="{{ $key }}" @selected(old('identity_check', \App\Models\SignatureRequest::IDENTITY_EMAIL) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="muted-sm" style="margin-top:6px;">
                        <strong>E-Mail-Bestätigung</strong> ist die stärkste der drei: sie belegt, dass die Person
                        Zugriff auf das eingeladene Postfach hat - ein weitergeleiteter Link allein belegt das nicht.
                        <strong>Geburtsdatum</strong> hält den zufälligen Empfänger eines weitergeleiteten Links auf,
                        ist aber kein Geheimnis (es steht auf jedem Ausweis). Tragen Sie es dafür im nächsten
                        Schritt je Unterzeichner ein. <strong>SMS gibt es bewusst nicht.</strong>
                    </div>
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
// Das Geburtsdatums-Feld hat nur einen Sinn, wenn die Pruefung darauf
// steht. Immer sichtbar waere es eine Aufforderung, ein personenbezogenes
// Datum zu erfassen, das niemand braucht (Datenminimierung).
(function () {
    var wahl = document.getElementById('identity_check');
    function umschalten() {
        var an = wahl && wahl.value === 'geburtsdatum';
        var felder = document.querySelectorAll('[data-dob-feld]');
        for (var i = 0; i < felder.length; i++) { felder[i].hidden = !an; }
        var reihen = document.getElementById('signer-rows');
        if (reihen) {
            for (var j = 0; j < reihen.children.length; j++) {
                reihen.children[j].style.gridTemplateColumns = an ? '1fr 1fr 130px 130px' : '1fr 1fr 130px';
            }
        }
    }
    if (wahl) { wahl.addEventListener('change', umschalten); }
    umschalten();
    window.__sigDobSichtbar = function () { return wahl && wahl.value === 'geburtsdatum'; };
    window.__sigDobUmschalten = umschalten;
})();

window.__h["sigAddSigner"] = function () {
    var rows = document.getElementById('signer-rows');
    var index = rows.children.length;
    if (index >= 10) { return; }
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 130px;gap:10px;';
    var name = document.createElement('input');
    name.type = 'text'; name.name = 'signers[' + index + '][name]';
    name.maxLength = 160; name.placeholder = 'Name (optional)';
    name.style.cssText = 'padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;';
    var mail = document.createElement('input');
    mail.type = 'email'; mail.name = 'signers[' + index + '][email]';
    mail.maxLength = 190; mail.placeholder = 'E-Mail (optional)';
    mail.style.cssText = name.style.cssText;
    var sprache = document.createElement('select');
    sprache.name = 'signers[' + index + '][locale]';
    sprache.setAttribute('aria-label', 'Sprache des Unterzeichners');
    sprache.style.cssText = name.style.cssText;
    // Per Option-Objekt, nicht per HTML-Zeichenkette (SEC-4-Haltung: kein
    // zusammengebautes Markup, auch nicht mit eigenen Werten).
    var sprachen = @json(\App\Models\SignatureSigner::LOCALES);
    Object.keys(sprachen).forEach(function (code) {
        var opt = document.createElement('option');
        opt.value = code; opt.textContent = sprachen[code];
        sprache.appendChild(opt);
    });
    var dob = document.createElement('input');
    dob.type = 'text'; dob.name = 'signers[' + index + '][date_of_birth]';
    dob.maxLength = 20; dob.placeholder = 'Geb. TT.MM.JJJJ';
    dob.setAttribute('data-dob-feld', '1');
    dob.setAttribute('aria-label', 'Geburtsdatum des Unterzeichners');
    dob.style.cssText = name.style.cssText;
    row.appendChild(name); row.appendChild(mail); row.appendChild(sprache); row.appendChild(dob);
    rows.appendChild(row);
    if (window.__sigDobUmschalten) { window.__sigDobUmschalten(); }
};
</script>
@endPushOnce
@endsection
