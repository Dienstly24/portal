<?php
namespace App\Services\Ai\Contracts;

/**
 * Deterministischer Parser fuer ein BEKANNTES, immer gleich aufgebautes
 * Formular (z.B. CHECK24-Beratungsprotokoll). Erkennt es an Stichworten und
 * liest die Felder gratis per fester Regel aus der Textebene - kein KI-Aufruf,
 * keine Kosten. Erkennt der Parser das Formular nicht, liefert er null und
 * die Analyse laeuft normal weiter (Heuristik/KI).
 */
interface DocumentTemplateParser
{
    /**
     * @return array{type:string,confidence:int,summary:string,title:?string,data:array}|null
     *         null = dieses Template trifft nicht zu.
     */
    public function parse(string $text): ?array;
}
