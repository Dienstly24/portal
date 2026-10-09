// Pruefung der Datumslogik ohne Browser: `npm run test:js`
// (node --test). Die Logik ist bewusst DOM-frei (resources/js/datum-logik.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { datumLesen, maskieren, isoZuAnzeige, jahrErgaenzen, istEchterTag } from '../../resources/js/datum-logik.js';

const heute = new Date(2026, 9, 9);
const iso = (t, o = {}) => { const r = datumLesen(t, { heute, ...o }); return r.status === 'ok' ? r.iso : r.status; };

test('alle verlangten Schreibweisen ergeben dasselbe Datum', () => {
    for (const t of ['01.01.1998', '1.1.1998', '01011998', '01/01/1998', '1998-01-01', '1-1-1998', ' 01.01.1998 ', '1.1.98', '010198']) {
        assert.equal(iso(t), '1998-01-01', t);
    }
});

test('zweistellige Jahre werden sinnvoll ergaenzt', () => {
    assert.equal(iso('01.01.30'), '2030-01-01');      // Vertragsende
    assert.equal(iso('01.01.47'), '1947-01-01');
    assert.equal(iso('01.01.30', { vergangenheit: true }), '1930-01-01'); // Geburtsdatum
    assert.equal(iso('01.01.26', { vergangenheit: true }), '2026-01-01');
    assert.equal(jahrErgaenzen(98, heute), 1998);
});

test('es gibt keinen 31.02. und keinen 29.02. ausserhalb des Schaltjahrs', () => {
    assert.equal(iso('31.02.2026'), 'ungueltig');
    assert.equal(iso('29.02.2026'), 'ungueltig');
    assert.equal(iso('29.02.2028'), '2028-02-29');
    assert.equal(iso('00.05.2026'), 'ungueltig');
    assert.equal(iso('12.13.2026'), 'ungueltig');
    assert.equal(datumLesen('31.02.2026').grund, 'tag');
    assert.equal(istEchterTag(2026, 4, 31), false);
});

test('Unsinn wird abgelehnt, leer ist leer', () => {
    assert.equal(iso('heute'), 'ungueltig');
    assert.equal(iso('1.1.'), 'ungueltig');
    assert.equal(iso('01.01.0098'), 'ungueltig');
    assert.equal(iso(''), 'leer');
    assert.equal(iso('   '), 'leer');
});

test('Maske setzt die Punkte beim Tippen', () => {
    assert.equal(maskieren('0'), '0');
    assert.equal(maskieren('01'), '01.');
    assert.equal(maskieren('011'), '01.1');
    assert.equal(maskieren('0101'), '01.01.');
    assert.equal(maskieren('01011998'), '01.01.1998');
    assert.equal(maskieren('010119981'), '01.01.1998');   // nicht laenger als 10
    assert.equal(maskieren('1.'), '01.');                 // kurzer Tag mit Punkt
    assert.equal(maskieren('01.1.'), '01.01.');
    assert.equal(maskieren('01/01/1998'), '01.01.1998');
    assert.equal(maskieren('01..'), '01.');               // kein doppelter Punkt
    assert.equal(maskieren('ab12'), '12.');
});

test('Anzeige aus dem gespeicherten ISO-Wert', () => {
    assert.equal(isoZuAnzeige('2026-10-06'), '06.10.2026');
    assert.equal(isoZuAnzeige(''), '');
    assert.equal(isoZuAnzeige(null), '');
    assert.equal(isoZuAnzeige('06/10/2026'), '');
});
