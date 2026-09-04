<?php

namespace App\Support;

/**
 * Minimaler QR-Code-Erzeuger (nur so viel, wie das System braucht).
 *
 * Warum eigener Code statt einer Bibliothek: Der QR-Code wird an genau
 * EINER Stelle gebraucht - beim Einrichten der Zwei-Faktor-Anmeldung, wo
 * eine `otpauth://`-Adresse abfotografiert wird. Dafuer eine Abhaengigkeit
 * aufzunehmen, bedeutet eine weitere Fremd-Bibliothek in einem System, das
 * Kunden-, Gesundheits- und Bankdaten haelt. Der Umfang hier ist bewusst
 * eng: Byte-Modus, Fehlerkorrektur M, Versionen 1-20 (bis 666 Zeichen) -
 * mehr braucht eine otpauth-Adresse nie.
 *
 * Die Ausgabe ist ein INLINE-SVG. Kein Bild vom Server, keine Datei, kein
 * externer Dienst: das Geheimnis der Zwei-Faktor-Anmeldung darf nirgends
 * abgelegt werden und keine fremde Domain je erreichen (die CSP der App
 * verbietet externe Quellen ohnehin).
 *
 * Die Tabellen (Blockaufteilung, Ausrichtungsmuster, Format- und
 * Versionsinformation) stammen aus ISO/IEC 18004. Die Implementierung
 * wurde bei der Entwicklung modulweise gegen eine Referenz-Implementierung
 * geprueft; `tests/Unit/QrCodeTest.php` haelt die Eckwerte fest.
 */
class QrCode
{
    /** Fehlerkorrektur-Stufe M: [Version => [Gesamt-Codewoerter, Daten-Codewoerter, ECC je Block, [[Bloecke, Daten je Block], ...]]] */
    private const VERSIONS = [
        1 => [26, 16, 10, [[1, 16]]],   // bis 14 Zeichen
        2 => [44, 28, 16, [[1, 28]]],   // bis 26 Zeichen
        3 => [70, 44, 26, [[1, 44]]],   // bis 42 Zeichen
        4 => [100, 64, 18, [[2, 32]]],   // bis 62 Zeichen
        5 => [134, 86, 24, [[2, 43]]],   // bis 84 Zeichen
        6 => [172, 108, 16, [[4, 27]]],   // bis 106 Zeichen
        7 => [196, 124, 18, [[4, 31]]],   // bis 122 Zeichen
        8 => [242, 154, 22, [[2, 38], [2, 39]]],   // bis 152 Zeichen
        9 => [292, 182, 22, [[3, 36], [2, 37]]],   // bis 180 Zeichen
        10 => [346, 216, 26, [[4, 43], [1, 44]]],   // bis 213 Zeichen
        11 => [404, 254, 30, [[1, 50], [4, 51]]],   // bis 251 Zeichen
        12 => [466, 290, 22, [[6, 36], [2, 37]]],   // bis 287 Zeichen
        13 => [532, 334, 22, [[8, 37], [1, 38]]],   // bis 331 Zeichen
        14 => [581, 365, 24, [[4, 40], [5, 41]]],   // bis 362 Zeichen
        15 => [655, 415, 24, [[5, 41], [5, 42]]],   // bis 412 Zeichen
        16 => [733, 453, 28, [[7, 45], [3, 46]]],   // bis 450 Zeichen
        17 => [815, 507, 28, [[10, 46], [1, 47]]],   // bis 504 Zeichen
        18 => [901, 563, 26, [[9, 43], [4, 44]]],   // bis 560 Zeichen
        19 => [991, 627, 26, [[3, 44], [11, 45]]],   // bis 624 Zeichen
        20 => [1085, 669, 26, [[3, 41], [13, 42]]],   // bis 666 Zeichen
    ];

    /** Mittelpunkte der Ausrichtungsmuster je Version. */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /** Formatinformation (Stufe M) je Maske 0-7, bereits BCH-gesichert. */
    private const FORMAT_INFO = [21522, 20773, 24188, 23371, 17913, 16590, 20375, 19104];

    /** Versionsinformation ab Version 7 (BCH-gesichert). */
    private const VERSION_INFO = [
        7 => 31892,
        8 => 34236,
        9 => 39577,
        10 => 42195,
        11 => 48118,
        12 => 51042,
        13 => 55367,
        14 => 58893,
        15 => 63784,
        16 => 68472,
        17 => 70749,
        18 => 76311,
        19 => 79154,
        20 => 84390,
    ];

    /**
     * QR-Code als eigenstaendiges SVG.
     *
     * @param  int  $moduleSize  Kantenlaenge eines Moduls in Pixeln
     * @param  int  $quiet       Ruhezone in Modulen (Norm: mindestens 4)
     */
    public static function svg(string $text, int $moduleSize = 6, int $quiet = 4, string $alt = ''): string
    {
        $matrix = self::matrix($text);
        $count = count($matrix);
        $size = ($count + 2 * $quiet) * $moduleSize;

        // Ein einziger Pfad statt tausender Rechtecke - kleineres SVG,
        // schnelleres Rendern.
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $px = ($x + $quiet) * $moduleSize;
                    $py = ($y + $quiet) * $moduleSize;
                    $path .= "M{$px} {$py}h{$moduleSize}v{$moduleSize}h-{$moduleSize}z";
                }
            }
        }

        $label = $alt !== '' ? ' role="img" aria-label="'.htmlspecialchars($alt, ENT_QUOTES).'"' : ' role="img" aria-label="QR-Code"';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'"'
            .' viewBox="0 0 '.$size.' '.$size.'" shape-rendering="crispEdges"'.$label.'>'
            .'<rect width="'.$size.'" height="'.$size.'" fill="#ffffff"/>'
            .'<path d="'.$path.'" fill="#000000"/>'
            .'</svg>';
    }

    /**
     * @param  int|null  $forceMask  nur fuer Tests: feste Maske statt der
     *                               besten (macht das Ergebnis vergleichbar)
     * @return array<int, array<int, bool>> true = dunkles Modul
     */
    public static function matrix(string $text, ?int $forceMask = null): array
    {
        $version = self::pickVersion($text);
        [$total, $dataWords, $eccPerBlock, $blockSpec] = self::VERSIONS[$version];

        $codewords = self::interleave(self::encodeData($text, $version), $version);
        $size = 17 + 4 * $version;

        // reserved: true = Funktionsmuster, darf keine Daten aufnehmen
        $reserved = self::functionPatternMap($version, $size);
        $matrix = self::drawFunctionPatterns($version, $size);
        self::placeData($matrix, $reserved, $codewords, $size);

        // Beste Maske waehlen (Norm: geringste Strafpunkte)
        $best = null;
        $bestScore = PHP_INT_MAX;
        $masks = $forceMask === null ? range(0, 7) : [$forceMask];
        foreach ($masks as $mask) {
            $candidate = self::applyMask($matrix, $reserved, $mask, $size);
            self::writeFormatInfo($candidate, $mask, $size);
            $score = self::penalty($candidate, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** Kleinste Version, in die der Text passt. */
    private static function pickVersion(string $text): int
    {
        $length = strlen($text);
        foreach (self::VERSIONS as $version => [$total, $dataWords]) {
            $header = $version >= 10 ? 3 : 2; // Modus + Zeichenzahl in Bytes
            if ($length <= $dataWords - $header) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('Text ist zu lang fuer einen QR-Code dieser Groesse (max. 666 Zeichen).');
    }

    /** Bitstrom bauen: Modus, Laenge, Daten, Abschluss, Fuellbytes. */
    private static function encodeData(string $text, int $version): array
    {
        [$total, $dataWords] = self::VERSIONS[$version];
        $countBits = $version >= 10 ? 16 : 8;

        $bits = '0100'; // Byte-Modus
        $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < strlen($text); $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Abschlusszeichen (max. 4 Nullbits) und auf volle Bytes auffuellen
        $capacityBits = $dataWords * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        $words = [];
        foreach (str_split($bits, 8) as $byte) {
            $words[] = bindec($byte);
        }

        // Normierte Fuellbytes im Wechsel
        $pad = [0xEC, 0x11];
        $i = 0;
        while (count($words) < $dataWords) {
            $words[] = $pad[$i++ % 2];
        }

        return $words;
    }

    /** Daten in Bloecke teilen, ECC rechnen, normgerecht verschraenken. */
    private static function interleave(array $data, int $version): array
    {
        [$total, $dataWords, $eccPerBlock, $blockSpec] = self::VERSIONS[$version];

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;
        foreach ($blockSpec as [$blockCount, $perBlock]) {
            for ($b = 0; $b < $blockCount; $b++) {
                $block = array_slice($data, $offset, $perBlock);
                $offset += $perBlock;
                $dataBlocks[] = $block;
                $eccBlocks[] = self::reedSolomon($block, $eccPerBlock);
            }
        }

        $result = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /** Reed-Solomon-Fehlerkorrektur ueber GF(256), Polynom 0x11D. */
    private static function reedSolomon(array $data, int $eccLength): array
    {
        static $exp = null, $log = null;
        if ($exp === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
        }

        // Generatorpolynom aufbauen
        $generator = [1];
        for ($i = 0; $i < $eccLength; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coeff) {
                $next[$j] ^= $coeff;
                if ($coeff !== 0) {
                    $next[$j + 1] ^= $exp[($log[$coeff] + $i) % 255];
                }
            }
            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $eccLength, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            $factorLog = $log[$factor];
            foreach ($generator as $j => $coeff) {
                if ($coeff !== 0) {
                    $remainder[$i + $j] ^= $exp[($log[$coeff] + $factorLog) % 255];
                }
            }
        }

        return array_slice($remainder, count($data), $eccLength);
    }

    /** Karte der Funktionsmuster (true = fuer Daten gesperrt). */
    private static function functionPatternMap(int $version, int $size): array
    {
        $map = array_fill(0, $size, array_fill(0, $size, false));

        $mark = function (array &$map, int $x0, int $y0, int $w, int $h) use ($size) {
            for ($y = $y0; $y < $y0 + $h; $y++) {
                for ($x = $x0; $x < $x0 + $w; $x++) {
                    if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                        $map[$y][$x] = true;
                    }
                }
            }
        };

        // Suchmuster inkl. Trennlinien und Formatbereich
        $mark($map, 0, 0, 9, 9);
        $mark($map, $size - 8, 0, 8, 9);
        $mark($map, 0, $size - 8, 9, 8);

        // Taktmuster
        $mark($map, 6, 0, 1, $size);
        $mark($map, 0, 6, $size, 1);

        // Ausrichtungsmuster
        $positions = self::ALIGNMENT[$version];
        foreach ($positions as $ry) {
            foreach ($positions as $rx) {
                if (self::skipAlignment($rx, $ry, $positions, $size)) {
                    continue;
                }
                $mark($map, $rx - 2, $ry - 2, 5, 5);
            }
        }

        // Versionsinformation (ab Version 7)
        if ($version >= 7) {
            $mark($map, 0, $size - 11, 6, 3);
            $mark($map, $size - 11, 0, 3, 6);
        }

        return $map;
    }

    /** Ausrichtungsmuster in den Ecken der Suchmuster entfallen. */
    private static function skipAlignment(int $x, int $y, array $positions, int $size): bool
    {
        $last = $size - 7;

        return ($x === 6 && $y === 6)
            || ($x === 6 && $y === $last)
            || ($x === $last && $y === 6);
    }

    /** Funktionsmuster wirklich zeichnen. */
    private static function drawFunctionPatterns(int $version, int $size): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, false));

        $finder = function (array &$m, int $ox, int $oy) use ($size) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $ox + $x;
                    $py = $oy + $y;
                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }
                    $inRing = ($x === 0 || $x === 6) && $y >= 0 && $y <= 6;
                    $inRing = $inRing || (($y === 0 || $y === 6) && $x >= 0 && $x <= 6);
                    $inCore = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    $m[$py][$px] = $inRing || $inCore;
                }
            }
        };

        $finder($m, 0, 0);
        $finder($m, $size - 7, 0);
        $finder($m, 0, $size - 7);

        // Taktmuster
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = ($i % 2) === 0;
            $m[6][$i] = $dark;
            $m[$i][6] = $dark;
        }

        // Ausrichtungsmuster
        $positions = self::ALIGNMENT[$version];
        foreach ($positions as $ry) {
            foreach ($positions as $rx) {
                if (self::skipAlignment($rx, $ry, $positions, $size)) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $ring = max(abs($x), abs($y));
                        $m[$ry + $y][$rx + $x] = ($ring !== 1);
                    }
                }
            }
        }

        // Immer dunkles Modul
        $m[$size - 8][8] = true;

        // Versionsinformation
        if ($version >= 7) {
            $info = self::VERSION_INFO[$version];
            for ($i = 0; $i < 18; $i++) {
                $bit = (bool) (($info >> $i) & 1);
                $x = intdiv($i, 3);
                $y = $size - 11 + ($i % 3);
                $m[$y][$x] = $bit;
                $m[$x][$y] = $bit;
            }
        }

        return $m;
    }

    /** Datenbits im Zickzack von rechts unten nach oben legen. */
    private static function placeData(array &$m, array $reserved, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $word) {
            $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $upward = true;
        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5; // Spalte des Taktmusters ueberspringen
            }
            for ($step = 0; $step < $size; $step++) {
                $y = $upward ? ($size - 1 - $step) : $step;
                foreach ([0, 1] as $offset) {
                    $x = $right - $offset;
                    if ($reserved[$y][$x]) {
                        continue;
                    }
                    $m[$y][$x] = isset($bits[$index]) ? $bits[$index] === '1' : false;
                    $index++;
                }
            }
            $upward = ! $upward;
        }
    }

    /** Maske auf alle Datenmodule anwenden. */
    private static function applyMask(array $m, array $reserved, int $mask, int $size): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                if (self::maskCondition($mask, $x, $y)) {
                    $m[$y][$x] = ! $m[$y][$x];
                }
            }
        }

        return $m;
    }

    private static function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
        };
    }

    /** Formatinformation an ihre zwei Stellen schreiben. */
    private static function writeFormatInfo(array &$m, int $mask, int $size): void
    {
        $info = self::FORMAT_INFO[$mask];

        for ($i = 0; $i < 15; $i++) {
            // Die 15 Bits liegen HOECHSTWERTIG ZUERST auf den Zellen -
            // deshalb (14 - $i) und nicht $i.
            $bit = (bool) (($info >> (14 - $i)) & 1);

            // Kopie 1: um das linke obere Suchmuster
            if ($i < 6) {
                $m[8][$i] = $bit;
            } elseif ($i === 6) {
                $m[8][7] = $bit;
            } elseif ($i === 7) {
                $m[8][8] = $bit;
            } elseif ($i === 8) {
                $m[7][8] = $bit;
            } else {
                $m[14 - $i][8] = $bit;
            }

            // Kopie 2: senkrecht am linken unteren Suchmuster (Bits 0-6)
            // und waagerecht am rechten oberen (Bits 7-14). Die Grenze
            // liegt bei 7, NICHT bei 8: die Zelle (size-8, 8) ist das
            // immer dunkle Modul und gehoert nicht zur Formatinformation.
            if ($i < 7) {
                $m[$size - 1 - $i][8] = $bit;
            } else {
                $m[8][$size - 15 + $i] = $bit;
            }
        }

        $m[$size - 8][8] = true; // immer dunkles Modul
    }


    /**
     * Strafpunkte fuer suchmuster-aehnliche Folgen in EINER Zeile/Spalte.
     * Vorgehen wie in ISO/IEC 18004: den dunklen Kern 1011101 suchen und
     * pruefen, ob davor oder danach vier helle Module (oder der Rand)
     * liegen.
     */
    private static function finderLikePenalty(array $line, int $size): int
    {
        $core = [true, false, true, true, true, false, true];
        $score = 0;

        $i = 0;
        while ($i <= $size - 7) {
            if (array_slice($line, $i, 7) !== $core) {
                $i++;
                continue;
            }

            $before = array_slice($line, max($i - 4, 0), min(4, $i));
            $after = array_slice($line, $i + 7, 4);

            $quietBefore = $i === 0 || ! in_array(true, $before, true);
            $quietAfter = ($i + 7) >= $size || ! in_array(true, $after, true);

            if ($quietBefore || $quietAfter) {
                $score += 40;
                $i += 7;
            } else {
                // Kein Platz fuer die helle Flaeche - erst ab der Mitte des
                // Kerns kann ein neuer Treffer beginnen.
                $i += 4;
            }
        }

        return $score;
    }

    /** Strafpunkte nach ISO/IEC 18004 (Regeln 1-4). */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;

        // Regel 1: Reihen gleicher Farbe ab Laenge 5
        foreach ([true, false] as $byRow) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $prev = $byRow ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    $cur = $byRow ? $m[$a][$b] : $m[$b][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += 3 + ($run - 5);
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // Regel 2: einfarbige 2x2-Bloecke
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Regel 3: Muster im Verhaeltnis 1:1:3:1:1 (wie der Kern eines
        // Suchmusters), dem eine helle Flaeche von 4 Modulen VORAUS- ODER
        // NACHgeht. Wichtig: der Symbolrand zaehlt als hell - sonst bleiben
        // genau die Faelle am Rand ungestraft, wegen derer die Regel
        // existiert (ein Scanner haelt sie fuer ein Suchmuster).
        for ($a = 0; $a < $size; $a++) {
            $row = [];
            $col = [];
            for ($b = 0; $b < $size; $b++) {
                $row[] = $m[$a][$b];
                $col[] = $m[$b][$a];
            }
            $score += self::finderLikePenalty($row, $size);
            $score += self::finderLikePenalty($col, $size);
        }

        // Regel 4: Abweichung vom Verhaeltnis 50 % dunkler Module
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $v) {
                if ($v) {
                    $dark++;
                }
            }
        }
        $percent = $dark * 100 / ($size * $size);
        $score += (int) (floor(abs($percent - 50) / 5) * 10);

        return $score;
    }
}
