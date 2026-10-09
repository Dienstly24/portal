{{-- Fehler am Feld (09.10.2026): die Meldungen des Servers als JSON fuer
     resources/js/ui.js (Abschnitt 8), das sie dem betroffenen Feld
     zuordnet. type="application/json" wird nie ausgefuehrt; @json
     maskiert <, >, & und Anfuehrungszeichen. --}}
@if(isset($errors) && $errors->any())
<script type="application/json" id="d24-feldfehler" @cspNonce>@json($errors->getMessages())</script>
@endif
