{{-- Website-Assistent (KI-Verkaufsassistent, Spezifikation Abschnitt 19).

     Ein Besucher soll ohne Formular und ohne Anmeldung ins Gespraech
     kommen. Der Chat laeuft gegen /api/website-assistent; die Zuordnung
     macht die Server-Sitzung, der Browser kennt keine Kennung.

     REGELN DIESER SEITE (Projektvorgabe): keine externen Ressourcen -
     kein CDN, keine Schriftart von aussen, alles inline. Der Knopf
     erscheint erst, wenn der Assistent laut Server verfuegbar ist; sonst
     bleibt die Seite wie bisher (Kontaktformular, WhatsApp).

     Kein Cookie-Banner-Thema: es wird nur die ohnehin vorhandene
     Server-Sitzung genutzt, kein Tracking, kein Drittanbieter. --}}
@php $ar = $isAr ?? false; @endphp
<div id="d24-ai" hidden>
  <button type="button" id="d24-ai-open" aria-expanded="false" aria-controls="d24-ai-box">
    <span aria-hidden="true">💬</span>
    <span>{{ $ar ? 'اسأل مساعدنا' : 'Assistent fragen' }}</span>
  </button>

  <section id="d24-ai-box" hidden aria-live="polite"
           aria-label="{{ $ar ? 'مساعد Dienstly24' : 'Dienstly24 Assistent' }}">
    <header>
      <strong>{{ $ar ? 'مساعد Dienstly24' : 'Dienstly24 Assistent' }}</strong>
      <button type="button" id="d24-ai-close" aria-label="{{ $ar ? 'إغلاق' : 'Schließen' }}">×</button>
    </header>

    <div id="d24-ai-log">
      <p class="ai">{{ $ar
        ? 'مرحباً! كيف يمكننا مساعدتك؟ اكتب طلبك بحرية – إنترنت جديد، تغيير عقد، أو سؤال عام.'
        : 'Guten Tag! Wie können wir helfen? Schreiben Sie einfach, worum es geht – neuer Internetanschluss, Vertragswechsel oder eine allgemeine Frage.' }}</p>
    </div>

    <form id="d24-ai-form">
      <label class="sr-only" for="d24-ai-input">{{ $ar ? 'رسالتك' : 'Ihre Nachricht' }}</label>
      <input type="text" id="d24-ai-input" maxlength="2000" autocomplete="off"
             placeholder="{{ $ar ? 'اكتب رسالتك…' : 'Nachricht schreiben …' }}" required>
      <button type="submit">{{ $ar ? 'إرسال' : 'Senden' }}</button>
    </form>

    <p class="hint">{{ $ar
      ? '🤖 يجيب مساعد آلي أولاً. لا ترسل بيانات بنكية أو بيانات حساسة هنا.'
      : '🤖 Zunächst antwortet ein automatischer Assistent. Bitte senden Sie hier keine Bank- oder Gesundheitsdaten.' }}</p>
  </div>
</div>

<style>
/* Der WhatsApp-Knopf sitzt bereits unten in derselben Ecke (bottom:20px,
   z-index 150). Der Assistent steht deshalb DARUEBER - sonst verdeckt
   WhatsApp ihn und faengt jeden Klick ab. Beide nutzen inset-inline-end,
   damit die Seite in RTL spiegelt, ohne zweite Regel. */
#d24-ai{position:fixed;bottom:84px;inset-inline-end:18px;z-index:151;font-family:inherit;}
/* Das hidden-Attribut muss gewinnen: eine eigene display-Regel schlaegt
   sonst die Vorgabe des Browsers, und der Knopf bleibt neben dem offenen
   Kasten stehen. */
#d24-ai [hidden]{display:none !important;}
#d24-ai-open{display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#19b463,#128a4b);color:#fff;border:0;border-radius:999px;padding:12px 18px;font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 6px 20px rgba(19,26,23,.25);}
#d24-ai-open:hover{filter:brightness(1.05);}
#d24-ai-box{padding:0;margin:0;width:min(360px,calc(100vw - 32px));background:#FBFAF6;border:1px solid #E0DCD0;border-radius:14px;box-shadow:0 16px 44px rgba(19,26,23,.22);overflow:hidden;}
#d24-ai-box header{display:flex;align-items:center;justify-content:space-between;background:#131A17;color:#fff;padding:11px 14px;}
#d24-ai-box header button{background:none;border:0;color:#fff;font-size:22px;line-height:1;cursor:pointer;}
#d24-ai-log{max-height:46vh;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;gap:9px;}
#d24-ai-log p{margin:0;padding:9px 12px;border-radius:12px;font-size:14px;line-height:1.5;max-width:88%;}
#d24-ai-log p.ai{background:#EAF6EF;border:1px solid #BFE0CC;color:#16211C;align-self:flex-start;}
#d24-ai-log p.me{background:#131A17;color:#fff;align-self:flex-end;}
#d24-ai-log p.err{background:#FDECEC;border:1px solid #E9B6B6;color:#8A2B2B;align-self:flex-start;}
#d24-ai-form{display:flex;gap:8px;padding:10px 14px;border-top:1px solid #E0DCD0;}
#d24-ai-form input{flex:1;min-width:0;padding:9px 12px;border:1px solid #E0DCD0;border-radius:9px;font-size:14px;background:#fff;color:#16211C;}
#d24-ai-form button{background:#17A65B;color:#fff;border:0;border-radius:9px;padding:9px 15px;font-weight:600;cursor:pointer;}
#d24-ai-form button[disabled]{opacity:.6;cursor:default;}
#d24-ai .hint{margin:0;padding:0 14px 12px;font-size:11.5px;color:#5F6B62;}
#d24-ai .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);}
/* Ist der Kasten offen, darf er die volle Hoehe nutzen - der
   WhatsApp-Knopf stoert dann nicht mehr, weil der Kasten ueber ihm liegt. */
#d24-ai:has(#d24-ai-box:not([hidden])){bottom:20px;}
@media (max-width:640px){#d24-ai{bottom:76px;inset-inline-end:16px;}}
</style>

<script>
(function () {
  var wurzel = document.getElementById('d24-ai');
  var knopf = document.getElementById('d24-ai-open');
  var kasten = document.getElementById('d24-ai-box');
  var log = document.getElementById('d24-ai-log');
  var form = document.getElementById('d24-ai-form');
  var feld = document.getElementById('d24-ai-input');
  var senden = form.querySelector('button');
  var laeuft = false;

  var texte = @json([
    'fehler' => $ar
      ? 'عذراً، حدث خطأ. يرجى استخدام نموذج الاتصال.'
      : 'Es ist ein Fehler aufgetreten. Bitte nutzen Sie das Kontaktformular.',
    'denkt' => $ar ? 'يكتب…' : 'schreibt …',
  ]);

  // Der Assistent erscheint nur, wenn der Betreiber ihn eingeschaltet hat.
  fetch('/api/website-assistent/status', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.verfuegbar) { wurzel.hidden = false; } })
    .catch(function () { /* Assistent bleibt aus - die Seite funktioniert weiter. */ });

  function blase(text, art) {
    var p = document.createElement('p');
    p.className = art;
    p.textContent = text;
    log.appendChild(p);
    log.scrollTop = log.scrollHeight;
    return p;
  }

  function oeffnen(auf) {
    kasten.hidden = !auf;
    knopf.hidden = auf;
    knopf.setAttribute('aria-expanded', auf ? 'true' : 'false');
    if (auf) { feld.focus(); }
  }

  knopf.addEventListener('click', function () {
    oeffnen(true);

    // Frueheren Verlauf derselben Sitzung nachladen (Seitenwechsel).
    fetch('/api/website-assistent/verlauf', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.verlauf || !d.verlauf.length) { return; }
        log.innerHTML = '';
        d.verlauf.forEach(function (e) {
          blase(e.text, e.rolle === 'ai' ? 'ai' : 'me');
        });
      })
      .catch(function () {});
  }, { once: true });

  document.getElementById('d24-ai-close').addEventListener('click', function () { oeffnen(false); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = feld.value.trim();
    if (!text || laeuft) { return; }

    laeuft = true;
    senden.disabled = true;
    blase(text, 'me');
    feld.value = '';
    var warten = blase(texte.denkt, 'ai');

    fetch('/api/website-assistent', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
      },
      body: JSON.stringify({ nachricht: text })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        warten.remove();
        blase((d && d.antwort) ? d.antwort : texte.fehler, (d && d.ok) ? 'ai' : 'err');
      })
      .catch(function () {
        warten.remove();
        blase(texte.fehler, 'err');
      })
      .finally(function () {
        laeuft = false;
        senden.disabled = false;
        feld.focus();
      });
  });
})();
</script>
