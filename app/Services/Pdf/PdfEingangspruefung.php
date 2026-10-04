<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Pruefung eines PDF beim HOCHLADEN zur Unterschrift (Betreiber-Auftrag
 * 04.10.2026, A3).
 *
 * WARUM AM EINGANG: ein Fehler, der erst nach dem Unterschreiben auffaellt,
 * ist dem Unterzeichner nicht zu erklaeren und der Vorgang nicht mehr zu
 * retten. Beim Hochladen kann der Mitarbeiter noch ein anderes PDF waehlen.
 *
 * ABLAUF:
 *  1. Verschluesselt? -> klare Meldung, nie ein spaeterer Fehlschlag.
 *  2. Ist qpdf installiert, prueft `qpdf --check` die Struktur. Meldet es
 *     Fehler oder Warnungen (kaputte Querverweistabelle, falsche Laengen,
 *     abgeschnittene Datei), schreibt qpdf die Datei NEU und die neue
 *     Fassung wird erneut geprueft. Nur eine Fassung, die die Pruefung
 *     besteht UND dieselbe Seitenzahl hat, wird zur Basis.
 *  3. Unser eigener Leser (PdfDocument) muss die Basis oeffnen koennen -
 *     sonst wird abgelehnt.
 *
 * DIE HOCHGELADENE DATEI GEHT NIE VERLOREN: wurde repariert, legt der
 * Aufrufer sie zusaetzlich mit ihrem Hash ab (upload_original_*), und das
 * Protokoll nennt beide Hashes.
 *
 * Fehlt qpdf, entfaellt nur Schritt 2 - die Basis ist dann die
 * hochgeladene Datei, wie vor dem 04.10.2026. Die Systemzustand-Seite
 * zeigt, ob qpdf installiert ist.
 */
class PdfEingangspruefung
{
    public const QPDF_OK = 'ok';

    public const QPDF_REPARIERT = 'repariert';

    public const QPDF_FEHLT = 'nicht_installiert';

    /**
     * @return array{pdf: string, repariert: bool, qpdf: string, meldungen: list<string>}
     *
     * @throws PdfException mit einer Meldung fuer den Mitarbeiter
     */
    public function pruefe(string $binary): array
    {
        if ($binary === '' || ! str_contains(substr($binary, 0, 1024), '%PDF-')) {
            throw new PdfException('Die Datei ist kein PDF. Bitte ein PDF-Dokument hochladen.');
        }
        if ($this->istVerschluesselt($binary)) {
            throw new PdfException(
                'Das PDF ist geschuetzt (verschluesselt oder mit Passwort). Bitte ohne Passwortschutz erneut hochladen.'
            );
        }

        $basis = $binary;
        $repariert = false;
        $meldungen = [];
        $status = self::QPDF_FEHLT;

        if (self::qpdfVerfuegbar()) {
            $check = $this->qpdf(['--check'], $binary);
            $meldungen = $this->kurz($check['ausgabe']);
            if ($check['code'] === 0) {
                $status = self::QPDF_OK;
            } else {
                $neu = $this->qpdf([], $binary, true);
                $nachher = $neu['pdf'] === null ? null : $this->qpdf(['--check'], $neu['pdf']);
                if ($neu['pdf'] === null || $nachher === null || $nachher['code'] !== 0) {
                    throw new PdfException(
                        'Das PDF ist beschaedigt und liess sich nicht automatisch reparieren. '
                        .'Bitte die Datei neu erzeugen (z. B. erneut als PDF speichern oder drucken) und wieder hochladen.'
                    );
                }
                $basis = $neu['pdf'];
                $repariert = true;
                $status = self::QPDF_REPARIERT;
            }
        }

        try {
            $doc = PdfDocument::open($basis);
            $seiten = $doc->pageCount();
        } catch (\Throwable $e) {
            throw new PdfException('Das PDF laesst sich nicht lesen: '.mb_substr($e->getMessage(), 0, 160));
        }
        if ($repariert) {
            // Eine Reparatur, die Seiten verliert, ist keine Reparatur.
            try {
                $vorher = PdfDocument::open($binary)->pageCount();
            } catch (\Throwable) {
                $vorher = $seiten; // die Ausgangsdatei war fuer uns gar nicht lesbar - qpdf hat sie gerettet
            }
            if ($vorher !== $seiten) {
                throw new PdfException('Das PDF ist beschaedigt: nach der Reparatur fehlen Seiten. Bitte die Datei neu erzeugen.');
            }
        }

        return ['pdf' => $basis, 'repariert' => $repariert, 'qpdf' => $status, 'meldungen' => $meldungen];
    }

    /**
     * Strukturpruefung eines fertigen Dokuments (Qualitaetsgate).
     *
     * @return array{verfuegbar: bool, ok: bool, meldungen: list<string>}
     */
    public function strukturpruefung(string $pdf): array
    {
        if (! self::qpdfVerfuegbar()) {
            return ['verfuegbar' => false, 'ok' => true, 'meldungen' => []];
        }
        $check = $this->qpdf(['--check'], $pdf);

        return ['verfuegbar' => true, 'ok' => $check['code'] === 0, 'meldungen' => $this->kurz($check['ausgabe'])];
    }

    public static function qpdfVerfuegbar(): bool
    {
        static $cache = [];
        $binary = self::binary();
        if (! array_key_exists($binary, $cache)) {
            try {
                $p = new Process([$binary, '--version']);
                $p->setTimeout(10);
                $p->run();
                $cache[$binary] = $p->isSuccessful();
            } catch (\Throwable) {
                $cache[$binary] = false;
            }
        }

        return $cache[$binary];
    }

    private static function binary(): string
    {
        return (string) config('services.pdf.qpdf_binary', 'qpdf');
    }

    /**
     * Verschluesselung steht im TRAILER. Mit Fortschreibungen und
     * Querverweis-Stroemen kann er weit vor dem Dateiende liegen - deshalb
     * fragt zuerst unser Leser (er kennt alle Trailer), dann die alte
     * Schnellpruefung des Dateiendes.
     */
    private function istVerschluesselt(string $binary): bool
    {
        try {
            if (PdfDocument::open($binary)->trailerValue('Encrypt') !== null) {
                return true;
            }
        } catch (\Throwable) {
            // nicht lesbar - das entscheidet die weitere Pruefung
        }

        return str_contains(substr($binary, -3000), '/Encrypt');
    }

    /**
     * @param  list<string>  $argumente
     * @return array{code: int, ausgabe: string, pdf: string|null}
     */
    private function qpdf(array $argumente, string $pdf, bool $schreiben = false): array
    {
        $dir = sys_get_temp_dir().'/pdf-eingang-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            return ['code' => 2, 'ausgabe' => 'Temporaeres Verzeichnis nicht anlegbar', 'pdf' => null];
        }
        try {
            file_put_contents($dir.'/ein.pdf', $pdf);
            $befehl = array_merge([self::binary()], $argumente, [$dir.'/ein.pdf']);
            if ($schreiben) {
                $befehl[] = $dir.'/aus.pdf';
            }
            $p = new Process($befehl);
            $p->setTimeout(60);
            try {
                $p->run();
            } catch (\Throwable $e) {
                Log::warning('PDF-Eingangspruefung: qpdf nicht ausfuehrbar: '.$e->getMessage());

                return ['code' => 2, 'ausgabe' => $e->getMessage(), 'pdf' => null];
            }
            // qpdf: 0 = in Ordnung, 2 = Fehler, 3 = Warnungen (Datei
            // geschrieben, aber mit Reparaturen).
            $code = (int) $p->getExitCode();
            $aus = $schreiben && in_array($code, [0, 3], true) && is_file($dir.'/aus.pdf')
                ? (file_get_contents($dir.'/aus.pdf') ?: null)
                : null;

            return ['code' => $code, 'ausgabe' => trim($p->getOutput()."\n".$p->getErrorOutput()), 'pdf' => $aus];
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** @return list<string> */
    private function kurz(string $ausgabe): array
    {
        $zeilen = [];
        foreach (preg_split('/\R/', $ausgabe) ?: [] as $zeile) {
            $zeile = trim(str_replace(sys_get_temp_dir(), '', $zeile));
            if ($zeile === '' || str_starts_with($zeile, 'checking ') || str_starts_with($zeile, 'PDF Version')
                || str_contains($zeile, 'No syntax or stream encoding errors') || str_contains($zeile, 'File is not encrypted')
                || str_contains($zeile, 'File is not linearized') || str_starts_with($zeile, 'R = ') || str_starts_with($zeile, 'P = ')
                || str_contains($zeile, 'extract for accessibility') || str_starts_with($zeile, 'User password')) {
                continue;
            }
            $zeilen[] = mb_substr($zeile, 0, 200);
            if (count($zeilen) >= 8) {
                break;
            }
        }

        return $zeilen;
    }
}
