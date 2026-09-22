<?php

namespace App\Support;

/**
 * Pruefung des BIMI-Markenlogos gegen "SVG Tiny Portable/Secure" (Auftrag
 * 22.09.2026: "das Firmenlogo statt des Buchstabens D in Gmail").
 *
 * WARUM EIN EIGENER PRUEFER: BIMI nimmt NICHT irgendein SVG. Das Format ist
 * ein bewusst beschnittener Ausschnitt von SVG Tiny 1.2 - ohne Skript, ohne
 * Animation, ohne eingebettete Rasterbilder, ohne Verweise nach draussen.
 * Faellt eine dieser Regeln, lehnt die Zertifizierungsstelle die Datei ab
 * ODER - schlimmer - das Zertifikat wird ausgestellt und Gmail zeigt das
 * Logo trotzdem nicht. Ein fehlendes `baseProfile="tiny-ps"` allein reicht
 * dafuer aus, und es gibt darauf KEINE Fehlermeldung: es passiert einfach
 * nichts, genau wie heute.
 *
 * Das Zertifikat wird auf GENAU DIESE Datei ausgestellt (Hashwert). Wer das
 * Logo danach aendert, macht das bezahlte Zertifikat ungueltig - deshalb
 * gehoert die Pruefung VOR die Beantragung und in die Testsuite, nicht in
 * ein Merkblatt.
 *
 * Die Regeln stammen aus der BIMI-Group-Spezifikation und den Vorgaben der
 * ausstellenden Stellen (Stand 09/2026). Sie sind bewusst hier gebuendelt -
 * Pruefbefehl (`bimi:pruefen`) und Waechter-Test lesen dieselbe Quelle.
 */
class BimiLogo
{
    /** Obergrenze der BIMI-Spezifikation. */
    public const MAX_BYTES = 32768;

    /** Elemente, die SVG Tiny PS ausdruecklich verbietet. */
    private const VERBOTENE_ELEMENTE = [
        'script', 'a', 'image', 'foreignObject', 'style', 'switch', 'cursor',
        'animate', 'animateColor', 'animateMotion', 'animateTransform', 'set',
        'animation', 'discard', 'handler', 'listener', 'prefetch', 'mpath',
        'filter', 'clipPath', 'mask', 'pattern', 'marker', 'symbol', 'view',
        'text', 'textArea', 'tspan', 'tbreak', 'flowRoot', 'solidColor',
        'font', 'font-face', 'glyph', 'missing-glyph', 'altGlyph',
        'linearGradient', 'radialGradient', 'stop', 'use', 'video', 'audio',
        'iframe', 'embed', 'object',
    ];

    public static function pfad(): string
    {
        return public_path('dienstly-bimi-logo.svg');
    }

    /**
     * Prueft eine SVG-Zeichenkette.
     *
     * @return array{ok: bool, fehler: list<string>, hinweise: list<string>, groesse: int}
     */
    public static function pruefe(string $svg): array
    {
        $fehler = [];
        $hinweise = [];
        $groesse = strlen($svg);

        if ($groesse === 0) {
            return ['ok' => false, 'fehler' => ['Die Datei ist leer.'], 'hinweise' => [], 'groesse' => 0];
        }

        if ($groesse > self::MAX_BYTES) {
            $fehler[] = 'Die Datei ist '.number_format($groesse / 1024, 1, ',', '.').' KB gross - erlaubt sind hoechstens 32 KB.';
        }

        // Eine DOCTYPE-Zeile ist ein Verweis nach draussen (externe DTD) und
        // damit genau das, was "Portable/Secure" ausschliesst.
        if (preg_match('/<!DOCTYPE/i', $svg)) {
            $fehler[] = 'Die Datei enthaelt eine DOCTYPE-Zeile (externer Verweis) - in SVG Tiny PS nicht erlaubt.';
        }

        // Wohlgeformtes XML: eine Zertifizierungsstelle PARST die Datei, sie
        // sieht sie nicht an. Ein nicht geschlossenes Tag faellt im Browser
        // nicht auf (er repariert still), beim Parser sehr wohl.
        $vorher = libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (simplexml_load_string($svg) === false) {
            $meldung = libxml_get_errors()[0] ?? null;
            $fehler[] = 'Die Datei ist kein wohlgeformtes XML'
                .($meldung ? ' (Zeile '.$meldung->line.': '.trim($meldung->message).')' : '').'.';
        }
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);

        if (! preg_match('/<svg\b[^>]*>/i', $svg, $svgTag)) {
            return ['ok' => false, 'fehler' => ['Kein <svg>-Element gefunden.'], 'hinweise' => [], 'groesse' => $groesse];
        }
        $tag = $svgTag[0];

        if (! preg_match('/xmlns\s*=\s*"http:\\/\\/www\\.w3\\.org\\/2000\\/svg"/i', $tag)) {
            $fehler[] = 'Im <svg>-Element fehlt xmlns="http://www.w3.org/2000/svg".';
        }

        // Illustrator schreibt x="0px" y="0px" in die Wurzel. Das ist in
        // SVG Tiny PS nicht zulaessig und einer der haeufigsten
        // Ablehnungsgruende - im Browser sieht die Datei trotzdem richtig aus.
        foreach (['x', 'y'] as $attribut) {
            if (preg_match('/\s'.$attribut.'\s*=\s*"/i', $tag)) {
                $fehler[] = 'Das <svg>-Element hat ein '.$attribut.'-Attribut - im Wurzelelement nicht erlaubt.';
            }
        }

        if (! preg_match('/baseProfile\s*=\s*"tiny-ps"/i', $tag)) {
            $fehler[] = 'Es fehlt baseProfile="tiny-ps" im <svg>-Element - allein das fuehrt zur Ablehnung.';
        }

        if (! preg_match('/\bversion\s*=\s*"1\.2"/i', $tag)) {
            $fehler[] = 'Es fehlt version="1.2" im <svg>-Element.';
        }

        // Der Titel ist Pflicht und traegt den Firmennamen.
        if (! preg_match('/<title>\s*(.+?)\s*<\/title>/is', $svg, $titel)) {
            $fehler[] = 'Es fehlt ein <title>-Element mit dem Firmennamen.';
        } elseif (trim($titel[1]) === '') {
            $fehler[] = 'Das <title>-Element ist leer - es muss den Firmennamen tragen.';
        } elseif (mb_strlen(trim($titel[1])) > 64) {
            $fehler[] = 'Das <title>-Element ist laenger als 64 Zeichen.';
        }

        // Der Titel muss das ERSTE Kindelement sein, nicht irgendwo stehen.
        $rest = (string) preg_replace('/<!--.*?-->/s', '', substr($svg, (int) strpos($svg, $tag) + strlen($tag)));
        if (preg_match('/<([a-zA-Z][\w:-]*)/', $rest, $erstes) && strtolower($erstes[1]) !== 'title') {
            $fehler[] = 'Das <title>-Element muss direkt nach <svg> stehen, hier kommt zuerst <'.$erstes[1].'>.';
        }

        // Quadratisch: die viewBox muss gleich breit wie hoch sein.
        if (! preg_match('/viewBox\s*=\s*"\s*([-\d.]+)[\s,]+([-\d.]+)[\s,]+([-\d.]+)[\s,]+([-\d.]+)\s*"/i', $tag, $vb)) {
            $fehler[] = 'Es fehlt eine viewBox im <svg>-Element.';
        } elseif (abs((float) $vb[3] - (float) $vb[4]) > 0.001) {
            $fehler[] = 'Die viewBox ist nicht quadratisch ('.$vb[3].' x '.$vb[4].') - BIMI verlangt 1:1.';
        }

        // Groesse in ABSOLUTEN Pixeln, nicht in Prozent und nicht in pt/em.
        foreach (['width', 'height'] as $attribut) {
            if (! preg_match('/\b'.$attribut.'\s*=\s*"([^"]+)"/i', $tag, $wert)) {
                $fehler[] = 'Es fehlt das Attribut '.$attribut.' im <svg>-Element.';

                continue;
            }
            if (! preg_match('/^\d+(\.\d+)?(px)?$/i', trim($wert[1]))) {
                $fehler[] = 'Das Attribut '.$attribut.' muss eine absolute Pixelangabe sein, hier steht "'.$wert[1].'".';
            }
        }

        foreach (self::VERBOTENE_ELEMENTE as $element) {
            if (preg_match('/<'.preg_quote($element, '/').'[\s>\/]/i', $svg)) {
                $fehler[] = 'Verbotenes Element <'.$element.'> gefunden.';
            }
        }

        // Verweise nach draussen in beliebiger Schreibweise.
        if (preg_match('/(xlink:href|href\s*=|url\s*\(\s*["\']?https?:)/i', $svg)) {
            $fehler[] = 'Die Datei verweist nach draussen (href / externe URL) - nicht erlaubt.';
        }

        if (preg_match('/\bon[a-z]+\s*=/i', $svg)) {
            $fehler[] = 'Die Datei enthaelt ein Ereignis-Attribut (on...) - nicht erlaubt.';
        }

        if (preg_match('/data:[^;]*;base64/i', $svg)) {
            $fehler[] = 'Die Datei enthaelt ein eingebettetes Rasterbild (data:...base64) - nicht erlaubt.';
        }

        // CSS gibt es in SVG Tiny 1.2 nicht - weder als <style>-Element (oben
        // bereits verboten) noch als Attribut. Ein Illustrator-Export bringt
        // beides mit, und die Zertifizierungsstelle lehnt es ab.
        if (preg_match('/\bstyle\s*=\s*"/i', $svg)) {
            $fehler[] = 'Die Datei enthaelt ein style-Attribut - SVG Tiny 1.2 kennt kein CSS.';
        }

        if (preg_match('/\bclass\s*=\s*"/i', $svg)) {
            $fehler[] = 'Die Datei enthaelt ein class-Attribut - CSS-Klassen sind nicht erlaubt.';
        }

        // Hinweise: kein Ablehnungsgrund, aber im Posteingang sichtbar.
        if (! preg_match('/<rect\b[^>]*\bfill\s*=/i', $svg)) {
            $hinweise[] = 'Kein deckender Hintergrund gefunden - ein durchsichtiges Logo wirkt in dunklen Ansichten leicht unsichtbar.';
        }

        if (preg_match('/fill\s*=\s*"\s*none\s*"/i', $svg)) {
            $hinweise[] = 'Es gibt Flaechen mit fill="none" - bitte im Bild pruefen, ob dort etwas fehlt.';
        }

        return ['ok' => $fehler === [], 'fehler' => $fehler, 'hinweise' => $hinweise, 'groesse' => $groesse];
    }

    /** @return array{ok: bool, fehler: list<string>, hinweise: list<string>, groesse: int} */
    public static function pruefeDatei(?string $pfad = null): array
    {
        $pfad ??= self::pfad();

        if (! is_file($pfad)) {
            return ['ok' => false, 'fehler' => ['Die Datei '.$pfad.' existiert nicht.'], 'hinweise' => [], 'groesse' => 0];
        }

        return self::pruefe((string) file_get_contents($pfad));
    }
}
