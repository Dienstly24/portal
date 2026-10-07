/*
 * Zeichenflaeche fuer "Meine Unterschrift" (Teil B, 07.10.2026).
 *
 * Finger, Apple Pencil/Stift und Maus ueber Pointer Events. Glatte Linien
 * (quadratische Kurven ueber den Mittelpunkten), Druckstaerke wenn das Geraet
 * sie liefert, "Rueckgaengig" je Strich, "Loeschen". Die Flaeche wird in
 * Geraetepixeln gezeichnet (devicePixelRatio) - auf einem Retina-Bildschirm
 * waere sie sonst unscharf. Der Server rendert das Ergebnis ohnehin neu.
 *
 * Bewusst eine eigene Datei aus /js (script-src 'self'): sie wird auf drei
 * Seiten gebraucht (Profil, Editor, Handy-Seite), eine Kopie je Seite liefe
 * auseinander.
 *
 *   var pad = window.UnterschriftPad(canvasElement, { onChange: fn });
 *   pad.leer() / pad.rueckgaengig() / pad.loeschen() / pad.dataUrl()
 */
(function () {
    'use strict';

    window.UnterschriftPad = function (canvas, optionen) {
        optionen = optionen || {};
        var ctx = canvas.getContext('2d');
        var striche = [];
        var aktuell = null;
        var verhaeltnis = 1;

        function groesse() {
            var rect = canvas.getBoundingClientRect();
            verhaeltnis = Math.max(1, Math.min(3, window.devicePixelRatio || 1));
            canvas.width = Math.max(1, Math.round(rect.width * verhaeltnis));
            canvas.height = Math.max(1, Math.round(rect.height * verhaeltnis));
            neuZeichnen();
        }

        function punkt(event) {
            var rect = canvas.getBoundingClientRect();
            var druck = event.pressure && event.pointerType !== 'mouse' ? event.pressure : 0.5;
            return {
                x: (event.clientX - rect.left) / Math.max(1, rect.width),
                y: (event.clientY - rect.top) / Math.max(1, rect.height),
                p: druck
            };
        }

        function breite(p) {
            var basis = Math.max(1.6, canvas.width / 260);
            return basis * (0.6 + p * 1.1);
        }

        function strich(punkte) {
            if (punkte.length === 0) {
                return;
            }
            var w = canvas.width;
            var h = canvas.height;
            ctx.strokeStyle = '#10204a';
            ctx.fillStyle = '#10204a';
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            if (punkte.length === 1) {
                ctx.beginPath();
                ctx.arc(punkte[0].x * w, punkte[0].y * h, breite(punkte[0].p) / 2, 0, Math.PI * 2);
                ctx.fill();
                return;
            }
            for (var i = 1; i < punkte.length; i++) {
                var a = punkte[i - 1];
                var b = punkte[i];
                var vor = i > 1 ? punkte[i - 2] : a;
                ctx.lineWidth = breite((a.p + b.p) / 2);
                ctx.beginPath();
                ctx.moveTo(((vor.x + a.x) / 2) * w, ((vor.y + a.y) / 2) * h);
                ctx.quadraticCurveTo(a.x * w, a.y * h, ((a.x + b.x) / 2) * w, ((a.y + b.y) / 2) * h);
                ctx.stroke();
            }
        }

        function neuZeichnen() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            striche.forEach(strich);
        }

        function gemeldet() {
            if (typeof optionen.onChange === 'function') {
                optionen.onChange(api);
            }
        }

        canvas.style.touchAction = 'none';
        canvas.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            canvas.setPointerCapture(event.pointerId);
            aktuell = [punkt(event)];
            striche.push(aktuell);
            neuZeichnen();
        });
        canvas.addEventListener('pointermove', function (event) {
            if (aktuell === null) {
                return;
            }
            event.preventDefault();
            var liste = event.getCoalescedEvents ? event.getCoalescedEvents() : [event];
            liste.forEach(function (e) { aktuell.push(punkt(e)); });
            neuZeichnen();
        });
        ['pointerup', 'pointercancel'].forEach(function (typ) {
            canvas.addEventListener(typ, function () {
                if (aktuell !== null) {
                    aktuell = null;
                    gemeldet();
                }
            });
        });
        window.addEventListener('resize', groesse);

        var api = {
            leer: function () { return striche.length === 0; },
            rueckgaengig: function () { striche.pop(); neuZeichnen(); gemeldet(); },
            loeschen: function () { striche = []; neuZeichnen(); gemeldet(); },
            dataUrl: function () { return striche.length === 0 ? null : canvas.toDataURL('image/png'); },
            groesse: groesse
        };
        groesse();
        return api;
    };
})();
