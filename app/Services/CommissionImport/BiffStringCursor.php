<?php

namespace App\Services\CommissionImport;

/**
 * Lesezeiger ueber die Bruchstuecke der BIFF8-Zeichenkettentabelle.
 *
 * Er existiert, weil eine Zeichenkette MITTEN in einem Bruchstueck enden
 * kann: die Fortsetzung beginnt dann mit einem neuen Kennzeichen-Byte, das
 * angibt, ob der Rest in 8- oder 16-Bit-Zeichen kommt. Diese eine Regel in
 * den Leser einzubauen, haette dessen Zellschleife unlesbar gemacht - hier
 * steht sie allein und ist pruefbar.
 */
class BiffStringCursor
{
    /** @var array<int,string> */
    private array $segments;
    private int $segment = 0;
    private int $offset = 0;

    /** @param array<int,string> $segments */
    public function __construct(array $segments)
    {
        $this->segments = array_values($segments);
    }

    public function skip(int $bytes): void
    {
        $this->offset += $bytes;
        $this->normalize();
    }

    public function uint16(): int
    {
        $bytes = $this->take(2);
        return $bytes === null ? 0 : (unpack('v', $bytes)[1] ?? 0);
    }

    public function uint32(): int
    {
        $bytes = $this->take(4);
        return $bytes === null ? 0 : (unpack('V', $bytes)[1] ?? 0);
    }

    /**
     * Eine Zeichenkette lesen. null heisst: die Daten sind zu Ende - der
     * Aufrufer bricht dann ab, statt Unsinn zu erzeugen.
     */
    public function string(): ?string
    {
        if ($this->atEnd()) {
            return null;
        }
        $length = $this->uint16();
        $flagByte = $this->take(1);
        if ($flagByte === null) {
            return null;
        }
        $flags = ord($flagByte);
        $wide = ($flags & 0x01) !== 0;
        $rich = ($flags & 0x08) !== 0;
        $extended = ($flags & 0x04) !== 0;

        $runs = $rich ? $this->uint16() : 0;
        $extendedBytes = $extended ? $this->uint32() : 0;

        $text = '';
        $remaining = $length;
        while ($remaining > 0) {
            if ($this->atEnd()) {
                return $text === '' ? null : $text;
            }
            $available = strlen($this->segments[$this->segment]) - $this->offset;
            if ($available <= 0) {
                // Bruchstelle: das naechste Bruchstueck beginnt mit einem
                // eigenen Kennzeichen-Byte - erst danach kommen Zeichen.
                $this->segment++;
                $this->offset = 0;
                if ($this->atEnd()) {
                    return $text;
                }
                $flags = ord($this->segments[$this->segment][0]);
                $wide = ($flags & 0x01) !== 0;
                $this->offset = 1;
                continue;
            }
            $charSize = $wide ? 2 : 1;
            $take = min($remaining * $charSize, $available - ($available % $charSize ?: 0));
            $take = max($take, $charSize);
            $bytes = substr($this->segments[$this->segment], $this->offset, $take);
            $this->offset += strlen($bytes);
            $chars = intdiv(strlen($bytes), $charSize);
            $remaining -= $chars;
            $text .= $wide
                ? (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE')
                : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        // Anhaengende Formatlauf-/Zusatzdaten ueberspringen - sie gehoeren
        // zur Darstellung, nicht zum Text.
        $this->skip($runs * 4 + $extendedBytes);

        return $text;
    }

    private function atEnd(): bool
    {
        $this->normalize();
        return ! isset($this->segments[$this->segment]);
    }

    private function take(int $bytes): ?string
    {
        $out = '';
        while (strlen($out) < $bytes) {
            if ($this->atEnd()) {
                return null;
            }
            $chunk = substr($this->segments[$this->segment], $this->offset, $bytes - strlen($out));
            if ($chunk === '') {
                $this->segment++;
                $this->offset = 0;
                continue;
            }
            $this->offset += strlen($chunk);
            $out .= $chunk;
        }
        return $out;
    }

    private function normalize(): void
    {
        while (isset($this->segments[$this->segment]) && $this->offset >= strlen($this->segments[$this->segment])) {
            $this->offset -= strlen($this->segments[$this->segment]);
            $this->segment++;
        }
    }
}
