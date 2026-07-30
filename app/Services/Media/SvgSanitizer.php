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
    private const FORBIDDEN_TAGS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'animation', 'audio', 'video'];

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
                }
            }
        };
        $walk($dom->documentElement);

        return $dom->saveXML($dom->documentElement);
    }
}
