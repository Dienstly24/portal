/*
 * Kleine Oberflaechen-Bausteine ohne Framework (Audit SEC-4).
 *
 * Ersetzt Alpine.js. Alpine wertet seine Direktiven (x-data, @click, ...)
 * zur Laufzeit mit Function() aus und braucht dafuer 'unsafe-eval' in der
 * Content-Security-Policy. 'unsafe-eval' erlaubt aber JEDE Auswertung von
 * Zeichenketten als Code - also genau den Schritt, den ein XSS-Angriff
 * braucht. Die Anwendung nutzte Alpine an ZWEI Stellen (Zeilenmenue der
 * Kunden- und der Ticketliste, Sammelauswahl der Tickets); dafuer eine
 * so weitreichende Ausnahme offenzulassen, war das schlechtere Geschaeft.
 *
 * Alles hier laeuft ueber Ereignis-Delegation an data-Attributen. Der
 * Vorteil neben der CSP: nachtraeglich eingefuegte Zeilen (Suche, Paginierung)
 * funktionieren ohne erneutes Verdrahten.
 */

// ---------------------------------------------------------------------
// 1) Zeilenmenue ("•••")
//
//    <div data-menu>
//      <button data-menu-trigger>•••</button>
//      <div data-menu-panel hidden>…</div>
//    </div>
// ---------------------------------------------------------------------
function alleMenuesSchliessen(ausser) {
    document.querySelectorAll('[data-menu]').forEach((menu) => {
        if (menu === ausser) return;
        const panel = menu.querySelector('[data-menu-panel]');
        const trigger = menu.querySelector('[data-menu-trigger]');
        if (panel) panel.hidden = true;
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
}

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-menu-trigger]');

    if (trigger) {
        const menu = trigger.closest('[data-menu]');
        const panel = menu && menu.querySelector('[data-menu-panel]');
        if (!panel) return;

        event.preventDefault();
        // Erst die anderen schliessen: zwei offene Menues uebereinander
        // waren auch mit Alpine schon unschoen.
        alleMenuesSchliessen(menu);
        panel.hidden = !panel.hidden;
        trigger.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        return;
    }

    // Klick ausserhalb: alles zu. Ein Klick INNERHALB des Panels schliesst
    // ebenfalls (dort stehen nur Links und Formulare, die die Seite
    // ohnehin verlassen) - das entspricht dem bisherigen Verhalten.
    alleMenuesSchliessen(null);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') alleMenuesSchliessen(null);
});

// ---------------------------------------------------------------------
// 2) Sammelauswahl (Ticketliste)
//
//    <div data-bulk>
//      <input type="checkbox" data-bulk-all>
//      <input type="checkbox" data-bulk-item value="7">
//      <form data-bulk-form> … <span data-bulk-count></span> </form>
//    </div>
// ---------------------------------------------------------------------
function bulkAktualisieren(wurzel) {
    const items = [...wurzel.querySelectorAll('[data-bulk-item]')];
    const gewaehlt = items.filter((el) => el.checked);
    const form = wurzel.querySelector('[data-bulk-form]');
    if (!form) return;

    form.hidden = gewaehlt.length === 0;

    const zaehler = wurzel.querySelector('[data-bulk-count]');
    if (zaehler) zaehler.textContent = String(gewaehlt.length);

    // Die IDs wandern als versteckte Felder ins Formular - dieselbe
    // Datenform wie zuvor (name="ids[]"), damit der Controller
    // unveraendert bleibt.
    form.querySelectorAll('[data-bulk-id]').forEach((el) => el.remove());
    gewaehlt.forEach((el) => {
        const feld = document.createElement('input');
        feld.type = 'hidden';
        feld.name = 'ids[]';
        feld.value = el.value;
        feld.setAttribute('data-bulk-id', '');
        form.appendChild(feld);
    });

    const alle = wurzel.querySelector('[data-bulk-all]');
    if (alle) {
        alle.checked = items.length > 0 && gewaehlt.length === items.length;
        alle.indeterminate = gewaehlt.length > 0 && gewaehlt.length < items.length;
    }
}

function bulkAusfuehren(wurzel, aktion) {
    const form = wurzel.querySelector('[data-bulk-form]');
    if (!form) return;

    const anzahl = wurzel.querySelectorAll('[data-bulk-item]:checked').length;
    if (anzahl === 0) return;

    if (aktion === 'delete'
        && !confirm(anzahl + ' Ticket(s) in den Papierkorb verschieben?')) {
        return;
    }

    const feld = form.querySelector('[data-bulk-action]');
    if (feld) feld.value = aktion;
    form.submit();
}

document.addEventListener('change', (event) => {
    const wurzel = event.target.closest('[data-bulk]');
    if (!wurzel) return;

    if (event.target.matches('[data-bulk-all]')) {
        wurzel.querySelectorAll('[data-bulk-item]').forEach((el) => {
            el.checked = event.target.checked;
        });
    }

    if (event.target.matches('[data-bulk-item], [data-bulk-all]')) {
        bulkAktualisieren(wurzel);
        return;
    }

    // Auswahlfeld mit hinterlegter Sammelaktion (Status, Zuweisung, …)
    const auswahl = event.target.closest('[data-bulk-select]');
    if (auswahl && auswahl.value) {
        bulkAusfuehren(wurzel, auswahl.getAttribute('data-bulk-select'));
    }
});

document.addEventListener('click', (event) => {
    const knopf = event.target.closest('[data-bulk-do]');
    if (knopf) {
        const wurzel = knopf.closest('[data-bulk]');
        if (wurzel) {
            event.preventDefault();
            bulkAusfuehren(wurzel, knopf.getAttribute('data-bulk-do'));
        }
        return;
    }

    const leeren = event.target.closest('[data-bulk-clear]');
    if (leeren) {
        const wurzel = leeren.closest('[data-bulk]');
        if (wurzel) {
            event.preventDefault();
            wurzel.querySelectorAll('[data-bulk-item]').forEach((el) => {
                el.checked = false;
            });
            bulkAktualisieren(wurzel);
        }
    }
});

// ---------------------------------------------------------------------
// 3) Rueckfrage vor dem Absenden / vor einem Klick
//
//    <form data-confirm="Wirklich loeschen?">
//    <form data-confirm="Erste Frage" data-confirm-2="Zweite Frage">
//
//    Ersetzt onsubmit="return confirm('…')". Der Text darf aus Blade
//    kommen - er steht als Attribut da und wird nie als Code gelesen.
// ---------------------------------------------------------------------
function rueckfrageBestanden(el) {
    const erste = el.getAttribute('data-confirm');
    if (erste && !confirm(erste)) return false;

    const zweite = el.getAttribute('data-confirm-2');
    if (zweite && !confirm(zweite)) return false;

    return true;
}

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-confirm]');
    if (form && !rueckfrageBestanden(form)) event.preventDefault();
}, true);

document.addEventListener('click', (event) => {
    // Nur Elemente, die selbst eine Rueckfrage tragen und KEIN
    // Formular sind (dort haengt sie am submit).
    const el = event.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') return;
    if (el.closest('form') && el.type === 'submit') return;

    if (!rueckfrageBestanden(el)) {
        event.preventDefault();
        event.stopPropagation();
    }
});

// ---------------------------------------------------------------------
// 4) Zeilennavigation
//
//    <tr data-row-nav="/admin/kunden/7">
//
//    Ein Klick auf die Zeile oeffnet die Adresse - ausser in Zellen der
//    Klasse .noNav und auf Bedienelementen (Links, Knoepfe, Felder), die
//    ihre eigene Aufgabe haben. Mittelklick/Strg+Klick oeffnen in einem
//    neuen Tab, wie bei einem echten Link.
// ---------------------------------------------------------------------
document.addEventListener('click', (event) => {
    const zeile = event.target.closest('[data-row-nav]');
    if (!zeile) return;

    if (event.target.closest('.noNav')) return;
    if (event.target.closest('a, button, input, select, textarea, label, [data-menu]')) return;
    // Textauswahl nicht als Klick werten.
    if (window.getSelection && String(window.getSelection()).length > 0) return;

    const ziel = zeile.getAttribute('data-row-nav');
    if (!ziel) return;

    if (event.ctrlKey || event.metaKey || event.button === 1) {
        window.open(ziel, '_blank', 'noopener');
    } else {
        window.location.href = ziel;
    }
});

// ---------------------------------------------------------------------
// 5) Verdrahtung ehemaliger Inline-Handler (Audit SEC-4)
//
//    Aus  <button onclick="tuWas()">
//    wird <button data-h-click="a1b2">
//    plus eine Registrierung im selben Blade-Template:
//         window.__h["a1b2"] = function (event) { tuWas() };
//
//    Warum ueberhaupt: ein onclick-Attribut kann keinen CSP-Nonce
//    tragen. Solange die Seite Attribut-Handler benutzt, braucht die
//    Richtlinie 'unsafe-inline' im script-src - und dann erlaubt sie
//    auch jedes eingeschleuste Skript. Die Registrierung dagegen steht
//    in einem <script nonce="…">, das der Browser eindeutig als
//    unseres erkennt.
//
//    Die Zuordnung laeuft ueber Ereignis-Delegation am document, nicht
//    ueber addEventListener je Element: so funktionieren auch Zeilen,
//    die erst spaeter per JavaScript eingefuegt werden.
// ---------------------------------------------------------------------
window.__h = window.__h || {};

/*
 * Diese Ereignisse werden delegiert. focus/blur steigen nicht auf und
 * werden deshalb in der Capture-Phase abgefangen.
 */
const DELEGIERT = [
    'click', 'change', 'input', 'submit', 'keydown', 'keyup', 'keypress',
    'mouseover', 'mouseout', 'mouseenter', 'mouseleave', 'dblclick',
    'contextmenu', 'paste', 'drop', 'dragover', 'dragleave', 'dragenter',
    'wheel', 'reset', 'select', 'search', 'toggle',
];

const CAPTURE = ['focus', 'blur', 'error', 'load'];

function ausfuehren(event, phase) {
    const attribut = 'data-h-' + event.type;
    const el = event.target.closest
        ? event.target.closest('[' + attribut + ']')
        : null;
    if (!el) return;

    const fn = window.__h[el.getAttribute(attribut)];
    if (typeof fn !== 'function') return;

    // Semantik des alten Attribut-Handlers exakt nachbilden:
    //  - `this` ist das Element
    //  - `event` ist verfuegbar
    //  - ein Rueckgabewert `false` verhindert die Standardaktion
    //    (genau so wirkte `onsubmit="return confirm(...)"`)
    if (fn.call(el, event) === false) {
        event.preventDefault();
    }
}

DELEGIERT.forEach((typ) => {
    document.addEventListener(typ, (event) => ausfuehren(event), false);
});

CAPTURE.forEach((typ) => {
    document.addEventListener(typ, (event) => ausfuehren(event), true);
});

// ---------------------------------------------------------------------
// 6) Kleine Standardgesten (Audit SEC-4)
//
//    Wiederkehrende Ein-Zeiler, die frueher als onclick-Attribut mit
//    eingebautem Blade-Ausdruck dastanden. Als data-Attribut ist der
//    veraenderliche Teil ein WERT und nie Code.
//
//    data-toggle="id"      Bereich ein-/ausblenden
//    data-show="id"        Bereich einblenden
//    data-hide="id"        Bereich ausblenden
//    data-fill-target="id" + data-fill-value="…"   Feld befuellen
// ---------------------------------------------------------------------
document.addEventListener('click', (event) => {
    const umschalten = event.target.closest('[data-toggle]');
    if (umschalten) {
        const ziel = document.getElementById(umschalten.getAttribute('data-toggle'));
        if (ziel) {
            event.preventDefault();
            ziel.style.display = ziel.style.display === 'none' ? 'block' : 'none';
        }
        return;
    }

    const zeigen = event.target.closest('[data-show]');
    if (zeigen) {
        const ziel = document.getElementById(zeigen.getAttribute('data-show'));
        if (ziel) {
            event.preventDefault();
            ziel.style.display = 'block';
        }
        return;
    }

    const verbergen = event.target.closest('[data-hide]');
    if (verbergen) {
        const ziel = document.getElementById(verbergen.getAttribute('data-hide'));
        if (ziel) {
            event.preventDefault();
            ziel.style.display = 'none';
        }
        return;
    }

    const fuellen = event.target.closest('[data-fill-target]');
    if (fuellen) {
        const ziel = document.getElementById(fuellen.getAttribute('data-fill-target'));
        if (ziel) {
            event.preventDefault();
            ziel.value = fuellen.getAttribute('data-fill-value') || '';
            ziel.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
});
