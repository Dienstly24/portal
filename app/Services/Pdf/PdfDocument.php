<?php

namespace App\Services\Pdf;

/**
 * LESENDER Zugang zu einem vorhandenen PDF: welche Seiten gibt es, wie gross
 * sind sie, und wo steht welches Objekt.
 *
 * WARUM SELBST GEBAUT: gebraucht wird genau dieser Ausschnitt (Seitenbaum,
 * MediaBox, Objekt-Rohtext) - fuer das Signatur-Modul reicht er, und die
 * Alternative waere ein grosses Fremdpaket im Sicherheitsupdate-Pfad einer
 * Anwendung mit Kundendaten. smalot/pdfparser liegt zwar bereits vor, liest
 * aber TEXT; es kann keine Objektnummern und keine Seitengeometrie liefern,
 * und schreiben kann es gar nicht.
 *
 * ZWEI QUELLEN FUER OBJEKTE, und beide werden gebraucht:
 *  1. Normale Objekte "12 0 obj ... endobj" stehen im Klartext in der Datei.
 *  2. Seit PDF 1.5 liegen Woerterbuecher regelmaessig KOMPRIMIERT in einem
 *     Objekt-Strom (/Type /ObjStm). Wer nur (1) liest, findet bei modernen
 *     Dokumenten den Seitenbaum nicht - und das sind heute die meisten.
 *
 * Die Objekt-Tabelle entsteht durch einen Lauf ueber die DATEI, nicht ueber
 * die Querverweistabelle. Grund: eine kaputte oder ungewoehnliche xref ist
 * der haeufigste Defekt in freier Wildbahn, waehrend "N G obj" immer
 * dasteht. Bei mehrfach fortgeschriebenen Dateien gewinnt das SPAETERE
 * Vorkommen - genau das ist die juengste Fassung.
 */
final class PdfDocument
{
    /** @var array<int, string> Objektnummer => Rohtext zwischen "obj" und "endobj" */
    private array $objects = [];

    /** @var list<PdfPage>|null */
    private ?array $pages = null;

    private bool $objectStreamsScanned = false;

    private function __construct(private readonly string $raw)
    {
    }

    public static function open(string $raw): self
    {
        if (! str_starts_with(ltrim(substr($raw, 0, 1024)), '%PDF-')) {
            throw new PdfException('Die Datei beginnt nicht mit "%PDF-" und ist damit kein PDF.');
        }
        $doc = new self($raw);
        $doc->indexPlainObjects();

        return $doc;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /** @return list<PdfPage> */
    public function pages(): array
    {
        return $this->pages ??= $this->buildPageList();
    }

    public function pageCount(): int
    {
        return count($this->pages());
    }

    public function page(int $index): PdfPage
    {
        $pages = $this->pages();
        if (! isset($pages[$index])) {
            throw new PdfException('Seite '.($index + 1).' gibt es in diesem Dokument nicht.');
        }

        return $pages[$index];
    }

    public function maxObjectNumber(): int
    {
        $max = $this->objects === [] ? 0 : max(array_keys($this->objects));
        // Auch die Groessenangabe der letzten Querverweistabelle zaehlt: sie
        // kennt ggf. Nummern, die im Klartext nicht mehr vorkommen.
        if (preg_match_all('/\/Size\s+(\d+)/', $this->raw, $m)) {
            $max = max($max, max(array_map('intval', $m[1])) - 1);
        }

        return $max;
    }

    /** Byte-Offset der letzten Querverweistabelle (fuer /Prev der Fortschreibung). */
    public function startXref(): int
    {
        if (preg_match_all('/startxref\s+(\d+)/', $this->raw, $m)) {
            return (int) end($m[1]);
        }
        throw new PdfException('Das Dokument hat kein "startxref" - die Querverweistabelle fehlt.');
    }

    /** Nutzt das Dokument eine Querverweis-STROM-Tabelle (PDF 1.5+)? */
    public function usesXrefStream(): bool
    {
        $at = $this->startXref();
        $head = substr($this->raw, $at, 64);

        return ! preg_match('/^\s*xref\b/', $head);
    }

    /** Rohtext eines Objekts (ohne "N G obj"/"endobj"), oder null. */
    public function objectBody(int $number): ?string
    {
        if (isset($this->objects[$number])) {
            return $this->objects[$number];
        }
        if (! $this->objectStreamsScanned) {
            $this->indexObjectStreams();

            return $this->objects[$number] ?? null;
        }

        return null;
    }

    /** Woerterbuch eines Objekts als Schluessel => Rohwert. */
    public function dict(int $number): array
    {
        $body = $this->objectBody($number);

        return $body === null ? [] : PdfSyntax::dictEntries($body);
    }

    /** Folgt einer Referenz "12 0 R" bis zum Woerterbuch-Rohtext. */
    public function resolve(?string $value): ?string
    {
        $guard = 0;
        while ($value !== null && PdfSyntax::isReference($value) && $guard++ < 32) {
            $number = PdfSyntax::referenceNumber($value);
            $value = $number === null ? null : $this->objectBody($number);
        }

        return $value;
    }

    /** Rohwert aus dem Trailer bzw. aus dem Querverweis-Strom (/Root, /Info, /ID). */
    public function trailerValue(string $key): ?string
    {
        // Klassischer Trailer - das LETZTE Vorkommen ist das juengste.
        $found = null;
        $offset = 0;
        while (($at = strpos($this->raw, 'trailer', $offset)) !== false) {
            $dictStart = strpos($this->raw, '<<', $at);
            if ($dictStart !== false) {
                $dict = substr($this->raw, $dictStart, PdfSyntax::endOfComposite($this->raw, $dictStart) - $dictStart);
                $value = PdfSyntax::dictEntries($dict)[$key] ?? null;
                if ($value !== null) {
                    $found = $value;
                }
            }
            $offset = $at + 7;
        }
        if ($found !== null) {
            return $found;
        }

        // Querverweis-Strom: dieselben Angaben stehen in seinem Woerterbuch.
        foreach ($this->objects as $body) {
            if (str_contains($body, '/XRef')) {
                $value = PdfSyntax::dictEntries($body)[$key] ?? null;
                if ($value !== null) {
                    $found = $value;
                }
            }
        }

        return $found;
    }

    /**
     * Das Objekt, in dessen /Resources die Signatur-Bausteine eingetragen
     * werden muessen. Fehlt der Seite ein eigenes /Resources, ERBT sie es -
     * dann wird der naechste Vorfahr genommen, der eines hat. Der Seite
     * einfach ein neues zu geben waere schlimmer als kein Eintrag: sie
     * verloere damit die geerbten Schriften ihres eigenen Inhalts.
     *
     * @return array{object: int, inline: bool}
     */
    public function resourcesOwner(PdfPage $page): array
    {
        $number = $page->objectNumber;
        $guard = 0;
        while ($guard++ < 64) {
            $dict = $this->dict($number);
            $resources = $dict['Resources'] ?? null;
            if ($resources !== null) {
                if (PdfSyntax::isReference($resources)) {
                    $target = PdfSyntax::referenceNumber($resources);
                    if ($target !== null && $this->objectBody($target) !== null) {
                        return ['object' => $target, 'inline' => false];
                    }
                }

                return ['object' => $number, 'inline' => true];
            }
            $parent = $dict['Parent'] ?? null;
            $next = $parent === null ? null : PdfSyntax::referenceNumber($parent);
            if ($next === null || $next === $number) {
                break;
            }
            $number = $next;
        }

        // Gar kein /Resources im Baum: dann darf die Seite eines bekommen.
        return ['object' => $page->objectNumber, 'inline' => true];
    }

    /** Objektnummer des Seitenbaum-Wurzelknotens (/Pages) - fuer angehaengte Seiten. */
    public function pagesRootNumber(): int
    {
        $catalog = $this->catalogNumber();
        $pages = $this->dict($catalog)['Pages'] ?? null;
        $number = $pages === null ? null : PdfSyntax::referenceNumber($pages);
        if ($number === null) {
            throw new PdfException('Der Seitenbaum (/Pages) des Dokuments ist nicht auffindbar.');
        }

        return $number;
    }

    // ---------------------------------------------------------------- intern

    private function indexPlainObjects(): void
    {
        $len = strlen($this->raw);
        $offset = 0;
        while (preg_match('/(\d+)[\x00\t\n\x0C\r ]+(\d+)[\x00\t\n\x0C\r ]+obj\b/', $this->raw, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $at = (int) $m[0][1];
            $number = (int) $m[1][0];
            $bodyStart = $at + strlen($m[0][0]);
            $bodyEnd = $this->findObjectEnd($bodyStart);
            $this->objects[$number] = substr($this->raw, $bodyStart, $bodyEnd - $bodyStart);
            $offset = max($bodyEnd, $at + strlen($m[0][0]));
            if ($offset >= $len) {
                break;
            }
        }
        if ($this->objects === []) {
            throw new PdfException('Das Dokument enthaelt keine lesbaren PDF-Objekte.');
        }
    }

    /**
     * Ende eines Objekts. Ein "endobj" kann auch INNERHALB eines Datenstroms
     * stehen (Bilder sind Binaerdaten) - deshalb wird ein Strom zuerst
     * anhand seiner /Length uebersprungen und erst danach gesucht.
     */
    private function findObjectEnd(int $bodyStart): int
    {
        $len = strlen($this->raw);
        $streamAt = strpos($this->raw, 'stream', $bodyStart);
        $endObjAt = strpos($this->raw, 'endobj', $bodyStart);
        if ($endObjAt === false) {
            $endObjAt = $len;
        }

        if ($streamAt !== false && $streamAt < $endObjAt) {
            $dict = substr($this->raw, $bodyStart, $streamAt - $bodyStart);
            $length = PdfSyntax::dictEntries($dict)['Length'] ?? null;
            $dataStart = $streamAt + 6;
            if (($this->raw[$dataStart] ?? '') === "\r") {
                $dataStart++;
            }
            if (($this->raw[$dataStart] ?? '') === "\n") {
                $dataStart++;
            }
            $after = null;
            if ($length !== null && preg_match('/^\d+$/', trim($length))) {
                $after = $dataStart + (int) $length;
            }
            // Eine indirekte oder falsche /Length ist haeufig; dann gilt das
            // naechste "endstream" - besser gemessen als geglaubt.
            $endStream = strpos($this->raw, 'endstream', $after ?? $dataStart);
            if ($after === null || $endStream === false || $endStream < $after) {
                $after = $endStream === false ? $len : $endStream;
            }
            $endObjAt = strpos($this->raw, 'endobj', $after);
            if ($endObjAt === false) {
                $endObjAt = $len;
            }
        }

        return $endObjAt;
    }

    /** Objekte aus komprimierten Objekt-Stroemen (/Type /ObjStm) nachtragen. */
    private function indexObjectStreams(): void
    {
        $this->objectStreamsScanned = true;
        foreach ($this->objects as $body) {
            if (! str_contains($body, '/ObjStm')) {
                continue;
            }
            $dict = PdfSyntax::dictEntries($body);
            if (($dict['Type'] ?? '') !== '/ObjStm') {
                continue;
            }
            $data = $this->streamData($body, $dict);
            if ($data === null) {
                continue;
            }
            $count = (int) ($dict['N'] ?? 0);
            $first = (int) ($dict['First'] ?? 0);
            $header = substr($data, 0, $first);
            if (! preg_match_all('/(\d+)\s+(\d+)/', $header, $m, PREG_SET_ORDER)) {
                continue;
            }
            $entries = array_slice($m, 0, $count);
            foreach ($entries as $i => $entry) {
                $number = (int) $entry[1];
                if (isset($this->objects[$number])) {
                    continue; // Klartext-Fassung ist die juengere.
                }
                $start = $first + (int) $entry[2];
                $end = isset($entries[$i + 1]) ? $first + (int) $entries[$i + 1][2] : strlen($data);
                $this->objects[$number] = substr($data, $start, $end - $start);
            }
        }
    }

    /** Entpackter Inhalt eines Datenstroms; null, wenn das Filterverfahren fremd ist. */
    private function streamData(string $body, array $dict): ?string
    {
        $streamAt = strpos($body, 'stream');
        if ($streamAt === false) {
            return null;
        }
        $start = $streamAt + 6;
        if (($body[$start] ?? '') === "\r") {
            $start++;
        }
        if (($body[$start] ?? '') === "\n") {
            $start++;
        }
        $end = strpos($body, 'endstream', $start);
        $data = substr($body, $start, ($end === false ? strlen($body) : $end) - $start);

        $filter = trim($dict['Filter'] ?? '');
        if ($filter === '') {
            return $data;
        }
        if ($filter !== '/FlateDecode' && $filter !== '[/FlateDecode]') {
            return null; // LZW/RunLength kommen bei Objekt-Stroemen praktisch nicht vor.
        }
        $inflated = @gzuncompress($data);
        if ($inflated === false) {
            $inflated = @gzinflate(substr($data, 2));
        }

        return $inflated === false ? null : $inflated;
    }

    private function catalogNumber(): int
    {
        $root = $this->trailerValue('Root');
        $number = $root === null ? null : PdfSyntax::referenceNumber($root);
        if ($number !== null && $this->objectBody($number) !== null) {
            return $number;
        }
        // Notbehelf: das Objekt mit /Type /Catalog suchen.
        $this->indexObjectStreams();
        foreach ($this->objects as $num => $body) {
            if (str_contains($body, '/Catalog')) {
                return $num;
            }
        }
        throw new PdfException('Das Dokument hat keinen lesbaren Katalog (/Root).');
    }

    /** @return list<PdfPage> */
    private function buildPageList(): array
    {
        $root = $this->pagesRootNumber();
        $pages = [];
        $this->collectPages($root, [], $pages, 0);
        if ($pages === []) {
            throw new PdfException('Das Dokument enthaelt keine lesbaren Seiten.');
        }

        return $pages;
    }

    /**
     * @param  array<string, string>  $inherited  Vererbbare Angaben des Vorfahren
     * @param  list<PdfPage>  $out
     */
    private function collectPages(int $number, array $inherited, array &$out, int $depth): void
    {
        if ($depth > 64 || count($out) > 5000) {
            return;
        }
        $dict = $this->dict($number);
        foreach (['MediaBox', 'Rotate', 'CropBox'] as $key) {
            if (isset($dict[$key])) {
                $inherited[$key] = $dict[$key];
            }
        }

        $type = trim($dict['Type'] ?? '');
        $kids = $dict['Kids'] ?? null;

        if ($type === '/Page' || ($kids === null && $type !== '/Pages')) {
            $box = PdfSyntax::numbers($this->resolve($inherited['MediaBox'] ?? '') ?? '');
            if (count($box) !== 4) {
                $box = [0.0, 0.0, 595.28, 841.89]; // A4 - eine Seite ohne MediaBox ist selten und A4 die einzig sinnvolle Annahme
            }
            $rotate = (int) trim($this->resolve($inherited['Rotate'] ?? '0') ?? '0');
            $rotate = ((($rotate % 360) + 360) % 360);
            $rotate -= $rotate % 90;
            $out[] = new PdfPage(count($out), $number, [
                min($box[0], $box[2]), min($box[1], $box[3]),
                max($box[0], $box[2]), max($box[1], $box[3]),
            ], $rotate);

            return;
        }

        $kidsRaw = $this->resolve($kids) ?? '';
        preg_match_all('/(\d+)\s+\d+\s+R/', $kidsRaw, $m);
        foreach ($m[1] as $kid) {
            $this->collectPages((int) $kid, $inherited, $out, $depth + 1);
        }
    }
}
