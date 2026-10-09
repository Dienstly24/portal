/*
 * Datumsfelder im deutschen Format TT.MM.JJJJ (09.10.2026).
 *
 * Befund: <input type="date"> zeigt das Datum im Format der BROWSER-
 * Sprache - ein englisch eingestellter Browser zeigt 06/10/2026, und ob
 * das der 6. Oktober oder der 10. Juni ist, sieht man ihm nicht an.
 *
 * Loesung ohne Umbau der rund 70 Datumsfelder: jedes <input type="date">
 * bekommt ein sichtbares Textfeld mit Maske davor. Das ORIGINAL bleibt
 * das Formularfeld - Name, id, ISO-Wert, alle vorhandenen Skripte
 * ("Heute"-Knoepfe, Ablauf-Berechnung) arbeiten unveraendert damit. Es
 * dient zusaetzlich als Kalender (Knopf 📅 -> showPicker()).
 *
 * Ohne JavaScript bleibt das native Feld stehen - nichts geht verloren.
 * Ausnahme pro Feld: data-datum-nativ.
 */
import { datumLesen, fehlerText, isoZuAnzeige, maskieren } from './datum-logik.js';

const WERT = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
const sprache = () => (document.documentElement.lang || 'de').slice(0, 2);
const istVergangenheit = (orig) => /birth|geburt/i.test(orig.name || orig.id || '') || orig.hasAttribute('data-datum-vergangenheit');

function originalSetzen(orig, iso) {
    if (WERT.get.call(orig) === iso) return;
    WERT.set.call(orig, iso);
    orig.dispatchEvent(new Event('input', { bubbles: true }));
    orig.dispatchEvent(new Event('change', { bubbles: true }));
}

function fehlerZeigen(wrap, text) {
    const anzeige = wrap.querySelector('.datum-anzeige');
    let f = wrap.parentNode.querySelector(':scope > .datum-fehler[data-fuer="' + wrap.dataset.datumId + '"]');
    if (!text) {
        anzeige.setCustomValidity('');
        anzeige.classList.remove('feld-hat-fehler');
        anzeige.removeAttribute('aria-invalid');
        if (f) f.remove();
        return;
    }
    anzeige.setCustomValidity(text);
    anzeige.classList.add('feld-hat-fehler');
    anzeige.setAttribute('aria-invalid', 'true');
    if (!f) {
        f = document.createElement('div');
        f.className = 'datum-fehler';
        f.setAttribute('role', 'alert');
        f.dataset.fuer = wrap.dataset.datumId;
        wrap.insertAdjacentElement('afterend', f);
    }
    f.textContent = text;
}

/** Prueft die Anzeige, uebertraegt sie ins Original. true = in Ordnung. */
function pruefen(wrap, { formatieren = true } = {}) {
    const orig = wrap.querySelector('input[type=date]');
    const anzeige = wrap.querySelector('.datum-anzeige');
    const ergebnis = datumLesen(anzeige.value, { vergangenheit: istVergangenheit(orig) });

    if (ergebnis.status === 'leer') {
        originalSetzen(orig, '');
        fehlerZeigen(wrap, null);
        return true;
    }
    if (ergebnis.status === 'ungueltig') {
        originalSetzen(orig, '');
        fehlerZeigen(wrap, fehlerText(ergebnis.grund, sprache()));
        return false;
    }
    const min = orig.getAttribute('min');
    const max = orig.getAttribute('max');
    if (min && ergebnis.iso < min) { fehlerZeigen(wrap, fehlerText('min', sprache(), isoZuAnzeige(min))); return false; }
    if (max && ergebnis.iso > max) { fehlerZeigen(wrap, fehlerText('max', sprache(), isoZuAnzeige(max))); return false; }

    if (formatieren) anzeige.value = ergebnis.anzeige;
    fehlerZeigen(wrap, null);
    originalSetzen(orig, ergebnis.iso);
    return true;
}

let zaehler = 0;

function ausstatten(orig) {
    if (orig.dataset.datumFertig || orig.hasAttribute('data-datum-nativ')) return;
    orig.dataset.datumFertig = '1';

    const wrap = document.createElement('span');
    wrap.className = 'datum';
    wrap.dataset.datumId = 'd' + (++zaehler);

    const anzeige = document.createElement('input');
    anzeige.type = 'text';
    anzeige.inputMode = 'numeric';
    anzeige.autocomplete = 'off';
    anzeige.maxLength = 10;
    anzeige.size = 11;
    anzeige.placeholder = 'TT.MM.JJJJ';
    anzeige.className = (orig.className ? orig.className + ' ' : '') + 'datum-anzeige';
    anzeige.style.cssText = orig.style.cssText;
    anzeige.setAttribute('aria-label', orig.getAttribute('aria-label') || orig.getAttribute('title') || 'Datum (TT.MM.JJJJ)');
    if (orig.title) anzeige.title = orig.title;
    anzeige.value = isoZuAnzeige(WERT.get.call(orig));

    const knopf = document.createElement('button');
    knopf.type = 'button';
    knopf.className = 'datum-kalender';
    knopf.tabIndex = -1;
    knopf.textContent = '📅';
    knopf.setAttribute('aria-label', sprache() === 'ar' ? 'فتح التقويم' : 'Kalender öffnen');

    // Breite: hatte das Original eine feste Breite, gilt sie fuer den
    // Umschlag; das sichtbare Feld fuellt ihn.
    if (orig.style.width) { wrap.style.width = orig.style.width; anzeige.style.width = '100%'; }
    if (orig.style.display === 'none') wrap.style.display = 'none';
    anzeige.style.display = '';

    orig.parentNode.insertBefore(wrap, orig);
    wrap.append(anzeige, orig, knopf);
    orig.tabIndex = -1;
    orig.setAttribute('aria-hidden', 'true');

    const spiegeln = () => {
        anzeige.disabled = orig.disabled;
        anzeige.readOnly = orig.readOnly;
        knopf.disabled = orig.disabled || orig.readOnly;
        if (orig.required) { anzeige.required = true; orig.required = false; orig.dataset.datumPflicht = '1'; }
        if (orig.style.display === 'none') wrap.style.display = 'none';
        else if (wrap.style.display === 'none') wrap.style.display = '';
    };
    spiegeln();
    new MutationObserver(spiegeln).observe(orig, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'style'] });

    // Skripte, die das Original setzen (Heute-Knopf, Ablauf-Automatik),
    // aktualisieren die Anzeige mit - ohne dass sie davon wissen muessen.
    Object.defineProperty(orig, 'value', {
        configurable: true,
        get() { return WERT.get.call(this); },
        set(v) {
            WERT.set.call(this, v);
            anzeige.value = isoZuAnzeige(v);
            fehlerZeigen(wrap, null);
        },
    });
    // Kalender (native Auswahl) -> Anzeige
    orig.addEventListener('change', () => {
        const iso = WERT.get.call(orig);
        if (iso && anzeige.value !== isoZuAnzeige(iso)) { anzeige.value = isoZuAnzeige(iso); fehlerZeigen(wrap, null); }
    });
    // Ein <label for="id"> zeigt aufs Original - Fokus weiterreichen.
    orig.addEventListener('focus', () => anzeige.focus());

    knopf.addEventListener('click', () => {
        try { orig.showPicker(); } catch (e) { anzeige.focus(); }
    });

    anzeige.addEventListener('input', (e) => {
        const loeschen = e.inputType && e.inputType.startsWith('delete');
        const amEnde = anzeige.selectionStart === anzeige.value.length;
        if (!loeschen && amEnde) anzeige.value = maskieren(anzeige.value);
        // Vollstaendige Eingabe sofort uebernehmen (abhaengige Berechnungen
        // wie der Ablauf laufen dann schon beim Tippen mit).
        if (anzeige.value.length === 10) pruefen(wrap);
        else if (anzeige.value === '') pruefen(wrap);
        else fehlerZeigen(wrap, null);
    });
    anzeige.addEventListener('paste', (e) => {
        const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
        const r = datumLesen(text, { vergangenheit: istVergangenheit(orig) });
        if (r.status === 'ok') {
            e.preventDefault();
            anzeige.value = r.anzeige;
            pruefen(wrap);
        }
    });
    anzeige.addEventListener('blur', () => pruefen(wrap));
    anzeige.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') pruefen(wrap);
        // Alt+Pfeil runter oeffnet den Kalender - wie beim nativen Feld.
        if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); knopf.click(); }
    });
}

export function datumsfelderAusstatten(wurzel = document) {
    wurzel.querySelectorAll('input[type=date]:not([data-datum-fertig]):not([data-datum-nativ])').forEach(ausstatten);
}

// Vor dem Absenden: alle Datumsfelder des Formulars pruefen. Ein
// ungueltiges Datum haelt das Formular an - mit der Meldung am Feld.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    let erstes = null;
    form.querySelectorAll('.datum').forEach((wrap) => {
        if (!pruefen(wrap) && !erstes) erstes = wrap.querySelector('.datum-anzeige');
    });
    if (erstes) {
        e.preventDefault();
        e.stopImmediatePropagation();
        erstes.focus();
        erstes.reportValidity();
    }
}, true);

function start() {
    datumsfelderAusstatten();
    // Nachtraeglich eingefuegte Felder (Schadenzeilen, Dialoge)
    new MutationObserver((liste) => {
        for (const m of liste) {
            m.addedNodes.forEach((n) => {
                if (n.nodeType !== 1) return;
                if (n.matches?.('input[type=date]')) ausstatten(n);
                else if (n.querySelectorAll) datumsfelderAusstatten(n);
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
