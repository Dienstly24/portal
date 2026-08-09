<?php

namespace App\Services\Media;

/**
 * Entschaerft hochgeladene SVG-Dateien (XSS-Schutz, Arbeitsauftrag P1-1):
 * SVG ist XML und kann Skripte, Event-Handler und Fremdressourcen
 * enthalten. Entfernt werden:
 * - <script>, <foreignObject>, <iframe>, <embed>, <object>, <use> mit
 *   externem Ziel sowie Event-Handler-Attribute (on*)
 * - javascript:-URLs und externe href/xlink:href (nur #Anker und
 *   data:image bleiben erlaubt)
 * Bei nicht parsbarem XML wird die Datei abgelehnt (null).
 */
class SvgSanitizer
{
    // Skript- und Einbettungselemente sowie SMIL-Animationen: <animate>, <set>,
    // <animateTransform/-Motion> und <handler> koennen Attribute (auch href und
    // Event-Handler) zur LAUFZEIT umschreiben und umgehen so die statische
    // Attributpruefung unten (z. B. <set attributeName="onload" to="...">). In
    // einem Marken-/Logo-SVG wird nichts davon gebraucht - komplett entfernen.
    private const FORBIDDEN_TAGS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'animation', 'audio', 'video',
        'animate', 'animatetransform', 'animatemotion', 'set', 'discard', 'handler'];

    public static function sanitize(string $svg): ?string
    {
        // XXE-Schutz: keine externen Entities/DTDs zulassen.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            return null;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || ! $dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            return null;
        }

        $walk = function (\DOMElement $el) use (&$walk) {
            // Verbotene Elemente samt Inhalt entfernen.
            foreach (iterator_to_array($el->childNodes) as $child) {
                if ($child instanceof \DOMElement) {
                    if (in_array(strtolower($child->localName), self::FORBIDDEN_TAGS, true)) {
                        $el->removeChild($child);
                        continue;
                    }
                    // <style> bleibt erhalten (Logo-SVGs nutzen interne CSS-
                    // Klassen), aber externe Ressourcen raus: @import und externe
                    // url(...) - die Website laedt NIE Fremdressourcen (DSGVO/
                    // Abmahnung, Audit SEC-4).
                    if (strtolower($child->localName) === 'style') {
                        $child->textContent = self::sanitizeCss($child->textContent);
                        continue;
                    }
                    $walk($child);
                }
            }
            // Gefaehrliche Attribute entfernen.
            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                $name = strtolower($attr->name);
                $value = trim($attr->value);
                if (str_starts_with($name, 'on')) {
                    $el->removeAttributeNode($attr);
                    continue;
                }
                if (in_array($name, ['href', 'xlink:href'], true) || str_ends_with($name, ':href')) {
                    $allowed = str_starts_with($value, '#') || str_starts_with($value, 'data:image/');
                    if (! $allowed) {
                        $el->removeAttributeNode($attr);
                        continue; // Attribut ist weg - nicht doppelt entfernen
                    }
                }
                if (stripos($value, 'javascript:') !== false) {
                    $el->removeAttributeNode($attr);
                    continue;
                }
                // Inline-style-Attribut: externe url(...) neutralisieren.
                if ($name === 'style') {
                    $attr->value = self::sanitizeCss($value);
                }
            }
        };
        $walk($dom->documentElement);

        return $dom->saveXML($dom->documentElement);
    }

    /**
     * Entfernt externe Ressourcen aus CSS (in <style> oder style=""):
     * @import und externe url(...) (http/https/protokollrelativ) werden
     * neutralisiert; interne Referenzen (#id) und data:-URIs bleiben.
     */
    private static function sanitizeCss(string $css): string
    {
        $css = preg_replace('/@import\b[^;]*;?/i', '', $css) ?? $css;
        $css = preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($m) {
            $u = trim($m[2]);
            if (preg_match('#^(https?:)?//#i', $u) || stripos($u, 'javascript:') !== false) {
                return "url('#')";
            }
            return $m[0];
        }, $css) ?? $css;
        return $css;
    }
}
