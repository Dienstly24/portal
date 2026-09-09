<?php

namespace App\Services\Pdf;

/**
 * Ein PDF, das dieses Modul nicht sicher verarbeiten kann. Die Meldung ist
 * fuer den MITARBEITER geschrieben, nicht fuer das Log: sie erscheint beim
 * Hochladen, also an der einzigen Stelle, an der noch etwas zu retten ist
 * (anderes PDF waehlen). Ein Fehlschlag NACH dem Unterschreiben waere
 * unzumutbar - deshalb wird jedes Dokument beim Upload geprueft.
 */
class PdfException extends \RuntimeException
{
}
