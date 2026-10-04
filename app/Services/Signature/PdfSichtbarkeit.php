<?php

namespace App\Services\Signature;

use App\Models\SignatureField;
use App\Support\Firmensignatur;
use Symfony\Component\Process\Process;

/**
 * Ist ein Feld im fertigen PDF SICHTBAR? Gemessen am gerenderten Bild,
 * nicht am PDF-Text (KI-061: "/Subtype /Image" im Text erfuellt auch ein
 * weisser Kasten).
 *
 * Gemeinsamer Baustein von Diagnose (`signaturen:diagnose`) und
 * Selbsttest beim Abschluss (`SignedPdfVerifier`) - EINE Regel, was
 * "sichtbar" heisst. Rendert mit pdftoppm (poppler) in ein eigenes
 * Temp-Verzeichnis und raeumt es wieder ab; schreibt sonst nichts.
 */
class PdfSichtbarkeit
{
    /** Aufloesung des Vergleichsbildes - genug fuer eine Unterschrift, schnell genug fuer den Bestand. */
    public const DPI = 60;

    /** Anteil veraenderter Pixel im Feldkasten, ab dem ein Feld als sichtbar gilt. */
    public const MIN_ANTEIL = 0.004;

    /**
     * Rendert dieselbe Seite aus Original und Ergebnis.
     *
     * @return array<string, mixed>
     */
    public function renderPaar(string $original, string $signiert, int $seite, bool $cropBox = false): array
    {
        $ergebnis = ['seite' => $seite, 'verfuegbar' => false, 'original' => null, 'signiert' => null,
            'stderr_original' => '', 'stderr_signiert' => ''];
        $dir = sys_get_temp_dir().'/signatur-diagnose-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            return $ergebnis;
        }
        try {
            foreach (['original' => $original, 'signiert' => $signiert] as $art => $pdf) {
                file_put_contents($dir.'/'.$art.'.pdf', $pdf);
                $process = new Process(array_merge(
                    [(string) config('services.ocr.pdftoppm_binary', 'pdftoppm'), '-png'],
                    $cropBox ? ['-cropbox'] : [],
                    ['-r', (string) self::DPI, '-f', (string) $seite, '-l', (string) $seite,
                        '-singlefile', $dir.'/'.$art.'.pdf', $dir.'/'.$art],
                ));
                $process->setTimeout(60);
                try {
                    $process->run();
                } catch (\Throwable) {
                    return $ergebnis;
                }
                $ergebnis['stderr_'.$art] = mb_substr(trim($process->getErrorOutput()), 0, 300);
                $datei = $dir.'/'.$art.'.png';
                if (is_file($datei)) {
                    $ergebnis[$art] = @imagecreatefrompng($datei) ?: null;
                }
            }
            $ergebnis['verfuegbar'] = $ergebnis['original'] !== null && $ergebnis['signiert'] !== null;

            return $ergebnis;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /**
     * Vergleicht den Feldkasten in beiden Bildern. Die Feldkoordinaten
     * sind Anteile der ANZEIGE-Seite (nach /Rotate) in der Bezugsflaeche
     * der Anfrage - renderPaar() rendert mit bzw. ohne -cropbox genau
     * diese Flaeche.
     *
     * @param  array<string, mixed>  $paar
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  Anteile der Anzeige-Seite
     * @return array<string, mixed>
     */
    public function vergleiche(array $paar, array $box): array
    {
        [$bx, $by, $bw, $bh] = $box;
        if (! $paar['verfuegbar']) {
            return ['urteil' => 'nicht_pruefbar', 'grund' => 'pdftoppm fehlt oder konnte die Seite nicht rendern'];
        }
        $a = $paar['original'];
        $b = $paar['signiert'];
        $w = min(imagesx($a), imagesx($b));
        $h = min(imagesy($a), imagesy($b));
        $x0 = max(0, (int) floor($bx * $w));
        $y0 = max(0, (int) floor($by * $h));
        $x1 = min($w, (int) ceil(($bx + $bw) * $w));
        $y1 = min($h, (int) ceil(($by + $bh) * $h));
        $flaeche = max(0, $x1 - $x0) * max(0, $y1 - $y0);
        if ($flaeche === 0) {
            return ['urteil' => 'unsichtbar', 'geaendert_anteil' => 0.0, 'pixel' => 0];
        }
        $geaendert = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $p = imagecolorat($a, $x, $y);
                $q = imagecolorat($b, $x, $y);
                $d = abs((($p >> 16) & 0xFF) - (($q >> 16) & 0xFF))
                    + abs((($p >> 8) & 0xFF) - (($q >> 8) & 0xFF))
                    + abs(($p & 0xFF) - ($q & 0xFF));
                if ($d > 60) {
                    $geaendert++;
                }
            }
        }
        $anteil = $geaendert / $flaeche;

        return [
            'urteil' => $anteil >= self::MIN_ANTEIL ? 'sichtbar' : 'unsichtbar',
            'geaendert_anteil' => round($anteil, 4),
            'pixel' => $flaeche,
        ];
    }

    /**
     * Der Bereich, in dem das BILD stehen muss (Anteile der Anzeige-Seite).
     * Bei der Unternehmenssignatur liegt unter dem Bild noch Linie und
     * Firmenname (SignedPdfBuilder::stempleFirmensignatur) - wuerde der ganze
     * Kasten verglichen, machte der sichtbare Name ein unsichtbares Logo
     * "sichtbar". Dieselbe Rechnung wie dort.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bildBereich(SignatureField $field, float $seitenHoehePt): array
    {
        $box = [(float) $field->pos_x, (float) $field->pos_y, (float) $field->width, (float) $field->height];
        if (! $field->isCompany()) {
            return $box;
        }
        $hoehePt = $box[3] * $seitenHoehePt;
        $zeile = Firmensignatur::name() === '' ? 0.0 : min(12.0, max(7.0, $hoehePt * 0.22));
        if ($hoehePt >= 26.0 && $zeile > 0.0) {
            $box[3] = ($hoehePt - $zeile * 1.45) / $seitenHoehePt;
        }

        return $box;
    }
}
