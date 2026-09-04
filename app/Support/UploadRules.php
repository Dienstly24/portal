<?php

namespace App\Support;

/**
 * ARCH-6: EINE Quelle fuer die Datei-Regeln.
 *
 * Dieselben Werte standen in acht Controllern: die Groessengrenze 10240
 * vierzehnmal, die Liste "pdf,jpg,jpeg,png,webp" achtmal. Solche Kopien
 * laufen nicht auf einmal auseinander, sondern einzeln - jemand ergaenzt
 * "heic" an der Stelle, an der ein Kunde sich beschwert hat, und die
 * anderen sieben bleiben, wie sie waren. Genau dieser Zustand liegt hier
 * bereits vor: zwei Portal-Wege erlauben heic/heif/gif, die uebrigen nicht.
 *
 * Diese Klasse aendert daran ZUNAECHST NICHTS. Sie benennt die vorhandenen
 * Kombinationen nur, damit die naechste Aenderung an EINER Stelle passiert
 * und der Unterschied zwischen ihnen sichtbar wird, statt sich in
 * Zeichenketten zu verstecken.
 */
class UploadRules
{
    /** Groessengrenze je Datei in Kilobyte (10 MB). */
    public const MAX_KB = 10240;

    /** Anhaenge in Chat, Ticket und E-Mail. */
    public const ATTACHMENT_MIMES = 'pdf,jpg,jpeg,png,webp';

    /** Dokumente in der Kundenakte (zusaetzlich Office-Formate). */
    public const DOCUMENT_MIMES = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx';

    /** Nachweise zu Kundenaenderungen (Kontoauszug, Meldebescheinigung, Ausweis). */
    public const PROOF_MIMES = 'pdf,jpg,jpeg,png,webp';

    /**
     * Regelkette fuer ein Pflichtfeld mit Datei.
     *
     * @return array<int, string>
     */
    public static function required(string $mimes): array
    {
        return ['required', 'file', 'mimes:'.$mimes, 'max:'.self::MAX_KB];
    }

    /**
     * Regelkette fuer ein optionales Datei-Feld.
     *
     * @return array<int, string>
     */
    public static function optional(string $mimes): array
    {
        return ['nullable', 'file', 'mimes:'.$mimes, 'max:'.self::MAX_KB];
    }

    /**
     * Regelkette fuer einen Eintrag in einem Datei-ARRAY (attachments.*).
     * Ohne "required": ob das Array selbst Pflicht ist, entscheidet die
     * Regel am Array, nicht die am einzelnen Eintrag.
     *
     * @return array<int, string>
     */
    public static function each(string $mimes): array
    {
        return ['file', 'mimes:'.$mimes, 'max:'.self::MAX_KB];
    }
}
