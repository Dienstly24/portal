/*
 * Markenfarben fuer Canvas-Diagramme (UX-1).
 *
 * WARUM DIESE DATEI: In CSS steht die Markenfarbe seit UX-1 an genau EINER
 * Stelle (resources/css/brand.css). Chart.js zeichnet aber auf ein
 * <canvas> - dort wird eine Farbe als Pixel gemalt, nicht als CSS-Wert
 * aufgeloest. `backgroundColor: 'var(--emerald)'` ergibt deshalb KEINE
 * gruene Flaeche, sondern gar keine. Die Diagramme brauchen einen
 * ausgerechneten Farbwert.
 *
 * Statt den Hex-Wert in jeder Diagramm-Datei erneut hinzuschreiben (genau
 * die Streuung, die UX-1 beseitigt hat), liest diese Funktion ihn zur
 * Laufzeit aus demselben CSS-Token. Ein Markenwechsel in brand.css wirkt
 * damit auch in den Diagrammen.
 *
 * Bewusst ein KLASSISCHES Skript (kein Modul): die Diagramm-Bloecke stehen
 * als eingebettete <script> in den Seiten und laufen sofort. Ein Modul
 * (wie ui.js) liefe spaeter - brandColor waere dann noch nicht definiert.
 */
(function () {
    var wurzel = document.documentElement;

    /**
     * @param {string} name  Token-Name ohne Praefix, z.B. 'emerald'.
     * @param {string} [ersatz] Farbe, falls das Token fehlt (z.B. weil das
     *        Stylesheet noch nicht geladen ist).
     * @returns {string} Ausgerechneter Farbwert, z.B. '#17A65B'.
     */
    window.brandColor = function (name, ersatz) {
        var wert = getComputedStyle(wurzel).getPropertyValue('--' + name);
        wert = (wert || '').trim();
        return wert !== '' ? wert : (ersatz || '#17A65B');
    };
})();
