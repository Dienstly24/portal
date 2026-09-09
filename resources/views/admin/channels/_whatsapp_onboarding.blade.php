{{--
  Der OFFIZIELLE Weg, eine WhatsApp-Nummer anzubinden (Auftrag 17/18/20).

  Bewusst KEIN Formular mit Token-Feld als Hauptweg: der Betreiber soll
  keinen Zugangsschluessel abtippen oder durch einen Chat schicken
  muessen. Meta oeffnet ein eigenes Fenster, wir bekommen einen
  kurzlebigen Code, und der SERVER tauscht ihn.

  Die Felder weiter unten (Token von Hand) bleiben als Rueckfallebene
  bestehen - fuer den Fall, dass Embedded Signup nicht eingerichtet ist.
--}}
<div class="card" style="padding:14px;margin-bottom:14px;background:var(--surface-2);">
  <strong style="font-size:14px;">WhatsApp Business verbinden</strong>

  @if(! $signupReady)
    <p style="font-size:13px;color:var(--text-muted);margin:6px 0 0;">
      Der offizielle Anmeldeweg ist auf dem Server noch nicht eingerichtet.
      Dafür fehlen <code>META_APP_ID</code>, <code>META_APP_SECRET</code> und
      <code>META_ES_CONFIG_ID</code> in der Server-Konfiguration.
      Solange können Zugangsdaten nur von Hand hinterlegt werden.
    </p>
  @else
    <p style="font-size:13px;color:var(--text-muted);margin:6px 0 10px;">
      Sie melden sich in einem Fenster von Meta an und wählen dort Unternehmen und
      Nummer. Es wird <strong>kein Zugangsschlüssel</strong> in dieses Formular
      eingetragen und keiner angezeigt — den Austausch übernimmt der Server.
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-primary" data-h-click="waStart">
        WhatsApp Business verbinden
      </button>
      <button type="button" class="btn" data-h-click="waStartCoex">
        Bestehende WhatsApp Business App verbinden (Coexistence)
      </button>
    </div>

    <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0;">
      <strong>Zwei verschiedene Wege.</strong> Der erste bindet die Nummer allein an die
      Cloud API. Der zweite ist der Coexistence-Weg: die Nummer bleibt zusätzlich in der
      WhatsApp Business App auf dem Telefon nutzbar. Meta gibt beides getrennt frei —
      eine funktionierende Cloud-API-Verbindung ist <strong>kein</strong> Nachweis für
      Coexistence.
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
