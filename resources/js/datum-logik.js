/*
 * Datum TT.MM.JJJJ - reine Logik ohne DOM (09.10.2026).
 *
 * Getrennt von datum.js, damit sie ohne Browser pruefbar ist
 * (tests/js/datum-logik.test.js, `npm run test:js`). Gespeichert wird
 * WEITERHIN ISO (JJJJ-MM-TT) - nur Anzeige und Eingabe sind deutsch.
 */

const MAX_TEILE = [2, 2, 4];

/** ISO "2026-10-09" -> "09.10.2026"; alles andere -> "". */
export function isoZuAnzeige(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || '').trim());
    return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
}

/** Gibt es diesen Tag wirklich? (kein 31.02., kein 29.02. ausserhalb Schaltjahr) */
export function istEchterTag(jahr, monat, tag) {
    if (monat < 1 || monat > 12 || tag < 1 || tag > 31) return false;
    const d = new Date(Date.UTC(jahr, monat - 1, tag));
    return d.getUTCFullYear() === jahr && d.getUTCMonth() === monat - 1 && d.getUTCDate() === tag;
}

/**
 * Zweistelliges Jahr ergaenzen. Standard: bis 20 Jahre in die Zukunft
 * gilt 20xx, sonst 19xx ("98" -> 1998, "30" -> 2030). Bei
 * `vergangenheit` (Geburtsdatum) gilt alles nach dem laufenden Jahr als
 * 19xx ("26" -> 2026, "27" -> 1927).
 */
export function jahrErgaenzen(yy, heute = new Date(), vergangenheit = false) {
    const jetzt = heute.getFullYear();
    const grenze = (jetzt % 100) + (vergangenheit ? 0 : 20);
    const jahrhundert = Math.floor(jetzt / 100) * 100;
    return yy <= grenze ? jahrhundert + yy : jahrhundert - 100 + yy;
}

/**
 * Tolerant lesen: "01.01.1998", "1.1.1998", "01011998", "010198",
 * "01/01/1998", "1-1-98", "1998-01-01", "1.1.98".
 *
 * @returns {{status:'leer'}|{status:'ok', iso:string, anzeige:string}|{status:'ungueltig', grund:string}}
 */
export function datumLesen(text, { heute = new Date(), vergangenheit = false } = {}) {
    const s = String(text ?? '').trim();
    if (s === '') return { status: 'leer' };

    let tag, monat, jahr, jahrText;
    let m;
    if ((m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s))) {
        [jahrText, monat, tag] = [m[1], +m[2], +m[3]];
    } else if ((m = /^(\d{1,2})[.\/\-\s](\d{1,2})[.\/\-\s](\d{2}|\d{4})\.?$/.exec(s))) {
        [tag, monat, jahrText] = [+m[1], +m[2], m[3]];
    } else if ((m = /^(\d{2})(\d{2})(\d{4}|\d{2})$/.exec(s))) {
        [tag, monat, jahrText] = [+m[1], +m[2], m[3]];
    } else {
        return { status: 'ungueltig', grund: 'format' };
    }

    jahr = jahrText.length === 2 ? jahrErgaenzen(+jahrText, heute, vergangenheit) : +jahrText;
    if (jahr < 1800 || jahr > 2199) return { status: 'ungueltig', grund: 'jahr' };
    if (!istEchterTag(jahr, monat, tag)) return { status: 'ungueltig', grund: 'tag' };

    const iso = `${String(jahr).padStart(4, '0')}-${String(monat).padStart(2, '0')}-${String(tag).padStart(2, '0')}`;
    return { status: 'ok', iso, anzeige: isoZuAnzeige(iso) };
}

/**
 * Eingabemaske beim Tippen: nach zwei Ziffern fuer den Tag kommt der
 * Punkt von selbst, ebenso nach dem Monat. "1." wird zu "01.".
 * Nur fuer TIPPEN gedacht - beim Loeschen ruft datum.js die Maske nicht
 * auf, sonst liesse sich ein Punkt nie entfernen.
 */
export function maskieren(eingabe) {
    const roh = String(eingabe ?? '').replace(/[\/\-\s,]/g, '.').replace(/[^\d.]/g, '');
    const teile = [''];
    for (const zeichen of roh) {
        const i = teile.length - 1;
        if (zeichen === '.') {
            if (i < 2 && teile[i] !== '') {
                if (teile[i].length === 1) teile[i] = '0' + teile[i];
                teile.push('');
            }
            continue;
        }
        if (teile[i].length < MAX_TEILE[i]) {
            teile[i] += zeichen;
        } else if (i < 2) {
            teile.push(zeichen);
        }
    }
    const i = teile.length - 1;
    if (i < 2 && teile[i].length === MAX_TEILE[i]) teile.push('');
    return teile.join('.');
}

/** Verstaendliche Meldung (Deutsch, im arabischen Portal Arabisch). */
export function fehlerText(grund, sprache = 'de', grenze = null) {
    const ar = sprache === 'ar';
    switch (grund) {
        case 'tag': return ar ? 'هذا التاريخ غير موجود (مثلاً 31.02.).' : 'Dieses Datum gibt es nicht (z. B. 31.02.). Bitte prüfen.';
        case 'jahr': return ar ? 'السنة غير صحيحة.' : 'Das Jahr ist nicht plausibel. Bitte vierstellig eingeben, z. B. 1998.';
        case 'min': return ar ? `التاريخ يجب ألا يكون قبل ${grenze}.` : `Das Datum darf nicht vor dem ${grenze} liegen.`;
        case 'max': return ar ? `التاريخ يجب ألا يكون بعد ${grenze}.` : `Das Datum darf nicht nach dem ${grenze} liegen.`;
        default: return ar ? 'يرجى إدخال التاريخ بالشكل TT.MM.JJJJ.' : 'Bitte das Datum als TT.MM.JJJJ eingeben, z. B. 01.01.1998.';
    }
}
