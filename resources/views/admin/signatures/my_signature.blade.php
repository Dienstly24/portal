@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <span>Meine Unterschrift</span>
    </div>
    <div>
        <h1 class="page-title">Meine Unterschrift</h1>
        <div class="page-sub">
            Ihre persönliche Unterschrift für Dokumente, die Sie für {{ \App\Support\Firmensignatur::name() }} unterschreiben.
            Nur Sie können sie benutzen - und nur nach Bestätigung je Dokument.
        </div>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="card" style="padding:16px 18px;margin-bottom:16px;">
    <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-weight:700;">{{ $user->name }}, {{ $user->signaturFunktion() }}</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-top:3px;">Im Dokument steht vor jeder Unterschrift: „{{ $bestaetigung }}"</div>
        </div>
        <div style="font-size:13px;" id="freigabeStatus">
            @if($freigabe)
                <span class="badge badge-success">🔐 Zwei-Faktor bestätigt</span>
                <span style="color:var(--ink-soft);">gilt bis {{ \Illuminate\Support\Carbon::createFromTimestamp($freigabe['at'] + $stunden * 3600)->lokal()->format('H:i') }} Uhr auf diesem Gerät</span>
            @else
                <span class="badge badge-warning">🔐 Zwei-Faktor-Code nötig</span>
                <span style="color:var(--ink-soft);">beim Speichern und beim Unterschreiben</span>
            @endif
        </div>
    </div>
    @if($user->role === 'admin')
    <form method="POST" action="{{ route('admin.meine_unterschrift.function') }}" style="display:flex;gap:8px;align-items:end;margin-top:14px;flex-wrap:wrap;">
        @csrf
        <div class="field" style="margin:0;">
            <label for="signatur_funktion" style="font-size:12px;">Funktion (steht im Bestätigungssatz und unter der Unterschrift)</label>
            <input id="signatur_funktion" name="signatur_funktion" maxlength="80" value="{{ $user->signatur_funktion }}" placeholder="z.B. Geschäftsführer" style="min-width:260px;">
        </div>
        <button class="btn btn-ghost btn-sm">Speichern</button>
    </form>
    @endif
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:16px;">
    @foreach(['unterschrift' => $unterschrift, 'paraphe' => $paraphe] as $art => $sig)
    <div class="card" style="padding:16px 18px;">
        <div style="font-weight:700;margin-bottom:8px;">{{ \App\Models\UserSignature::KINDS[$art] }}{{ $art === 'paraphe' ? ' (optional)' : '' }}</div>
        <div class="sig-muster">
            @if($sig)
                <img src="{{ route('admin.meine_unterschrift.image', $sig->id) }}" alt="Hinterlegte {{ \App\Models\UserSignature::KINDS[$art] }}">
            @else
                <span style="color:var(--ink-soft);font-size:13px;">Noch nicht hinterlegt</span>
            @endif
            <div class="sig-linie"></div>
            <div class="sig-unter">{{ $user->name }}, {{ $user->signaturFunktion() }}</div>
        </div>
        @if($sig)
        <div style="font-size:12px;color:var(--ink-soft);margin-top:8px;line-height:1.5;">
            {{ $sig->methodLabel() }} am {{ $sig->created_at->lokal()->format('d.m.Y H:i') }}<br>
            SHA-256 <code style="font-size:11px;">{{ substr($sig->hash, 0, 24) }}…</code>
        </div>
        @endif
        <button type="button" class="btn btn-emerald btn-sm" style="margin-top:12px;" data-ms-art="{{ $art }}">{{ $sig ? 'Ersetzen' : 'Jetzt hinterlegen' }}</button>
    </div>
    @endforeach
</div>

{{-- Hinterlegen: Zeichnen / Hochladen / Handy. Eine Karte fuer beide Arten,
     die Art kommt vom Knopf darueber. --}}
<div class="card" style="padding:16px 18px;margin-bottom:16px;" id="msEditor" hidden>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <div style="font-weight:700;" id="msTitel">Unterschrift hinterlegen</div>
        <div class="ms-reiter" role="tablist">
            <button type="button" class="btn btn-ghost btn-sm" data-ms-reiter="zeichnen" aria-selected="true">✍ Zeichnen</button>
            <button type="button" class="btn btn-ghost btn-sm" data-ms-reiter="hochladen">⬆ Hochladen</button>
            <button type="button" class="btn btn-ghost btn-sm" data-ms-reiter="handy">📱 Auf dem Handy</button>
        </div>
    </div>

    <div data-ms-teil="zeichnen">
        <canvas id="msPad" class="ms-pad" aria-label="Unterschriftsfeld"></canvas>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-ghost btn-sm" id="msUndo">↶ Rückgängig</button>
            <button type="button" class="btn btn-ghost btn-sm" id="msLeeren">Löschen</button>
            <span style="font-size:12px;color:var(--ink-soft);align-self:center;">Finger, Stift oder Maus. Mit Stift wird der Druck berücksichtigt.</span>
        </div>
    </div>

    <div data-ms-teil="hochladen" hidden>
        <p style="font-size:13px;color:var(--ink-soft);margin:0 0 8px;">Foto oder Scan Ihrer Unterschrift (PNG, JPG, HEIC). Der Hintergrund wird automatisch entfernt und das Bild zugeschnitten. Dunkler Stift auf weißem Papier, gutes Licht.</p>
        <input type="file" id="msDatei" accept=".png,.jpg,.jpeg,.heic,.heif,image/png,image/jpeg,image/heic">
    </div>

    <div data-ms-teil="handy" hidden>
        <p style="font-size:13px;color:var(--ink-soft);margin:0 0 8px;">Kein Touch-Bildschirm? QR-Code mit dem Handy scannen und dort unterschreiben. Der Link gilt 10 Minuten und nur einmal; die Zeichnung erscheint hier.</p>
        <button type="button" class="btn btn-ghost btn-sm" id="msQrNeu">QR-Code erzeugen</button>
        <div id="msQr" style="margin-top:10px;"></div>
    </div>

    <div style="margin-top:14px;" id="msVorschauBox" hidden>
        <div style="font-size:12px;font-weight:700;margin-bottom:4px;">Vorschau auf einer Unterschriftslinie</div>
        <div class="sig-muster"><img id="msVorschau" alt="Vorschau"><div class="sig-linie"></div><div class="sig-unter">{{ $user->name }}, {{ $user->signaturFunktion() }}</div></div>
    </div>

    <div id="msCodeBox" hidden style="margin-top:12px;">
        <label for="msCode" style="font-size:13px;font-weight:600;">Code aus Ihrer Authenticator-App</label>
        <input id="msCode" inputmode="numeric" autocomplete="one-time-code" maxlength="10" style="max-width:160px;">
    </div>
    <div id="msMeldung" role="status" style="margin-top:10px;font-size:13px;"></div>
    <div style="display:flex;gap:8px;margin-top:12px;">
        <button type="button" class="btn btn-emerald" id="msSpeichern" disabled>Speichern</button>
        <button type="button" class="btn btn-ghost" id="msAbbrechen">Abbrechen</button>
    </div>
</div>

@if($archiv->isNotEmpty())
<div class="card" style="padding:16px 18px;">
    <div style="font-weight:700;margin-bottom:6px;">Frühere Fassungen (archiviert, nie gelöscht)</div>
    <table class="table" style="font-size:13px;">
        @foreach($archiv as $alt)
        <tr><td>{{ \App\Models\UserSignature::KINDS[$alt->kind] ?? $alt->kind }}</td><td>{{ $alt->methodLabel() }}</td>
            <td>{{ $alt->created_at->lokal()->format('d.m.Y') }} – {{ $alt->archived_at?->lokal()->format('d.m.Y') }}</td>
            <td><code style="font-size:11px;">{{ substr($alt->hash, 0, 16) }}…</code></td></tr>
        @endforeach
    </table>
</div>
@endif

<style>
.sig-muster{position:relative;background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px 10px;min-height:110px;display:flex;flex-direction:column;justify-content:flex-end;}
.sig-muster img{max-height:70px;max-width:100%;object-fit:contain;align-self:flex-start;}
.sig-linie{border-top:1px solid var(--ink);margin-top:4px;}
.sig-unter{font-size:11px;color:var(--ink-soft);margin-top:3px;}
.ms-pad{width:100%;height:clamp(180px,32vh,280px);background:#fff;border:2px dashed var(--line);border-radius:12px;display:block;}
.ms-reiter [aria-selected="true"]{background:var(--emerald-soft);border-color:var(--emerald);}
</style>

@pushOnce('cspScripts')
<script src="/js/unterschrift-pad.js" @cspNonce></script>
<script @cspNonce>
(function () {
    var urls = {
        draw: @json(route('admin.meine_unterschrift.draw')),
        upload: @json(route('admin.meine_unterschrift.upload')),
        handy: @json(route('admin.meine_unterschrift.handoff')),
        status: @json(route('admin.meine_unterschrift.handoff.status', '__ID__'))
    };
    var csrf = @json(csrf_token());
    var art = 'unterschrift', reiter = 'zeichnen', pad = null, handoffId = null, abfrage = null, vorschauDaten = null;
    var $ = function (id) { return document.getElementById(id); };

    function meldung(text, fehler) { $('msMeldung').textContent = text || ''; $('msMeldung').style.color = fehler ? '#B3261E' : 'var(--emerald-ink)'; }
    function vorschau(src) { vorschauDaten = src; $('msVorschauBox').hidden = !src; if (src) { $('msVorschau').src = src; } pruefen(); }
    function pruefen() {
        var bereit = reiter === 'zeichnen' ? (pad && !pad.leer()) : (reiter === 'hochladen' ? !!vorschauDaten : !!(handoffId && vorschauDaten));
        $('msSpeichern').disabled = !bereit;
    }
    function zeigeReiter(name) {
        reiter = name;
        document.querySelectorAll('[data-ms-teil]').forEach(function (t) { t.hidden = t.getAttribute('data-ms-teil') !== name; });
        document.querySelectorAll('[data-ms-reiter]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-ms-reiter') === name ? 'true' : 'false'); });
        if (name === 'zeichnen' && pad) { pad.groesse(); }
        vorschau(name === 'zeichnen' ? null : vorschauDaten);
        meldung('');
    }
    function senden(url, body) {
        body.append('_token', csrf);
        body.append('kind', art);
        if (!$('msCodeBox').hidden && $('msCode').value) { body.append('code', $('msCode').value); }
        $('msSpeichern').disabled = true;
        return fetch(url, { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.code_noetig) { $('msCodeBox').hidden = false; $('msCode').focus(); }
                meldung(j.message, !j.ok);
                if (j.ok && j.reload) { window.location.reload(); }
                pruefen();
                return j;
            })
            .catch(function () { meldung('Verbindung fehlgeschlagen. Bitte erneut versuchen.', true); pruefen(); });
    }

    document.querySelectorAll('[data-ms-art]').forEach(function (b) {
        b.addEventListener('click', function () {
            art = b.getAttribute('data-ms-art');
            $('msTitel').textContent = (art === 'paraphe' ? 'Paraphe' : 'Unterschrift') + ' hinterlegen';
            $('msEditor').hidden = false;
            if (!pad) { pad = window.UnterschriftPad($('msPad'), { onChange: pruefen }); } else { pad.groesse(); }
            zeigeReiter('zeichnen');
            $('msEditor').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    document.querySelectorAll('[data-ms-reiter]').forEach(function (b) {
        b.addEventListener('click', function () { zeigeReiter(b.getAttribute('data-ms-reiter')); });
    });
    $('msUndo').addEventListener('click', function () { pad.rueckgaengig(); });
    $('msLeeren').addEventListener('click', function () { pad.loeschen(); });
    $('msAbbrechen').addEventListener('click', function () { $('msEditor').hidden = true; if (abfrage) { clearInterval(abfrage); } });

    $('msDatei').addEventListener('change', function () {
        var datei = $('msDatei').files[0];
        vorschau(null);
        if (!datei) { return; }
        var body = new FormData();
        body.append('datei', datei);
        body.append('vorschau', '1');
        meldung('Bild wird aufbereitet …');
        senden(urls.upload, body).then(function (j) { if (j && j.ok && j.vorschau) { vorschau(j.vorschau); meldung('So erscheint die Unterschrift im Dokument. Passt es? Dann speichern.'); } });
    });

    $('msQrNeu').addEventListener('click', function () {
        var body = new FormData();
        body.append('_token', csrf);
        fetch(urls.handy, { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { meldung(j.message || 'QR-Code konnte nicht erzeugt werden.', true); return; }
                handoffId = j.id; vorschau(null);
                $('msQr').innerHTML = j.qr;
                meldung('Warte auf die Unterschrift vom Handy …');
                if (abfrage) { clearInterval(abfrage); }
                var bis = Date.parse(j.gueltig_bis);
                abfrage = setInterval(function () {
                    if (Date.now() > bis + 60000) { clearInterval(abfrage); meldung('Der QR-Code ist abgelaufen. Bitte neu erzeugen.', true); return; }
                    fetch(urls.status.replace('__ID__', handoffId), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (s) { if (s.fertig) { clearInterval(abfrage); vorschau(s.bild); meldung('Unterschrift vom Handy erhalten. Passt es? Dann speichern.'); } });
                }, 2000);
            });
    });

    $('msSpeichern').addEventListener('click', function () {
        var body = new FormData();
        if (reiter === 'zeichnen') {
            body.append('zeichnung', pad.dataUrl());
            senden(urls.draw, body);
        } else if (reiter === 'hochladen') {
            body.append('datei', $('msDatei').files[0]);
            senden(urls.upload, body);
        } else {
            body.append('handoff_id', handoffId);
            senden(urls.draw, body);
        }
    });
})();
</script>
@endPushOnce
@endsection
