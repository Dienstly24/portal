<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Ein kaputter Datensatz darf nie den ganzen Lauf stoppen.
 *
 * Die geplanten Aufgaben arbeiten Listen ab: faellige Erinnerungen,
 * Vertragswechsel, haengende Analysen. Ohne Absicherung beendet die ERSTE
 * Ausnahme den gesamten Lauf - alle folgenden Datensaetze werden nicht mehr
 * angefasst. Praktisch heisst das: ein Kunde mit einer kaputten Adresse
 * verhindert, dass alle anderen Kunden ihre Erinnerung bekommen. Und weil
 * das im Hintergrund passiert, merkt es niemand.
 *
 * Diese Hilfe kehrt das um:
 *  - jeder Datensatz laeuft fuer sich; ein Fehler ueberspringt genau ihn,
 *  - der Fehler wird protokolliert (mit Kennung des Datensatzes) und in der
 *    Befehlsausgabe genannt - er verschwindet also nicht,
 *  - der Lauf geht bis zum Ende weiter und meldet ERST DANACH einen
 *    Fehlschlag. Genau in dieser Reihenfolge: alles Machbare erledigen,
 *    dann ehrlich melden, dass etwas liegen geblieben ist.
 *
 * Der abschliessende Fehlschlag ist Absicht: Exitcode 1 macht die Aufgabe
 * auf /admin/systemzustand rot. Ein stiller Teilausfall waere schlimmer als
 * ein sichtbarer.
 */
trait ProcessesRecordsSafely
{
    /** Datensaetze, die in diesem Lauf uebersprungen werden mussten. */
    private array $uebersprungeneDatensaetze = [];

    /**
     * Eine Liste Datensatz fuer Datensatz abarbeiten.
     *
     * @param  iterable  $records   Die Datensaetze.
     * @param  callable  $handler   fn($record): void - ein Datensatz.
     * @param  string    $label     Bezeichnung fuer Protokoll und Ausgabe.
     * @return int                  Anzahl erfolgreich verarbeiteter Datensaetze.
     */
    protected function verarbeiteEinzeln(iterable $records, callable $handler, string $label): int
    {
        $erfolgreich = 0;

        foreach ($records as $record) {
            try {
                $handler($record);
                $erfolgreich++;
            } catch (\Throwable $e) {
                $kennung = $this->kennungVon($record);
                $this->uebersprungeneDatensaetze[] = $label . ' ' . $kennung . ': ' . $e->getMessage();

                // Ins Log, damit der Fall nachvollziehbar bleibt - die
                // Befehlsausgabe sieht sich im Cron-Betrieb niemand an.
                Log::error('Uebersprungen in ' . static::class . ' (' . $label . ' ' . $kennung . '): '
                    . $e->getMessage(), ['exception' => $e]);

                $this->meldung('  uebersprungen: ' . $label . ' ' . $kennung . ' - ' . $e->getMessage());
            }
        }

        return $erfolgreich;
    }

    /**
     * Abschluss: uebersprungene Datensaetze nennen und den passenden
     * Exitcode liefern. Immer NACH aller Arbeit aufrufen.
     */
    protected function ergebnisMitUebersprungenen(int $erfolgCode = 0): int
    {
        if ($this->uebersprungeneDatensaetze === []) {
            return $erfolgCode;
        }

        $anzahl = count($this->uebersprungeneDatensaetze);
        $this->meldung($anzahl . ' Datensatz/Datensaetze uebersprungen - der Lauf ist unvollstaendig:');
        // Bewusst gedeckelt: bei einem grossflaechigen Ausfall soll die
        // Ausgabe nicht das Log fluten. Vollstaendig steht alles im Log.
        foreach (array_slice($this->uebersprungeneDatensaetze, 0, 10) as $zeile) {
            $this->meldung('  - ' . $zeile);
        }
        if ($anzahl > 10) {
            $this->meldung('  ... und ' . ($anzahl - 10) . ' weitere (siehe Log).');
        }

        return 1;
    }

    /** Wurden Datensaetze uebersprungen? */
    protected function hatUebersprungene(): bool
    {
        return $this->uebersprungeneDatensaetze !== [];
    }

    /**
     * Auf die Konsole schreiben, sofern eine da ist. Ein direkt erzeugter
     * Befehl (Tests, programmatischer Aufruf) hat keine Ausgabe - dann darf
     * die Meldung nicht ihrerseits eine Ausnahme werfen.
     */
    private function meldung(string $text): void
    {
        if (isset($this->output)) {
            $this->output->writeln('<comment>' . $text . '</comment>');
        }
    }

    /** Sprechende Kennung eines Datensatzes fuer Protokoll und Ausgabe. */
    private function kennungVon(mixed $record): string
    {
        if ($record instanceof \Illuminate\Database\Eloquent\Model) {
            return '#' . $record->getKey();
        }

        if (is_scalar($record)) {
            return '#' . $record;
        }

        return '(unbenannt)';
    }
}
