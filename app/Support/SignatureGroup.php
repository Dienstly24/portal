<?php

namespace App\Support;

use App\Models\SignatureField;
use App\Models\SignatureSigner;
use Illuminate\Support\Collection;

/**
 * EIN Unterzeichner + EINE Handschriftart = EINE Unterschrift.
 *
 * DAS PROBLEM, DAS DIESE KLASSE LOEST (Betreiber-Vorgabe 10.09.2026):
 * Bisher hing das Unterschriftsbild am FELD. Ein Dokument mit sieben
 * Unterschriftsfeldern verlangte deshalb sieben Zeichnungen - der Kunde
 * malte siebenmal, jedes Mal ein bisschen anders, und auf Seite 1 stand
 * eine andere Unterschrift als auf Seite 9. Sieben FELDER sind aber nicht
 * sieben WILLENSERKLAERUNGEN: es ist eine Unterschrift, die an sieben
 * Stellen steht - so wie man einen Papiervertrag auch nur einmal
 * unterschreibt, selbst wenn das Kuerzel auf jeder Seite wiederholt wird.
 *
 * WARUM KEINE EIGENE TABELLE (die eigentliche Architekturfrage):
 * Eine Tabelle `signature_groups` mit einer eigenen ID waere eine ZWEITE
 * Quelle fuer etwas, das die vorhandenen Daten bereits eindeutig
 * bestimmen - naemlich das Paar (Unterzeichner, Feldart). Sie koennte
 * auseinanderlaufen: ein Feld ohne Gruppe, eine Gruppe ohne Felder, zwei
 * Gruppen fuer denselben Menschen. Genau diese Art von Duplikat hat im
 * Bestand schon einmal geschmerzt (zwei Vertraege fuer einen Vorgang).
 * Der Gruppenschluessel ist deshalb ABGELEITET und kann nicht falsch
 * sein. Die Gruppe ist trotzdem ein benanntes Ding im Code - man kann
 * ueber sie reden, sie zaehlen und sie anzeigen; sie braucht nur keine
 * eigene Zeile.
 *
 * WARUM NACH ART GETRENNT: Unterschrift und Initialen sind zwei
 * verschiedene Handschriften. Sie in eine Gruppe zu werfen hiesse, das
 * volle Namenszeichen in ein Initialen-Kaestchen zu quetschen.
 *
 * ERWEITERBAR (Betreiber-Vorgabe 20): kommt spaeter eine weitere
 * gezeichnete Art dazu, taucht sie hier von selbst als eigene Gruppe auf -
 * es ist keine Migration und keine Sonderbehandlung noetig.
 */
final class SignatureGroup
{
    /**
     * @param  Collection<int,SignatureField>  $fields
     */
    private function __construct(
        public readonly SignatureSigner $signer,
        public readonly string $type,
        public readonly Collection $fields,
    ) {}

    /**
     * Alle Gruppen EINES Unterzeichners, in der Reihenfolge der Feldarten.
     *
     * @param  Collection<int,SignatureField>  $fields  Felder dieses Vorgangs
     * @return Collection<string,self>  Schluessel ist die Feldart
     */
    public static function forSigner(SignatureSigner $signer, Collection $fields): Collection
    {
        return $fields
            ->filter(fn (SignatureField $f) => $f->signature_signer_id === $signer->id && $f->isDrawn())
            ->groupBy(fn (SignatureField $f) => (string) $f->type)
            ->map(fn (Collection $gefunden, string $type) => new self(
                $signer,
                $type,
                // Nach Seite sortiert: der Zaehler "7 Stellen" soll in der
                // Reihenfolge stehen, in der der Mensch das Dokument liest.
                $gefunden->sortBy([['page', 'asc'], ['pos_y', 'asc']])->values(),
            ));
    }

    /**
     * Der Schluessel, unter dem die EINE Zeichnung uebertragen wird.
     *
     * Er traegt die Unterzeichner-ID NICHT: das Formular gehoert bereits
     * genau einem Unterzeichner (der Zugang haengt am Token). Eine ID im
     * Feldnamen waere eine Einladung, sie zu vertauschen - der Server
     * loest die Gruppe deshalb aus der Sitzung auf, nie aus dem Browser
     * (dieselbe Regel wie bei den Werkzeugen des KI-Assistenten).
     */
    public function key(): string
    {
        return $this->type;
    }

    /** Wie viele Stellen im Dokument diese eine Unterschrift fuellt. */
    public function count(): int
    {
        return $this->fields->count();
    }

    /** @return array<int,int> Seitenzahlen, aufsteigend und ohne Dopplung. */
    public function pages(): array
    {
        return $this->fields->pluck('page')->unique()->sort()->values()->all();
    }

    /**
     * Muss diese Gruppe gezeichnet werden?
     *
     * Pflicht ist sie, sobald EIN Feld darin Pflicht ist - die Unterschrift
     * entsteht ja nur einmal fuer alle.
     */
    public function required(): bool
    {
        return $this->fields->contains(fn (SignatureField $f) => (bool) $f->required);
    }

    public function label(): string
    {
        return SignatureFieldType::label($this->type);
    }

    /**
     * Der Ablageschluessel der EINEN Zeichnung (ohne Endung).
     *
     * Alle Felder der Gruppe zeigen anschliessend auf genau diese Datei -
     * nicht auf Kopien. Damit ist "auf allen Seiten dieselbe Unterschrift"
     * keine Zusage, die man testen muesste, sondern eine Eigenschaft der
     * Ablage: es GIBT nur ein Bild.
     */
    public function imageKey(): string
    {
        return 'signer-'.$this->signer->id.'-'.$this->type;
    }
}
