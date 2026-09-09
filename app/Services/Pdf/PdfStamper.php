<?php

namespace App\Services\Pdf;

/**
 * Schreibt Unterschriften, Namen, Daten und Kreuze in ein vorhandenes PDF -
 * als FORTSCHREIBUNG (incremental update).
 *
 * DAS IST DER KERN DER BEWEISKRAFT: die Originaldatei bleibt Byte fuer Byte
 * erhalten, das Ergebnis ist "Original + Anhang". Wer beide Dateien
 * nebeneinanderlegt, sieht, dass die ersten N Bytes identisch sind - eine
 * Aenderung am urspruenglichen Inhalt ist damit ausgeschlossen und nicht
 * bloss behauptet. Ein Neuaufbau (Seiten zu Bildern rendern und neu
 * zusammensetzen) waere einfacher zu programmieren, verloere aber Textebene,
 * Struktur und genau diesen Nachweis.
 *
 * WAS ANGEHAENGT WIRD:
 *  - je Unterschriftsbild zwei Objekte (1x1-Pixel-Farbe + Alphakanal als
 *    /SMask). Warum so: der Alphakanal traegt die volle Aufloesung der
 *    Handschrift, die Farbe braucht dafuer genau drei Bytes. Ein
 *    weiss hinterlegtes JPEG waere ein weisser Kasten ueber dem Vertragstext.
 *  - je bestempelter Seite ein "q" davor und die Stempel-Anweisungen
 *    dahinter. Das "q"/"Q"-Paar ist Pflicht: liesse der urspruengliche
 *    Seiteninhalt den Grafikzustand veraendert zurueck (Drehung, Skalierung),
 *    landete die Unterschrift sonst irgendwo.
 *  - eine Protokollseite mit den Angaben zu jedem Unterzeichner.
 *
 * Die Seitendrehung (/Rotate) wird mitgerechnet. Ohne sie stuende die
 * Unterschrift auf jedem quer eingescannten Vertrag um 90 Grad verdreht am
 * falschen Rand - und zwar erst im fertigen Dokument, nie im Editor.
 */
final class PdfStamper
{
    /** @var list<PdfStamp> */
    private array $stamps = [];

    /** @var array<int, string> Objektnummer => neuer Rohtext */
    private array $rewrites = [];

    /** @var array<int, string> Objektnummer => Rohtext neuer Objekte */
    private array $additions = [];

    private int $nextObject;

    private ?int $fontObject = null;

    /** @var list<array{title: string, lines: list<string>}> */
    private array $protocolSections = [];

    private string $protocolTitle = 'Signaturprotokoll';

    public function __construct(private readonly PdfDocument $document)
    {
        $this->nextObject = $this->document->maxObjectNumber() + 1;
    }

    public function add(PdfStamp $stamp): self
    {
        $this->stamps[] = $stamp;

        return $this;
    }

    /**
     * Haengt eine Protokollseite an. Sie macht das unterschriebene PDF fuer
     * sich allein aussagekraeftig: wer es weitergibt, gibt den Nachweis mit.
     *
     * @param  list<array{title: string, lines: list<string>}>  $sections
     */
    public function withProtocolPage(string $title, array $sections): self
    {
        $this->protocolTitle = $title;
        $this->protocolSections = $sections;

        return $this;
    }

    public function build(): string
    {
        $byPage = [];
        foreach ($this->stamps as $stamp) {
            $byPage[$stamp->pageIndex][] = $stamp;
        }
        foreach ($byPage as $pageIndex => $stamps) {
            $this->stampPage($this->document->page($pageIndex), $stamps);
        }
        if ($this->protocolSections !== []) {
            $this->appendProtocolPage();
        }
        if ($this->rewrites === [] && $this->additions === []) {
            return $this->document->raw();
        }

        return $this->writeIncrementalUpdate();
    }

    // -------------------------------------------------------------- Stempeln

    /** @param  list<PdfStamp>  $stamps */
    private function stampPage(PdfPage $page, array $stamps): void
    {
        $xobjects = [];
        $ops = ['q'];

        $box = $page->mediaBox;
        if ($box[0] != 0.0 || $box[1] != 0.0) {
            $ops[] = '1 0 0 1 '.PdfSyntax::num($box[0]).' '.PdfSyntax::num($box[1]).' cm';
        }
        $rotation = $this->rotationMatrix($page);
        if ($rotation !== null) {
            $ops[] = $rotation;
        }
        $ops[] = '0 g 0 G';

        $needsFont = false;
        foreach ($stamps as $i => $stamp) {
            // Anzeige-Koordinaten (Ursprung oben links) in PDF-Koordinaten
            // (Ursprung unten links) umrechnen.
            $x = $stamp->x;
            $y = $page->displayHeight() - $stamp->y - $stamp->height;

            if ($stamp->type === PdfStamp::TYPE_IMAGE && $stamp->png !== null) {
                $name = 'D24Sig'.$page->objectNumber.'x'.$i;
                $imageObject = $this->addSignatureImage($stamp->png);
                if ($imageObject === null) {
                    continue;
                }
                $xobjects[$name] = $imageObject;
                $ops[] = 'q '.PdfSyntax::num($stamp->width).' 0 0 '.PdfSyntax::num($stamp->height)
                    .' '.PdfSyntax::num($x).' '.PdfSyntax::num($y).' cm /'.$name.' Do Q';
            } elseif ($stamp->type === PdfStamp::TYPE_TEXT && $stamp->text !== null) {
                $needsFont = true;
                // Grundlinie: die Schrift sitzt knapp ueber der Feldunterkante.
                $baseline = $y + max(1.0, $stamp->height * 0.25);
                $ops[] = 'BT /D24Font '.PdfSyntax::num($stamp->fontSize).' Tf '
                    .PdfSyntax::num($x).' '.PdfSyntax::num($baseline).' Td '
                    .PdfSyntax::escapeString($stamp->text).' Tj ET';
            } elseif ($stamp->type === PdfStamp::TYPE_BOX) {
                $ops[] = '0.8 w '.PdfSyntax::num($x).' '.PdfSyntax::num($y).' '
                    .PdfSyntax::num($stamp->width).' '.PdfSyntax::num($stamp->height).' re S';
            }
        }
        $ops[] = 'Q';

        $fonts = [];
        if ($needsFont) {
            $fonts['D24Font'] = $this->fontObject();
        }

        $pre = $this->newObject("<< /Length 2 >>\nstream\nq\nendstream");
        $content = implode("\n", $ops)."\n";
        $post = $this->newObject('<< /Length '.strlen("Q\n".$content).' >>'."\nstream\nQ\n".$content.'endstream');

        $body = $this->bodyOf($page->objectNumber);
        $contents = PdfSyntax::dictEntries($body)['Contents'] ?? null;
        $list = $contents === null ? [] : ($this->contentRefs($contents));
        $newContents = '['.implode(' ', array_merge([$pre.' 0 R'], $list, [$post.' 0 R'])).']';
        $body = PdfSyntax::replaceEntry($body, 'Contents', $newContents);
        $this->rewrites[$page->objectNumber] = $body;

        $this->registerResources($page, $xobjects, $fonts);
    }

    /** @return list<string> Referenzen der bisherigen Inhalts-Stroeme */
    private function contentRefs(string $contents): array
    {
        preg_match_all('/(\d+)\s+(\d+)\s+R/', $contents, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => $x[1].' '.$x[2].' R', $m);
    }

    /**
     * Traegt die neuen Bausteine in das /Resources ein, das FUER DIESE SEITE
     * gilt - ggf. das geerbte des Vorfahren. Ergaenzt wird nur; ein
     * vorhandener Eintrag wird nie ueberschrieben.
     *
     * @param  array<string, int>  $xobjects
     * @param  array<string, int>  $fonts
     */
    private function registerResources(PdfPage $page, array $xobjects, array $fonts): void
    {
        if ($xobjects === [] && $fonts === []) {
            return;
        }
        $owner = $this->document->resourcesOwner($page);
        $ownerNumber = $owner['object'];
        $body = $this->bodyOf($ownerNumber);

        $resources = PdfSyntax::dictEntries($body)['Resources'] ?? null;
        if ($resources !== null && PdfSyntax::isReference($resources)) {
            $ref = PdfSyntax::referenceNumber($resources);
            if ($ref !== null) {
                $this->rewrites[$ref] = $this->mergeResourceDict($this->bodyOf($ref), $xobjects, $fonts);

                return;
            }
        }
        if ($resources === null) {
            $body = PdfSyntax::replaceEntry($body, 'Resources', $this->mergeResourceDict('<< >>', $xobjects, $fonts));
            $this->rewrites[$ownerNumber] = $body;

            return;
        }
        $merged = $this->mergeResourceDict($resources, $xobjects, $fonts);
        $this->rewrites[$ownerNumber] = PdfSyntax::replaceEntry($body, 'Resources', $merged);
    }

    /**
     * @param  array<string, int>  $xobjects
     * @param  array<string, int>  $fonts
     */
    private function mergeResourceDict(string $dict, array $xobjects, array $fonts): string
    {
        foreach ([['XObject', $xobjects], ['Font', $fonts]] as [$key, $entries]) {
            if ($entries === []) {
                continue;
            }
            $text = implode(' ', array_map(fn ($name, $obj) => '/'.$name.' '.$obj.' 0 R', array_keys($entries), $entries));
            $current = PdfSyntax::dictEntries($dict)[$key] ?? null;
            if ($current === null) {
                $dict = PdfSyntax::replaceEntry($dict, $key, '<< '.$text.' >>');
            } elseif (PdfSyntax::isReference($current)) {
                $ref = PdfSyntax::referenceNumber($current);
                if ($ref !== null) {
                    $this->rewrites[$ref] = PdfSyntax::insertEntries($this->bodyOf($ref), $text);
                }
            } else {
                $dict = PdfSyntax::replaceEntry($dict, $key, PdfSyntax::insertEntries($current, $text));
            }
        }

        return $dict;
    }

    /**
     * Drehung der Seite als Transformationsmatrix, damit alle Stempel in
     * ANZEIGE-Koordinaten angegeben werden koennen.
     */
    private function rotationMatrix(PdfPage $page): ?string
    {
        $w = PdfSyntax::num($page->width());
        $h = PdfSyntax::num($page->height());

        return match ($page->rotate) {
            90 => '0 1 -1 0 '.$w.' 0 cm',
            180 => '-1 0 0 -1 '.$w.' '.$h.' cm',
            270 => '0 -1 1 0 0 '.$h.' cm',
            default => null,
        };
    }

    // ---------------------------------------------------------------- Bilder

    /**
     * Unterschriftsbild als Objektpaar: eine 1x1-Pixel-Farbflaeche mit dem
     * Alphakanal der Handschrift als /SMask. Der Alphakanal traegt die
     * Aufloesung, die Farbflaeche drei Bytes - und die Schrift bleibt
     * durchscheinend, ueberdeckt also keinen Vertragstext.
     *
     * @return int|null Objektnummer des Bildes; null, wenn das PNG unlesbar ist
     */
    private function addSignatureImage(string $png): ?int
    {
        $image = @imagecreatefromstring($png);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1 || $width * $height > 4_000_000) {
            imagedestroy($image);

            return null;
        }

        $alpha = '';
        $ink = [0, 0, 0];
        $inkFound = false;
        for ($y = 0; $y < $height; $y++) {
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $a = ($rgba >> 24) & 0x7F;      // GD: 0 = deckend, 127 = klar
                $opacity = (int) round((127 - $a) * 255 / 127);
                if (! $inkFound && $opacity > 200) {
                    $ink = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
                    $inkFound = true;
                }
                $row .= chr($opacity);
            }
            $alpha .= $row;
        }
        imagedestroy($image);

        $smaskData = gzcompress($alpha, 6);
        $smask = $this->newObject(
            '<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height
            .' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($smaskData).' >>'
            ."\nstream\n".$smaskData."\nendstream"
        );

        $color = chr($ink[0]).chr($ink[1]).chr($ink[2]);

        return $this->newObject(
            '<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB'
            .' /BitsPerComponent 8 /SMask '.$smask.' 0 R /Length 3 >>'
            ."\nstream\n".$color."\nendstream"
        );
    }

    private function fontObject(): int
    {
        return $this->fontObject ??= $this->newObject(
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'
        );
    }

    // --------------------------------------------------------- Protokollseite

    private function appendProtocolPage(): void
    {
        $width = 595.28;
        $height = 841.89;
        $margin = 56.0;
        $y = $height - $margin;

        $ops = ['0 g'];
        $write = function (string $text, float $size, float $gap) use (&$ops, &$y, $margin): void {
            $y -= $gap;
            $ops[] = 'BT /D24Font '.PdfSyntax::num($size).' Tf '
                .PdfSyntax::num($margin).' '.PdfSyntax::num($y).' Td '
                .PdfSyntax::escapeString($text).' Tj ET';
        };

        $write($this->protocolTitle, 16, 20);
        $ops[] = '0.6 w '.PdfSyntax::num($margin).' '.PdfSyntax::num($y - 8).' m '
            .PdfSyntax::num($width - $margin).' '.PdfSyntax::num($y - 8).' l S';
        $y -= 8;

        foreach ($this->protocolSections as $section) {
            $write($section['title'], 11, 26);
            foreach ($section['lines'] as $line) {
                if ($y < $margin + 24) {
                    break 2; // Eine zweite Protokollseite waere Zierde; das Vollprotokoll steht im Audit.
                }
                $write($line, 9, 13);
            }
        }

        $content = implode("\n", $ops)."\n";
        $contentObject = $this->newObject('<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream');
        $pagesRoot = $this->document->pagesRootNumber();
        $pageObject = $this->newObject(
            '<< /Type /Page /Parent '.$pagesRoot.' 0 R /MediaBox [0 0 '.PdfSyntax::num($width).' '.PdfSyntax::num($height).']'
            .' /Resources << /Font << /D24Font '.$this->fontObject().' 0 R >> >>'
            .' /Contents '.$contentObject.' 0 R >>'
        );

        $rootBody = $this->bodyOf($pagesRoot);
        $entries = PdfSyntax::dictEntries($rootBody);
        $kids = $entries['Kids'] ?? '[]';
        if (PdfSyntax::isReference($kids)) {
            $ref = PdfSyntax::referenceNumber($kids);
            if ($ref !== null) {
                $this->rewrites[$ref] = PdfSyntax::appendToArray($this->bodyOf($ref), $pageObject.' 0 R');
            }
        } else {
            $rootBody = PdfSyntax::replaceEntry($rootBody, 'Kids', PdfSyntax::appendToArray($kids, $pageObject.' 0 R'));
        }
        $count = (int) trim($entries['Count'] ?? '0');
        $rootBody = PdfSyntax::replaceEntry($rootBody, 'Count', (string) ($count + 1));
        $this->rewrites[$pagesRoot] = $rootBody;
    }

    // ------------------------------------------------------------- Schreiben

    private function bodyOf(int $number): string
    {
        if (isset($this->rewrites[$number])) {
            return $this->rewrites[$number];
        }
        if (isset($this->additions[$number])) {
            return $this->additions[$number];
        }
        $body = $this->document->objectBody($number);
        if ($body === null) {
            throw new PdfException('Objekt '.$number.' des PDF ist nicht lesbar.');
        }

        return $body;
    }

    private function newObject(string $body): int
    {
        $number = $this->nextObject++;
        $this->additions[$number] = $body;

        return $number;
    }

    private function writeIncrementalUpdate(): string
    {
        $out = $this->document->raw();
        // Die Fortschreibung muss auf einer neuen Zeile beginnen, sonst
        // klebt "1 0 obj" am "%%EOF" der Vorfassung.
        if (! str_ends_with($out, "\n")) {
            $out .= "\n";
        }

        $objects = $this->rewrites + $this->additions;
        ksort($objects);

        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= $number." 0 obj\n".trim($body)."\nendobj\n";
        }

        $previous = $this->document->startXref();
        $size = ($objects === [] ? 0 : max(array_keys($objects))) + 1;
        $size = max($size, $this->document->maxObjectNumber() + 1);

        $startXref = strlen($out);
        $out .= $this->document->usesXrefStream()
            ? $this->xrefStream($offsets, $size, $previous, $startXref)
            : $this->xrefTable($offsets, $size, $previous);

        return $out."startxref\n".$startXref."\n%%EOF\n";
    }

    /** @param  array<int, int>  $offsets */
    private function xrefTable(array $offsets, int $size, int $previous): string
    {
        $out = "xref\n";
        foreach ($this->contiguousRuns(array_keys($offsets)) as $run) {
            $out .= $run[0].' '.count($run)."\n";
            foreach ($run as $number) {
                $out .= sprintf("%010d %05d n \n", $offsets[$number], 0);
            }
        }

        return $out.'trailer'."\n".$this->trailerDict($size, $previous)."\n";
    }

    /** @param  array<int, int>  $offsets */
    private function xrefStream(array $offsets, int $size, int $previous, int $selfOffset): string
    {
        $number = $this->nextObject++;
        $offsets[$number] = $selfOffset;
        ksort($offsets);
        $size = max($size, $number + 1);

        $index = [];
        $data = '';
        foreach ($this->contiguousRuns(array_keys($offsets)) as $run) {
            $index[] = $run[0].' '.count($run);
            foreach ($run as $object) {
                $data .= chr(1).pack('N', $offsets[$object]).pack('n', 0);
            }
        }

        $dict = '<< /Type /XRef /Size '.$size.' /Index ['.implode(' ', $index).']'
            .' /W [1 4 2] /Prev '.$previous.' /Length '.strlen($data);
        foreach (['Root', 'Info', 'ID'] as $key) {
            $value = $this->document->trailerValue($key);
            if ($value !== null) {
                $dict .= ' /'.$key.' '.$value;
            }
        }
        $dict .= ' >>';

        return $number." 0 obj\n".$dict."\nstream\n".$data."\nendstream\nendobj\n";
    }

    private function trailerDict(int $size, int $previous): string
    {
        $dict = '<< /Size '.$size.' /Prev '.$previous;
        foreach (['Root', 'Info', 'ID', 'Encrypt'] as $key) {
            $value = $this->document->trailerValue($key);
            if ($value !== null) {
                $dict .= ' /'.$key.' '.$value;
            }
        }

        return $dict.' >>';
    }

    /**
     * Zusammenhaengende Nummernbloecke - die Querverweistabelle verlangt sie
     * abschnittsweise.
     *
     * @param  list<int>  $numbers
     * @return list<list<int>>
     */
    private function contiguousRuns(array $numbers): array
    {
        sort($numbers);
        $runs = [];
        $current = [];
        foreach ($numbers as $number) {
            if ($current !== [] && $number !== end($current) + 1) {
                $runs[] = $current;
                $current = [];
            }
            $current[] = $number;
        }
        if ($current !== []) {
            $runs[] = $current;
        }

        return $runs;
    }
}
