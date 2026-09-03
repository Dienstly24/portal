/*
 * Einstiegspunkt der Oberflaeche.
 *
 * Alpine.js ist mit Audit SEC-4 entfallen: es wertet seine Direktiven mit
 * Function() aus und verlangt dafuer 'unsafe-eval' in der
 * Content-Security-Policy - eine Ausnahme, die jede Auswertung von
 * Zeichenketten als Code erlaubt. Genutzt wurde es an zwei Stellen; die
 * uebernimmt jetzt ui.js mit gewoehnlichen Ereignis-Listenern.
 */

import './ui';
