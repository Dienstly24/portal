{{--
    EIN Einwilligungs-Banner fuer Website UND Portal (Betreiber-Auftrag
    19.09.2026). Begruendung der Bauform: App\Support\Consent.

    SELBSTTRAGEND: eigenes CSS, eigenes Skript mit Nonce, keine
    Abhaengigkeit von Vite oder `ui.js`. Genau deshalb laesst er sich in
    die Website-Vorlagen einbinden, die kein Bundle laden - der alte
    Banner konnte das nicht und fehlte dort.

    KEIN FREMDDIENST: kein Consent-Anbieter, kein externes Skript. Ein
    Einwilligungswerkzeug, das selbst einen Dritten einbindet, ist ein
    Widerspruch in sich.

    Die Ereignisse haengen per addEventListener an den Elementen - kein
    `onclick`-Attribut (seit SEC-4 durch `script-src-attr 'none'`
    ohnehin wirkungslos) und kein `window.__h`, das `ui.js` braeuchte.
--}}
@php
    use App\Support\Consent;

    /*
     * KEIN BANNER OHNE EINWILLIGUNGSPFLICHTIGE TECHNIK. Solange nichts
     * eingebunden ist, worueber der Besucher entscheiden kann, ist eine
     * Abfrage nur ein Klick ins Leere - und die Beschreibung der
     * Kategorie waere eine Aussage ueber einen Dienst, den es nicht
     * gibt. Begruendung: App\Support\Consent::optionaleDiensteVorhanden().
     */
    $consentNoetig = Consent::optionaleDiensteVorhanden();

    $consentRtl = app()->getLocale() === 'ar';
    $consentEntschieden = Consent::entschieden(request());
    $consentStatistik = Consent::erlaubt(request(), Consent::STATISTIK);
    $consentDomain = Consent::domain(request());
@endphp
@if($consentNoetig)
<div id="d24-consent" class="d24-consent" role="dialog" aria-modal="false"
     aria-labelledby="d24-consent-titel" dir="{{ $consentRtl ? 'rtl' : 'ltr' }}" hidden>
  <div class="d24-consent-box">
    <h2 id="d24-consent-titel">{{ $consentRtl ? 'ملفات تعريف الارتباط وحماية البيانات' : 'Cookies & Datenschutz' }}</h2>
    <p>{{ $consentRtl
        ? 'نستخدم ملفات تعريف الارتباط الضرورية لكي يعمل موقعنا وبوابتنا. وبموافقتكم يمكننا استخدام ملفات وتقنيات اختيارية لتحليل استخدام الموقع وتحسين خدماتنا.'
        : 'Wir verwenden notwendige Cookies, damit unsere Website und unser Portal funktionieren. Mit Ihrer Zustimmung können wir optionale Cookies und Technologien einsetzen, um die Nutzung unserer Website zu analysieren und unsere Angebote zu verbessern.' }}</p>

    {{-- Die Auswahl: erst sichtbar, wenn "Einstellungen" gedrueckt wurde.
         Genannt wird NUR, was es wirklich gibt - zwei Kategorien. --}}
    <div id="d24-consent-details" class="d24-consent-details" hidden>
      <div class="d24-consent-zeile">
        <div>
          <strong>{{ $consentRtl ? 'ملفات ضرورية' : 'Notwendige Cookies' }}</strong>
          <span>{{ $consentRtl
              ? 'الجلسة، والحماية من تزوير الطلبات (CSRF)، واللغة، وحفظ اختياركم هنا. بدونها لا يعمل تسجيل الدخول ولا النماذج.'
              : 'Sitzung, Schutz vor Formularmissbrauch (CSRF), Sprachwahl und das Speichern dieser Auswahl. Ohne sie funktionieren Login und Formulare nicht.' }}</span>
        </div>
        <span class="d24-consent-fix">{{ $consentRtl ? 'مفعّلة دائماً' : 'Immer aktiv' }}</span>
      </div>
      <div class="d24-consent-zeile">
        <div>
          <strong>{{ $consentRtl ? 'التحليل / الإحصاء' : 'Analyse / Statistik' }}</strong>
          <span>{{ $consentRtl
              ? 'قياس الوصول عبر Matomo على خادمنا الخاص — بدون ملفات تعريف ارتباط، وبعنوان IP مختصر، وبدون أي طرف ثالث.'
              : 'Reichweitenmessung mit Matomo auf unserem eigenen Server – ohne Cookies, mit gekürzter IP-Adresse und ohne Dritte.' }}</span>
        </div>
        <label class="d24-consent-schalter">
          <input type="checkbox" id="d24-consent-statistik" @checked($consentStatistik)>
          <span>{{ $consentRtl ? 'اختياري' : 'Optional' }}</span>
        </label>
      </div>
    </div>

    <div class="d24-consent-knoepfe">
      <button type="button" id="d24-consent-alle" class="d24-consent-primaer">
        {{ $consentRtl ? 'قبول الكل' : 'Alle akzeptieren' }}
      </button>
      <button type="button" id="d24-consent-ablehnen">
        {{ $consentRtl ? 'رفض' : 'Ablehnen' }}
      </button>
      <button type="button" id="d24-consent-einstellungen">
        {{ $consentRtl ? 'الإعدادات' : 'Einstellungen' }}
      </button>
      <button type="button" id="d24-consent-speichern" class="d24-consent-primaer" hidden>
        {{ $consentRtl ? 'حفظ الاختيار' : 'Auswahl speichern' }}
      </button>
    </div>

    <p class="d24-consent-fuss">
      <a href="{{ url('/cookie-richtlinie') }}">{{ $consentRtl ? 'سياسة الكوكيز' : 'Cookie-Richtlinie' }}</a>
      · <a href="{{ url('/datenschutz') }}">{{ $consentRtl ? 'حماية البيانات' : 'Datenschutz' }}</a>
      · <a href="{{ url('/impressum') }}">{{ $consentRtl ? 'بيانات الناشر' : 'Impressum' }}</a>
    </p>
  </div>
</div>

<style>
.d24-consent{position:fixed;inset-inline:0;bottom:0;z-index:9999;padding:14px;background:rgba(11,19,16,.97);border-top:1px solid rgba(255,255,255,.14);box-shadow:0 -14px 44px rgba(0,0,0,.5);font-family:system-ui,-apple-system,'Inter',Arial,sans-serif;}
.d24-consent[hidden]{display:none!important;}
/* SEC-4-LEHRE (2): `[hidden]` verliert gegen eine Klasse mit eigenem
   `display`. Ohne diese Zeile stuenden der Einstellungen-Block
   (`display:grid`) und der Speichern-Knopf dauerhaft offen - im
   Browser gesehen, von keinem Test bemerkt. Das Partial traegt sein
   CSS selbst, die Regel aus `app.css` gilt hier nicht. */
.d24-consent [hidden]{display:none!important;}
.d24-consent-box{max-width:1000px;margin:0 auto;color:#c9d1cc;max-height:80vh;overflow-y:auto;}
.d24-consent-box h2{margin:0 0 6px;font-size:15px;color:#fff;font-weight:700;}
.d24-consent-box p{margin:0;font-size:13.5px;line-height:1.6;}
.d24-consent-details{margin-top:14px;border-top:1px solid rgba(255,255,255,.12);padding-top:12px;display:grid;gap:10px;}
.d24-consent-zeile{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;}
.d24-consent-zeile strong{display:block;color:#eef1ee;font-size:13.5px;}
.d24-consent-zeile span{font-size:12.5px;line-height:1.55;color:#9fa9a3;}
.d24-consent-fix{flex:none;font-size:12.5px;color:#3ddc8e;font-weight:600;white-space:nowrap;}
.d24-consent-schalter{flex:none;display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#c9d1cc;cursor:pointer;}
.d24-consent-schalter input{width:17px;height:17px;accent-color:#17A65B;cursor:pointer;}
.d24-consent-knoepfe{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px;}
.d24-consent-knoepfe button{min-height:44px;padding:11px 20px;border-radius:11px;font-size:14px;font-weight:600;cursor:pointer;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.2);color:#e6ebe8;}
.d24-consent-knoepfe button:hover{background:rgba(255,255,255,.13);}
.d24-consent-knoepfe .d24-consent-primaer{background:linear-gradient(180deg,#19b463,#128a4b);border-color:#1fc06e;color:#fff;font-weight:700;}
.d24-consent-fuss{margin-top:11px;font-size:12px;}
.d24-consent-fuss a{color:#3ddc8e;text-decoration:none;}
.d24-consent-fuss a:hover{text-decoration:underline;}
@media(max-width:560px){.d24-consent-knoepfe button{flex:1 1 100%;}}
</style>

<script @cspNonce>
(function () {
    var NAME = @json(\App\Support\Consent::COOKIE);
    var ALT = @json(\App\Support\Consent::COOKIE_ALT);
    var VERSION = @json(\App\Support\Consent::VERSION);
    var DOMAIN = @json($consentDomain);
    var TAGE = @json(\App\Support\Consent::TAGE);
    var banner = document.getElementById('d24-consent');
    if (! banner) { return; }

    function lesen(name) {
        var treffer = document.cookie.split('; ').filter(function (t) { return t.indexOf(name + '=') === 0; });
        return treffer.length ? decodeURIComponent(treffer[0].slice(name.length + 1)) : '';
    }

    function schreiben(wert) {
        var ab = new Date();
        ab.setTime(ab.getTime() + TAGE * 864e5);
        /* Secure nur auf https - lokal ueber http wuerde der Browser das
           Cookie sonst verwerfen und der Banner erschiene jedes Mal neu. */
        document.cookie = NAME + '=' + encodeURIComponent(wert)
            + ';expires=' + ab.toUTCString()
            + ';path=/'
            + (DOMAIN ? ';domain=' + DOMAIN : '')
            + ';SameSite=Lax'
            + (location.protocol === 'https:' ? ';Secure' : '');
    }

    /* Die erteilten Kategorien, oder null wenn noch nichts entschieden
       wurde. Dieselbe Lesart wie serverseitig in App\Support\Consent -
       inklusive des Altbestands aus der Zwei-Knopf-Fassung. */
    function erteilt() {
        var roh = lesen(NAME);
        if (roh) {
            var teile = roh.split(':');
            if (teile[0] !== VERSION) { return null; }
            return (teile[1] || '').split(',').filter(Boolean);
        }
        var alt = lesen(ALT);
        if (alt === 'all') { return ['statistik']; }
        if (alt === 'essential') { return []; }
        return null;
    }

    /* Die oeffentliche Schnittstelle: alles, was auf eine Einwilligung
       wartet, meldet sich hier an, statt selbst Cookies zu lesen. So
       gibt es genau EINE Stelle, die entscheidet. */
    var wartende = [];
    window.d24Consent = {
        erteilt: erteilt,
        erlaubt: function (kategorie) {
            var liste = erteilt();
            return !! liste && liste.indexOf(kategorie) !== -1;
        },
        beiFreigabe: function (kategorie, rueckruf) {
            if (this.erlaubt(kategorie)) { rueckruf(); return; }
            wartende.push({ kategorie: kategorie, rueckruf: rueckruf });
        },
        oeffnen: function () { zeigen(true); },
    };

    function anwenden(liste) {
        schreiben(VERSION + ':' + liste.join(','));
        banner.hidden = true;
        /* Nur NEU freigegebene Kategorien starten - und jede genau
           einmal. Ein Ablehnen startet nichts; was bereits laeuft,
           laesst sich per JavaScript ohnehin nicht zurueckholen,
           deshalb sagt der Text ausdruecklich, dass eine Ablehnung ab
           sofort gilt (und ein Neuladen sie vollstaendig durchsetzt). */
        wartende = wartende.filter(function (eintrag) {
            if (liste.indexOf(eintrag.kategorie) === -1) { return true; }
            try { eintrag.rueckruf(); } catch (e) {}
            return false;
        });
        document.dispatchEvent(new CustomEvent('d24:consent', { detail: { erteilt: liste } }));
    }

    function zeigen(mitDetails) {
        document.getElementById('d24-consent-details').hidden = ! mitDetails;
        document.getElementById('d24-consent-speichern').hidden = ! mitDetails;
        document.getElementById('d24-consent-einstellungen').hidden = !! mitDetails;
        banner.hidden = false;
    }

    document.getElementById('d24-consent-alle').addEventListener('click', function () {
        anwenden(@json(\App\Support\Consent::OPTIONAL));
    });
    document.getElementById('d24-consent-ablehnen').addEventListener('click', function () {
        anwenden([]);
    });
    document.getElementById('d24-consent-einstellungen').addEventListener('click', function () {
        zeigen(true);
    });
    document.getElementById('d24-consent-speichern').addEventListener('click', function () {
        var gewaehlt = [];
        if (document.getElementById('d24-consent-statistik').checked) { gewaehlt.push('statistik'); }
        anwenden(gewaehlt);
    });

    /* "Cookie-Einstellungen" im Fuss: jeder Link mit data-consent-oeffnen
       holt den Banner zurueck - das ist der Widerruf (Art. 7 Abs. 3
       DSGVO) und er muss so leicht sein wie die Zustimmung. */
    document.addEventListener('click', function (e) {
        var ziel = e.target.closest ? e.target.closest('[data-consent-oeffnen]') : null;
        if (! ziel) { return; }
        e.preventDefault();
        zeigen(true);
    });

    if (erteilt() === null) { zeigen(false); }
})();
</script>
@endif
