<?php

namespace App\Services\Signature;

use App\Models\SignatureField;
use App\Models\SignatureRequest;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfPage;
use App\Services\Pdf\PdfSyntax;
use App\Support\Firmensignatur;
use App\Support\SignatureStatus;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Process\Process;

/**
 * Warum steht eine Unterschrift NICHT im fertigen PDF?
 *
 * Betreiber-Meldung 02.10.2026: Kundenunterschrift und Firmenstempel fehlen
 * im unterschriebenen Dokument, obwohl das Protokoll "Unterschrieben"
 * vermerkt. Im Nachbau fanden sich zwei Ursachen (Firmenbild wird als
 * EINFARBIGE Flaeche gestempelt; ein indirektes /Contents-Array macht die
 * Seite fuer poppler unlesbar). Ob die gemeldete Anfrage genau daran
 * scheitert, steht aber nur auf dem Server. Dieser Dienst beantwortet das
 * fuer EINE Anfrage oder den ganzen Bestand.
 *
 * STRENG LESEND: er schreibt nichts in die Datenbank, nichts in den
 * Speicher und kein Protokoll-Ereignis. Ein Fehlbild wird ausschliesslich
 * in einem eigenen Temp-Verzeichnis gerendert und danach geloescht.
 *
 * KEINE PERSONENBEZOGENEN DATEN IM BERICHT: weder Titel noch Namen noch
 * E-Mail-Adressen - der Bericht soll gefahrlos weitergegeben werden koennen.
 *
 * DAS ENTSCHEIDENDE URTEIL IST DAS BILD, nicht die Struktur: je Feld wird
 * die Seite aus Original und Ergebnis gerendert und im Feldkasten
 * verglichen. Erst wenn dort kein Unterschied ist, gilt das Feld als
 * "unsichtbar" - und erst dann wird nach der Ursache gefragt. Findet sich
 * keine bekannte, steht das ausdruecklich da (UNBEKANNT) statt einer
 * geratenen.
 */
class SignatureDiagnostics
{
    /** Ursachen-Kennungen - der Befehl gibt sie aus, die Tests pruefen sie. */
    public const KEIN_SIGNIERTES_PDF = 'U0_KEIN_SIGNIERTES_PDF';

    public const FIRMENBILD_OPAK = 'U1_BILD_OPAK_WIRD_FLAECHE';

    public const BILD_MEHRFARBIG = 'U1b_BILD_MEHRFARBIG_WIRD_EINFARBIG';

    public const CONTENTS_INDIREKT = 'U2_CONTENTS_INDIREKTES_ARRAY';

    public const BILD_FEHLT = 'U3_BILD_FEHLT_ODER_LEER';

    public const FELD_AUSSERHALB = 'U4_FELD_AUSSERHALB_DER_SEITE';

    public const SEITE_FEHLT = 'U5_SEITE_FEHLT';

    public const SIGNIERT_GLEICH_ORIGINAL = 'U6_SIGNIERT_GLEICH_ORIGINAL';

    public const ERZEUGUNG_FEHLGESCHLAGEN = 'U7_PDF_ERZEUGUNG_FEHLGESCHLAGEN';

    public const OBJEKT_MEHRFACH = 'U8_SEITENOBJEKT_MEHRFACH_FORTGESCHRIEBEN';

    public const GENERATION = 'U9_SEITENOBJEKT_GENERATION_UNGLEICH_0';

    public const VORSPANN = 'U10_VORSPANN_VOR_PDF_KOPF';

    public const RENDER_FEHLER = 'U11_POPPLER_MELDET_FEHLER';

    public const HASH_ABWEICHUNG = 'U12_DATEI_HASH_WEICHT_AB';

    public const UNBEKANNT = 'UX_UNSICHTBAR_OHNE_BEKANNTE_URSACHE';

    /** Aufloesung des Vergleichsbildes - genug fuer eine Unterschrift, schnell genug fuer den Bestand. */
    private const DPI = 60;

    /** Anteil veraenderter Pixel im Feldkasten, ab dem ein Feld als sichtbar gilt. */
    private const MIN_ANTEIL = 0.004;

    public function __construct(private readonly SignatureStorage $storage)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(SignatureRequest $request, bool $rendern = true, bool $logs = true): array
    {
        $request->loadMissing(['fields.companyAsset', 'signers', 'events']);
        $ursachen = [];
        $hinweise = [];

        $bericht = [
            'id' => $request->id,
            'status' => $request->status,
            'erstellt' => optional($request->created_at)->toIso8601String(),
            'abgeschlossen' => optional($request->completed_at)->toIso8601String(),
            'seiten_laut_db' => (int) $request->page_count,
            'unterzeichner' => $request->signers->count(),
            'davon_unterschrieben' => $request->signers->filter(fn ($s) => $s->hasSigned())->count(),
        ];

        // ------------------------------------------------------ Dateien
        $original = $this->storage->read($request->original_path);
        $signiert = $request->signed_path ? $this->storage->read($request->signed_path) : null;

        $bericht['dateien'] = [
            'original_vorhanden' => $original !== null,
            'original_hash_ok' => $original !== null && hash('sha256', $original) === $request->original_hash,
            'signiert_pfad_gesetzt' => $request->signed_path !== null,
            'signiert_vorhanden' => $signiert !== null,
            'signiert_hash_ok' => $signiert !== null && hash('sha256', $signiert) === $request->signed_hash,
            'signiert_beginnt_mit_original' => $signiert !== null && $original !== null && str_starts_with($signiert, $original),
            'signiert_gleich_original' => $signiert !== null && $original !== null && $signiert === $original,
            'bytes_original' => $original === null ? null : strlen($original),
            'bytes_signiert' => $signiert === null ? null : strlen($signiert),
        ];
        if ($original !== null && ! $bericht['dateien']['original_hash_ok']) {
            $ursachen[] = self::HASH_ABWEICHUNG;
        }
        if ($signiert !== null && ! $bericht['dateien']['signiert_hash_ok']) {
            $ursachen[] = self::HASH_ABWEICHUNG;
        }
        if ($bericht['dateien']['signiert_gleich_original']) {
            $ursachen[] = self::SIGNIERT_GLEICH_ORIGINAL;
        }

        $fehlgeschlagen = $request->events
            ->filter(fn ($e) => in_array($e->event, ['pdf_generated', 'signing_failed'], true)
                && ($e->event === 'signing_failed' || str_starts_with((string) $e->description, 'FEHLGESCHLAGEN')))
            ->map(fn ($e) => [
                'ereignis' => $e->event,
                'zeit' => optional($e->created_at)->toIso8601String(),
                'text' => $this->ohnePersonendaten(mb_substr((string) $e->description, 0, 300)),
            ])->values()->all();
        $bericht['fehlerereignisse'] = $fehlgeschlagen;
        if ($fehlgeschlagen !== []) {
            $ursachen[] = self::ERZEUGUNG_FEHLGESCHLAGEN;
        }

        // Fehlt das fertige PDF, wird es zur Diagnose IM SPEICHER nachgebaut
        // - mit demselben Code, der es erzeugen wuerde. So zeigt sich, ob der
        // heutige Stand die Unterschrift sichtbar machen WUERDE.
        $simuliert = false;
        $allesUnterschrieben = $request->signers->isNotEmpty()
            && $request->signers->every(fn ($s) => $s->hasSigned());
        if ($signiert === null && $original !== null && ($allesUnterschrieben || $request->status === SignatureStatus::COMPLETED)) {
            $ursachen[] = self::KEIN_SIGNIERTES_PDF;
            try {
                $signiert = app(SignedPdfBuilder::class)->build($request)['pdf'];
                $simuliert = true;
            } catch (\Throwable $e) {
                $hinweise[] = 'Nachbau des signierten PDF scheitert: '.$this->ohnePersonendaten(mb_substr($e->getMessage(), 0, 200));
            }
        }
        $bericht['ergebnis_simuliert'] = $simuliert;

        // ------------------------------------------- Aufbau des Originals
        $doc = null;
        $seiten = [];
        if ($original !== null) {
            try {
                $doc = PdfDocument::open($original);
                $bericht['pdf'] = [
                    'seiten' => $doc->pageCount(),
                    'xref' => $doc->usesXrefStream() ? 'strom' : 'tabelle',
                    'objektstroeme' => $doc->objectStreamMembers() !== [],
                    'verschluesselt' => $doc->trailerValue('Encrypt') !== null,
                    'vorspann_bytes' => $doc->headerOffset(),
                    'fortschreibungen' => max(0, substr_count($original, 'startxref') - 1),
                ];
                if ($doc->headerOffset() > 0) {
                    $ursachen[] = self::VORSPANN;
                }
                foreach ($doc->pages() as $page) {
                    $seiten[$page->index + 1] = $this->seitenAufbau($doc, $page);
                }
            } catch (\Throwable $e) {
                $hinweise[] = 'Original nicht lesbar: '.mb_substr($e->getMessage(), 0, 200);
            }
        }

        // ----------------------------------------------------- Felder
        $felder = [];
        $render = [];
        foreach ($request->fields->sortBy(['page', 'sort']) as $field) {
            $eintrag = $this->feldBefund($field, $seiten);
            $feldUrsachen = $eintrag['ursachen'];

            if ($rendern && $signiert !== null && $original !== null && $eintrag['gefuellt']
                && isset($seiten[$field->page])) {
                $render[$field->page] ??= $this->renderPaar($original, $signiert, (int) $field->page);
                $eintrag['sichtbarkeit'] = $this->sichtbarkeit($render[$field->page], $this->bildBereich($field, $seiten[$field->page]));
                if ($render[$field->page]['stderr_signiert'] !== '') {
                    $feldUrsachen[] = self::RENDER_FEHLER;
                }
            } else {
                $eintrag['sichtbarkeit'] = ['urteil' => $eintrag['gefuellt'] ? 'nicht_geprueft' : 'nicht_ausgefuellt'];
            }

            if (($eintrag['sichtbarkeit']['urteil'] ?? null) === 'unsichtbar' && $feldUrsachen === []) {
                $feldUrsachen[] = self::UNBEKANNT;
            }
            $eintrag['ursachen'] = array_values(array_unique($feldUrsachen));
            $felder[] = $eintrag;
            array_push($ursachen, ...$eintrag['ursachen']);
        }
        $bericht['seiten'] = array_values($seiten);
        $bericht['felder'] = $felder;
        $bericht['poppler'] = array_map(fn ($r) => [
            'seite' => $r['seite'],
            'verfuegbar' => $r['verfuegbar'],
            'meldung_original' => $r['stderr_original'],
            'meldung_signiert' => $r['stderr_signiert'],
        ], array_values($render));

        $sichtbar = collect($felder)->where('sichtbarkeit.urteil', 'sichtbar')->count();
        $unsichtbar = collect($felder)->where('sichtbarkeit.urteil', 'unsichtbar')->count();
        $bericht['zusammenfassung'] = [
            'felder' => count($felder),
            'ausgefuellt' => collect($felder)->where('gefuellt', true)->count(),
            'sichtbar' => $sichtbar,
            'unsichtbar' => $unsichtbar,
        ];
        $bericht['ursachen'] = array_values(array_unique($ursachen));
        $bericht['hinweise'] = $hinweise;
        $bericht['log'] = $logs ? $this->logZeilen($request->id) : [];

        return $bericht;
    }

    /**
     * Aufbau einer Seite: Boxen, Drehung, Form des /Contents-Eintrags und
     * ob das Seitenobjekt mehrfach in der Datei steht.
     *
     * @return array<string, mixed>
     */
    private function seitenAufbau(PdfDocument $doc, PdfPage $page): array
    {
        $dict = $doc->dict($page->objectNumber);
        $crop = isset($dict['CropBox']) ? PdfSyntax::numbers((string) $doc->resolve($dict['CropBox'])) : null;
        $versionen = $doc->objectVersions($page->objectNumber);

        return [
            'seite' => $page->index + 1,
            'objekt' => $page->objectNumber,
            'mediabox' => array_map(fn ($v) => round($v, 2), $page->mediaBox),
            'cropbox' => $crop === null || count($crop) !== 4 ? null : array_map(fn ($v) => round($v, 2), $crop),
            'rotate' => $page->rotate,
            'anzeige' => [round($page->displayWidth(), 2), round($page->displayHeight(), 2)],
            'contents' => $this->contentsForm($doc, $dict['Contents'] ?? null),
            'versionen' => $versionen,
        ];
    }

    /** direkt-strom | array-direkt | array-indirekt | fehlt | unbekannt */
    private function contentsForm(PdfDocument $doc, ?string $contents): string
    {
        if ($contents === null) {
            return 'fehlt';
        }
        $contents = trim($contents);
        if (str_starts_with($contents, '[')) {
            return 'array-direkt';
        }
        if (! PdfSyntax::isReference($contents)) {
            return 'unbekannt';
        }
        $number = PdfSyntax::referenceNumber($contents);
        $body = $number === null ? null : $doc->objectBody($number);
        if ($body === null) {
            return 'unbekannt';
        }
        $body = ltrim($body);
        if (str_starts_with($body, '[')) {
            return 'array-indirekt';
        }

        return str_contains($body, 'stream') ? 'direkt-strom' : 'unbekannt';
    }

    /**
     * @param  array<int, array<string, mixed>>  $seiten
     * @return array<string, mixed>
     */
    private function feldBefund(SignatureField $field, array $seiten): array
    {
        $ursachen = [];
        $x = (float) $field->pos_x;
        $y = (float) $field->pos_y;
        $w = (float) $field->width;
        $h = (float) $field->height;

        $eintrag = [
            'feld' => $field->id,
            'art' => $field->type,
            'seite' => (int) $field->page,
            'box_anteilig' => [round($x, 4), round($y, 4), round($w, 4), round($h, 4)],
            'gefuellt' => $field->isFilled(),
            'pflicht' => (bool) $field->required,
            'risiken' => [],
        ];

        if (! isset($seiten[$field->page])) {
            $ursachen[] = self::SEITE_FEHLT;
        } else {
            $seite = $seiten[$field->page];
            [$dw, $dh] = $seite['anzeige'];
            $eintrag['box_punkte'] = [round($x * $dw, 1), round($y * $dh, 1), round($w * $dw, 1), round($h * $dh, 1)];
            if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
                $ursachen[] = self::FELD_AUSSERHALB;
            }
            if ($eintrag['gefuellt'] && $seite['contents'] === 'array-indirekt') {
                $ursachen[] = self::CONTENTS_INDIREKT;
            }
            if ($eintrag['gefuellt'] && ($seite['versionen']['klartext'] > 1 || ($seite['versionen']['klartext'] > 0 && $seite['versionen']['im_objektstrom']))) {
                $ursachen[] = self::OBJEKT_MEHRFACH;
            }
            if ($eintrag['gefuellt'] && array_diff($seite['versionen']['generationen'], [0]) !== []) {
                $ursachen[] = self::GENERATION;
            }
        }

        // Das Bild, das der Stempler bekommt - Handschrift oder Firmenbild.
        $png = null;
        if ($field->isDrawn() && $field->image_path) {
            $png = $this->storage->read($field->image_path);
            $eintrag['bild_quelle'] = 'unterschrift';
        } elseif ($field->isCompany() && $field->companyAsset !== null) {
            $png = $this->storage->read($field->companyAsset->path);
            $eintrag['bild_quelle'] = 'firmenbild:'.$field->companyAsset->type;
        }

        if ($field->isDrawn() || $field->isCompany()) {
            if ($eintrag['gefuellt'] && ($png === null || $png === '')) {
                $ursachen[] = self::BILD_FEHLT;
                $eintrag['bild'] = null;
            } elseif ($png !== null) {
                $analyse = $this->bildAnalyse($png);
                $eintrag['bild'] = $analyse;
                if ($analyse === null) {
                    $ursachen[] = self::BILD_FEHLT;
                } else {
                    // Ursache nur, wenn daraus wirklich "unsichtbar" folgt:
                    // ein ueberwiegend deckendes Bild wird zur Flaeche, eine
                    // helle Stempelfarbe zur weissen Kontur.
                    if ($analyse['deckend_anteil'] > 0.5 || $analyse['stempel_farbe_hell']) {
                        $ursachen[] = self::FIRMENBILD_OPAK;
                    }
                    // Mehrfarbig ist ein RISIKO (falsche Farbe), keine
                    // Erklaerung fuer ein fehlendes Bild.
                    if ($analyse['farben_deckend'] > 1) {
                        $eintrag['risiken'][] = self::BILD_MEHRFARBIG;
                    }
                    if ($analyse['deckend_anteil'] + $analyse['halbdeckend_anteil'] < 0.001) {
                        $ursachen[] = self::BILD_FEHLT;
                    }
                }
            }
        }

        $eintrag['ursachen'] = $ursachen;

        return $eintrag;
    }

    /**
     * Was macht der Stempler aus diesem Bild? Er nimmt den Alphakanal als
     * Maske und EINE Farbe - die des ersten deckenden Pixels. Ein deckendes
     * Bild wird damit zu einer gleichfarbigen Flaeche; ist das erste
     * deckende Pixel weiss (Hintergrund), zu einem weissen Kasten.
     *
     * @return array<string, mixed>|null
     */
    public function bildAnalyse(string $png): ?array
    {
        $info = @getimagesizefromstring($png);
        if ($info === false || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $bild = @imagecreatefromstring($png);
        if ($bild === false) {
            return null;
        }
        $w = imagesx($bild);
        $h = imagesy($bild);
        $schritt = max(1, (int) floor(max($w, $h) / 200));
        $gesamt = $deckend = $halb = 0;
        $farben = [];
        $stempelFarbe = null;
        for ($py = 0; $py < $h; $py += $schritt) {
            for ($px = 0; $px < $w; $px += $schritt) {
                $c = imagecolorat($bild, $px, $py);
                $a = ($c >> 24) & 0x7F;
                $gesamt++;
                if ($a <= 7) {
                    $deckend++;
                    // grob quantisiert: Kantenglaettung soll keine "Farbe" sein
                    $farben[(($c >> 16) & 0xC0).'-'.(($c >> 8) & 0xC0).'-'.($c & 0xC0)] = true;
                } elseif ($a < 120) {
                    $halb++;
                }
            }
        }
        // Dieselbe Regel wie PdfStamper::addSignatureImage (erstes Pixel mit Deckung > 200/255)
        for ($py = 0; $py < $h && $stempelFarbe === null; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $c = imagecolorat($bild, $px, $py);
                $a = ($c >> 24) & 0x7F;
                if ((int) round((127 - $a) * 255 / 127) > 200) {
                    $stempelFarbe = sprintf('#%02x%02x%02x', ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
                    break;
                }
            }
        }
        imagedestroy($bild);

        return [
            'breite' => $w,
            'hoehe' => $h,
            'bytes' => strlen($png),
            'sha256' => hash('sha256', $png),
            'deckend_anteil' => round($deckend / $gesamt, 4),
            'halbdeckend_anteil' => round($halb / $gesamt, 4),
            'farben_deckend' => count($farben),
            'stempel_farbe' => $stempelFarbe,
            'stempel_farbe_hell' => $stempelFarbe !== null && $this->helligkeit($stempelFarbe) > 215,
        ];
    }

    private function helligkeit(string $hex): float
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    /**
     * Rendert dieselbe Seite aus Original und Ergebnis.
     *
     * @return array<string, mixed>
     */
    private function renderPaar(string $original, string $signiert, int $seite): array
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
                $process = new Process([
                    (string) config('services.ocr.pdftoppm_binary', 'pdftoppm'),
                    '-png', '-r', (string) self::DPI, '-f', (string) $seite, '-l', (string) $seite,
                    '-singlefile', $dir.'/'.$art.'.pdf', $dir.'/'.$art,
                ]);
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
     * sind Anteile der ANZEIGE-Seite (nach /Rotate, bezogen auf die
     * MediaBox) - pdftoppm rendert ohne -cropbox genau diese Flaeche.
     *
     * @param  array<string, mixed>  $paar
     * @return array<string, mixed>
     */
    private function sichtbarkeit(array $paar, array $box): array
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
     * @param  array<string, mixed>  $seite
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function bildBereich(SignatureField $field, array $seite): array
    {
        $box = [(float) $field->pos_x, (float) $field->pos_y, (float) $field->width, (float) $field->height];
        if (! $field->isCompany()) {
            return $box;
        }
        $hoehePt = $box[3] * $seite['anzeige'][1];
        $zeile = Firmensignatur::name() === '' ? 0.0 : min(12.0, max(7.0, $hoehePt * 0.22));
        if ($hoehePt >= 26.0 && $zeile > 0.0) {
            $box[3] = ($hoehePt - $zeile * 1.45) / $seite['anzeige'][1];
        }

        return $box;
    }

    /**
     * Letzte Logzeilen, die diese Anfrage nennen. E-Mail-Adressen werden
     * vorsichtshalber unkenntlich gemacht.
     *
     * @return list<string>
     */
    private function logZeilen(string $id): array
    {
        $treffer = [];
        foreach (glob(storage_path('logs/*.log')) ?: [] as $datei) {
            $h = @fopen($datei, 'r');
            if ($h === false) {
                continue;
            }
            while (($zeile = fgets($h)) !== false) {
                if (str_contains($zeile, $id)) {
                    $treffer[] = basename($datei).': '.$this->ohnePersonendaten(mb_substr(trim($zeile), 0, 400));
                    if (count($treffer) > 40) {
                        array_shift($treffer);
                    }
                }
            }
            fclose($h);
        }

        return array_slice($treffer, -10);
    }

    private function ohnePersonendaten(string $text): string
    {
        return (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-mail]', $text);
    }

    /**
     * Kandidaten fuer den Bestandslauf: alles, worin ueberhaupt etwas
     * gestempelt werden sollte.
     *
     * @return Builder<SignatureRequest>
     */
    public function bestand()
    {
        return SignatureRequest::query()
            ->where(function ($q) {
                $q->where('status', SignatureStatus::COMPLETED)
                    ->orWhereNotNull('signed_path')
                    ->orWhereHas('fields', fn ($f) => $f->whereNotNull('filled_at')->orWhereNotNull('company_asset_id'));
            })
            ->orderBy('created_at');
    }
}
