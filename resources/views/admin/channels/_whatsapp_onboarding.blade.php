{{--
  Der OFFIZIELLE Weg, eine WhatsApp-Nummer anzubinden (Auftrag 17/18/20).

  Bewusst KEIN Formular mit Token-Feld als Hauptweg: der Betreiber soll
  keinen Zugangsschluessel abtippen oder durch einen Chat schicken
  muessen. Meta oeffnet ein eigenes Fenster, wir bekommen einen
  kurzlebigen Code, und der SERVER tauscht ihn.

  Die Felder weiter unten (Token von Hand) bleiben als Rueckfallebene
  bestehen - fuer den Fall, dass Embedded Signup nicht eingerichtet ist.
--}}
<div class="wa-onboarding">
  <div class="wa-onboarding-kopf">
    <span aria-hidden="true" style="font-size:18px;">💬</span>
    <strong>WhatsApp Business verbinden</strong>
  </div>

  @if(! $signupReady)
    <div class="alert-info" style="margin:0;">
      Der offizielle Anmeldeweg ist auf dem Server noch nicht eingerichtet.
      Dafür fehlen <code>META_APP_ID</code>, <code>META_APP_SECRET</code> und
      <code>META_ES_CONFIG_ID</code> in der Server-Konfiguration.
      Solange können Zugangsdaten nur von Hand hinterlegt werden.
    </div>
  @else
    <p class="wa-onboarding-text">
      Sie melden sich in einem Fenster von Meta an und wählen dort Unternehmen und
      Nummer. Es wird <strong>kein Zugangsschlüssel</strong> in dieses Formular
      eingetragen und keiner angezeigt — den Austausch übernimmt der Server.
    </p>

    {{--
      DIE REIHENFOLGE IST ABSICHT. Coexistence steht zuerst und traegt den
      Hauptknopf: es ist der Weg, den ein bestehender Betrieb braucht.
      Der Cloud-API-Weg REGISTRIERT die Nummer und beendet damit die
      WhatsApp Business App auf dem Telefon - er darf nicht so aussehen
      wie die naheliegende Wahl, sonst klickt ihn jemand versehentlich.
    --}}
    <div class="wa-weg wa-weg-empfohlen">
      <div class="wa-weg-kopf">
        <strong>Bestehende Nummer behalten</strong>
        <span class="badge badge-active">Empfohlen</span>
      </div>
      <p class="wa-weg-text">
        Coexistence: die Nummer bleibt zusätzlich in der WhatsApp Business App
        auf dem Telefon nutzbar. Chats und Kontakte gehen nicht verloren.
      </p>
      <button type="button" class="btn btn-emerald" data-h-click="waStartCoex">
        Bestehende WhatsApp Business App verbinden
      </button>
    </div>

    <details class="wa-weg">
      <summary>Andere Möglichkeit: neue Nummer allein an die Cloud API binden</summary>
      <div class="alert alert-warning" style="margin:12px 0;">
        <strong>Nur für eine Nummer ohne WhatsApp.</strong> Dieser Weg
        <strong>registriert</strong> die Nummer bei der Cloud API. Läuft auf ihr
        die WhatsApp Business App, hört diese danach auf zu funktionieren, und der
        Weg zurück ist aufwendig.
      </div>
      <button type="button" class="btn btn-ghost" data-h-click="waStart">
        Neue Nummer an die Cloud API binden
      </button>
    </details>

    <p class="wa-onboarding-fuss">
      Meta gibt beide Wege <strong>getrennt</strong> frei — eine funktionierende
      Cloud-API-Verbindung ist <strong>kein</strong> Nachweis für Coexistence.
    </p>

    <form method="POST" action="{{ route('admin.channels.whatsapp.complete') }}" id="waFinish" hidden>
      @csrf
      <input type="hidden" name="code" id="waCode">
      <input type="hidden" name="waba_id" id="waWaba">
      <input type="hidden" name="phone_number_id" id="waPhone">
      <input type="hidden" name="coexistence" id="waCoex" value="0">
    </form>
  @endif
</div>

@push('styles')
<style @cspNonce>
    .wa-onboarding {
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 14px;
        background: var(--surface-soft);
    }
    .wa-onboarding-kopf { display: flex; align-items: center; gap: 8px; font-size: 14px; margin-bottom: 8px; }
    .wa-onboarding-text { color: var(--ink-soft); font-size: 13px; margin: 0 0 14px; line-height: 1.55; }
    .wa-onboarding-fuss { color: var(--ink-soft); font-size: 12px; margin: 12px 0 0; line-height: 1.5; }
    .wa-weg { border: 1px solid var(--line); border-radius: 8px; padding: 14px; background: var(--surface); }
    .wa-weg + .wa-weg, details.wa-weg { margin-top: 10px; }
    .wa-weg-empfohlen { border-color: var(--emerald); }
    .wa-weg-kopf { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
    .wa-weg-text { color: var(--ink-soft); font-size: 12.5px; margin: 0 0 12px; line-height: 1.5; }
    details.wa-weg > summary {
        cursor: pointer; font-size: 13px; color: var(--ink-soft);
        list-style: none; font-weight: 500;
    }
    details.wa-weg > summary::-webkit-details-marker { display: none; }
    details.wa-weg > summary:hover { color: var(--ink); }
</style>
@endpush

@if($signupReady)
  @pushOnce('cspScripts')
    <script @cspNonce>
      window.__h = window.__h || {};

      // Zwei Einstiege, EIN Ablauf - der Unterschied ist die Auswahl im
      // Meta-Fenster (featureType). Sie wird mitgeschickt, damit der
      // Server die Anbindungsart nicht raten muss.
      const waConfig = @json($signupConfig);
      let waCoexistence = false;

      const waLaunch = (coexistence) => {
        waCoexistence = coexistence;
        document.getElementById('waCoex').value = coexistence ? '1' : '0';

        if (typeof FB === 'undefined') {
          window.alert('Das Anmeldefenster von Meta konnte nicht geladen werden. '
            + 'Bitte Seite neu laden oder Zugangsdaten von Hand hinterlegen.');
          return;
        }

        FB.login(function () {
          // Absichtlich leer: das Ergebnis kommt NICHT hier an, sondern
          // als Nachricht des Meta-Fensters (siehe unten). Der Rueckruf
          // von FB.login liefert bei diesem Verfahren keinen Code.
        }, {
          config_id: waConfig.configId,
          response_type: 'code',
          override_default_response_type: true,
          extras: coexistence ? { featureType: 'whatsapp_business_app_onboarding' } : {},
        });
      };

      window.__h.waStart = () => waLaunch(false);
      window.__h.waStartCoex = () => waLaunch(true);

      // Meta meldet Kennungen und Code ueber postMessage. Fremde
      // Absender werden ignoriert - sonst koennte jede eingebettete
      // Seite eine Anbindung auf unser Konto auslösen.
      window.addEventListener('message', (event) => {
        if (! String(event.origin || '').endsWith('facebook.com')) { return; }
        let daten;
        try { daten = JSON.parse(event.data); } catch (e) { return; }
        if (! daten || daten.type !== 'WA_EMBEDDED_SIGNUP') { return; }

        if (daten.event === 'FINISH' || daten.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
          document.getElementById('waWaba').value = daten.data.waba_id || '';
          document.getElementById('waPhone').value = daten.data.phone_number_id || '';
          // Der Business-App-Abschluss ist der Coexistence-Weg - das ist
          // die verlaesslichere Angabe als der geklickte Knopf.
          if (daten.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
            document.getElementById('waCoex').value = '1';
          }
        }
      });

      // Der Code kommt ueber den Rueckruf der Anmeldung.
      window.__h.waSubmit = () => document.getElementById('waFinish').submit();
    </script>
    <script async defer crossorigin="anonymous"
            src="https://connect.facebook.net/de_DE/sdk.js" @cspNonce></script>
    <script @cspNonce>
      window.fbAsyncInit = function () {
        FB.init({
          appId: @json($signupConfig['appId']),
          autoLogAppEvents: true,
          xfbml: false,
          version: @json($signupConfig['graphVersion']),
        });
      };
    </script>
  @endPushOnce
@endif
