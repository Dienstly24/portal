<?php

namespace App\Services\CommissionImport;

/**
 * Minimal-Leser fuer das OLE-Verbunddokument (Compound File Binary Format).
 *
 * Eine alte .xls-Datei ist kein ZIP, sondern ein winziges Dateisystem in
 * einer Datei: Sektoren, eine Belegungstabelle (FAT) und ein Verzeichnis.
 * Diese Klasse holt daraus genau EINEN Datenstrom heraus - fuer Excel den
 * Strom "Workbook". Sie versteht bewusst nichts von Excel selbst; die
 * Trennung haelt den BIFF-Leser darueber lesbar.
 *
 * Nur LESEND und ohne jede Ausfuehrung: Makros (VBA) liegen in eigenen
 * Stroemen und werden hier nie angefasst.
 */
class OleCompoundFile
{
    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END_OF_CHAIN = 0xFFFFFFFE;
    private const FREE_SECTOR = 0xFFFFFFFF;

    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;
    private int $miniCutoff;

    /** @var array<int,int> */
    private array $fat = [];
    /** @var array<int,int> */
    private array $miniFat = [];
    private string $miniStream = '';

    /** @var array<string,array{start:int,size:int,mini:bool}> Name => Eintrag */
    private array $entries = [];

    public function __construct(string $data)
    {
        if (! str_starts_with($data, self::SIGNATURE)) {
            throw new \RuntimeException('Die Datei ist keine gültige .xls-Datei (falsche Signatur).');
        }
        $this->data = $data;

        $this->sectorSize = 1 << $this->uint16(0x1E);
        $this->miniSectorSize = 1 << $this->uint16(0x20);
        $this->miniCutoff = $this->uint32(0x38);
        if ($this->sectorSize < 64 || $this->sectorSize > 1048576) {
            throw new \RuntimeException('Die .xls-Datei hat eine unbekannte Sektorgröße.');
        }

        $this->readFat();
        $this->readDirectory();
    }

    public static function fromPath(string $path): self
    {
        return new self((string) file_get_contents($path));
    }

    /** Gibt es diesen Datenstrom? (Namen sind gross-/kleinschreibungsrelevant) */
    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return array<int,string> alle Stromnamen (fuer Fehlermeldungen) */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function stream(string $name): string
    {
        if (! isset($this->entries[$name])) {
            throw new \RuntimeException('Der Datenstrom "'.$name.'" fehlt in der Datei.');
        }
        $entry = $this->entries[$name];
        return $entry['mini']
            ? substr($this->readChain($this->miniFat, $this->miniStream, $entry['start'], $this->miniSectorSize), 0, $entry['size'])
            : substr($this->readSectorChain($entry['start']), 0, $entry['size']);
    }

    /**
     * Die Belegungstabelle (FAT) zusammensetzen. Ihre eigenen Sektoren stehen
     * im DIFAT: die ersten 109 Eintraege im Dateikopf, der Rest in verketteten
     * DIFAT-Sektoren. Ohne diesen zweiten Teil bricht das Lesen bei Dateien
     * ab etwa 7 MB ab - genau dann, wenn ein Jahresexport gross genug wird.
     */
    private function readFat(): void
    {
        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $sector = $this->uint32(0x4C + $i * 4);
            if ($sector === self::FREE_SECTOR) {
                break;
            }
            $difat[] = $sector;
        }

        $next = $this->uint32(0x44);
        $guard = 0;
        $perSector = intdiv($this->sectorSize, 4) - 1;
        while ($next !== self::END_OF_CHAIN && $next !== self::FREE_SECTOR && $guard++ < 100000) {
            $offset = $this->sectorOffset($next);
            for ($i = 0; $i < $perSector; $i++) {
                $sector = $this->uint32($offset + $i * 4);
                if ($sector === self::FREE_SECTOR) {
                    continue;
                }
                $difat[] = $sector;
            }
            $next = $this->uint32($offset + $perSector * 4);
        }

        foreach ($difat as $fatSector) {
            $offset = $this->sectorOffset($fatSector);
            for ($i = 0, $n = intdiv($this->sectorSize, 4); $i < $n; $i++) {
                $this->fat[] = $this->uint32($offset + $i * 4);
            }
        }
    }

    /**
     * Verzeichnis lesen: Name, Startsektor und Groesse je Datenstrom.
     * Der WURZEL-Eintrag ist ein Sonderfall - er zeigt nicht auf Nutzdaten,
     * sondern auf den Behaelter fuer alle KLEINEN Stroeme (Mini-Stream).
     */
    private function readDirectory(): void
    {
        $directory = $this->readSectorChain($this->uint32(0x30));
        $count = intdiv(strlen($directory), 128);

        $rootStart = null;
        $rootSize = 0;
        $pending = [];

        for ($i = 0; $i < $count; $i++) {
            $base = $i * 128;
            $type = ord($directory[$base + 0x42] ?? "\0");
            if ($type === 0) { // unbenutzt
                continue;
            }
            $nameLength = unpack('v', substr($directory, $base + 0x40, 2))[1] ?? 0;
            $name = $nameLength > 2
                ? (string) mb_convert_encoding(substr($directory, $base, $nameLength - 2), 'UTF-8', 'UTF-16LE')
                : '';
            $start = unpack('V', substr($directory, $base + 0x74, 4))[1] ?? 0;
            // Die Groesse steht als 64-Bit-Wert; der obere Teil ist bei
            // Excel-Dateien praktisch immer 0 und wird nur gelesen, damit
            // eine grosse Datei nicht still abgeschnitten wird.
            $sizeLow = unpack('V', substr($directory, $base + 0x78, 4))[1] ?? 0;
            $sizeHigh = unpack('V', substr($directory, $base + 0x7C, 4))[1] ?? 0;
            $size = $sizeLow + ($sizeHigh << 32);

            if ($type === 5) { // Wurzel
                $rootStart = $start;
                $rootSize = $size;
                continue;
            }
            if ($type !== 2) { // nur Datenstroeme, keine Ordner
                continue;
            }
            $pending[$name] = ['start' => $start, 'size' => $size, 'mini' => $size < $this->miniCutoff];
        }

        if ($rootStart !== null && $rootStart !== self::END_OF_CHAIN) {
            $this->readMiniFat();
            $this->miniStream = substr($this->readSectorChain($rootStart), 0, $rootSize);
        }
        $this->entries = $pending;
    }

    private function readMiniFat(): void
    {
        $next = $this->uint32(0x3C);
        $guard = 0;
        while ($next !== self::END_OF_CHAIN && $next !== self::FREE_SECTOR && $guard++ < 100000) {
            $offset = $this->sectorOffset($next);
            for ($i = 0, $n = intdiv($this->sectorSize, 4); $i < $n; $i++) {
                $this->miniFat[] = $this->uint32($offset + $i * 4);
            }
            $next = $this->fat[$next] ?? self::END_OF_CHAIN;
        }
    }

    private function readSectorChain(int $start): string
    {
        $out = '';
        $sector = $start;
        $guard = 0;
        while ($sector !== self::END_OF_CHAIN && $sector !== self::FREE_SECTOR && $guard++ < 1000000) {
            $out .= substr($this->data, $this->sectorOffset($sector), $this->sectorSize);
            $sector = $this->fat[$sector] ?? self::END_OF_CHAIN;
        }
        return $out;
    }

    /** @param array<int,int> $fat */
    private function readChain(array $fat, string $container, int $start, int $size): string
    {
        $out = '';
        $sector = $start;
        $guard = 0;
        while ($sector !== self::END_OF_CHAIN && $sector !== self::FREE_SECTOR && $guard++ < 1000000) {
            $out .= substr($container, $sector * $size, $size);
            $sector = $fat[$sector] ?? self::END_OF_CHAIN;
        }
        return $out;
    }

    private function sectorOffset(int $sector): int
    {
        return 512 + $sector * $this->sectorSize;
    }

    private function uint16(int $offset): int
    {
        return unpack('v', substr($this->data, $offset, 2))[1] ?? 0;
    }

    private function uint32(int $offset): int
    {
        return unpack('V', substr($this->data, $offset, 4))[1] ?? 0;
    }
}
