<?php

namespace App\Console\Commands;

use App\Models\AiKnowledgeEntry;
use App\Models\CustomerMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Gespraechsleitfaden aus echten Mitarbeiter-Antworten VORSCHLAGEN
 * (Spezifikation Abschnitt 17).
 *
 * Was hier bewusst NICHT passiert: kein Nachtrainieren des Modells und
 * kein woertliches Nachahmen einzelner Mitarbeiter. Beides waere
 * datenschutzrechtlich heikel (Kundeninhalte in Trainingsdaten) und
 * fachlich falsch - es wuerde auch die Fehler der Vergangenheit
 * einbrennen.
 *
 * Stattdessen wird gemessen, was messbar ist: Laenge, Ansprache,
 * Begruessung und Abschluss, Fragen je Nachricht. Daraus entsteht ein
 * ENTWURF fuer die Wissensbasis (Kategorie 'leitfaden'), den ein Mensch
 * liest, korrigiert und freischaltet. Der Assistent nutzt ihn erst,
 * wenn er aktiv ist.
 *
 * Nur lesend, solange --schreiben fehlt.
 */
class DraftAssistantStyleGuide extends Command
{
    protected $signature = 'ki:leitfaden-entwurf
        {--tage=90 : Zeitraum der ausgewerteten Mitarbeiter-Antworten}
        {--schreiben : Entwurf als INAKTIVEN Eintrag in der Wissensbasis anlegen}';

    protected $description = 'Gesprächsleitfaden aus bisherigen Mitarbeiter-Antworten vorschlagen (Entwurf, nie automatisch aktiv)';

    public function handle(): int
    {
        $tage = max(7, (int) $this->option('tage'));

        $nachrichten = CustomerMessage::fromStaff()
            ->where('ai_generated', false)
            ->where('created_at', '>=', now()->subDays($tage))
            ->pluck('body')
            ->map(fn ($b) => trim((string) $b))
            ->filter(fn ($b) => mb_strlen($b) >= 40)
            ->values();

        if ($nachrichten->count() < 20) {
            $this->warn('Zu wenige Mitarbeiter-Antworten im Zeitraum (' . $nachrichten->count() . ').');
            $this->line('Ein Leitfaden aus einer Handvoll Nachrichten waere geraten, nicht beobachtet.');

            return self::FAILURE;
        }

        $laengen = $nachrichten->map(fn ($b) => mb_strlen($b))->sort()->values();
        $median = (int) $laengen[(int) floor($laengen->count() / 2)];
        $saetze = (int) round($nachrichten->map(
            fn ($b) => max(1, preg_match_all('/[.!?]+/', $b))
        )->avg());

        $sieForm = $this->anteil($nachrichten, '/\bSie\b|\bIhre?n?\b/u');
        $begruessung = $this->anteil($nachrichten, '/^(guten (tag|morgen|abend)|hallo|sehr geehrte)/iu');
        $abschluss = $this->anteil($nachrichten, '/(freundlichen grüßen|freundlichen gruessen|beste grüße|melden sie sich|gerne helfen wir)/iu');
        $rueckfrage = $this->anteil($nachrichten, '/\?/');

        $this->info('Ausgewertet: ' . $nachrichten->count() . ' Mitarbeiter-Antworten der letzten ' . $tage . ' Tage.');
        $this->line('  Typische Laenge: ' . $median . ' Zeichen, etwa ' . $saetze . ' Saetze');
        $this->line('  Sie-Ansprache:   ' . $sieForm . ' %');
        $this->line('  Mit Begruessung: ' . $begruessung . ' %');
        $this->line('  Mit Abschluss:   ' . $abschluss . ' %');
        $this->line('  Mit Rueckfrage:  ' . $rueckfrage . ' %');

        $inhalt = $this->guide($median, $saetze, $sieForm, $begruessung, $abschluss, $rueckfrage, $nachrichten->count(), $tage);

        $this->newLine();
        $this->line('--- Entwurf ---');
        $this->line($inhalt);
        $this->line('--- Ende ---');

        if (!$this->option('schreiben')) {
            $this->newLine();
            $this->line('Nur angezeigt. Zum Anlegen (INAKTIV, zur Freigabe):');
            $this->line('  php artisan ki:leitfaden-entwurf --schreiben');

            return self::SUCCESS;
        }

        $eintrag = AiKnowledgeEntry::create([
            'title' => 'Gesprächsleitfaden (Entwurf vom ' . now()->format('d.m.Y') . ')',
            'category' => 'leitfaden',
            'content' => $inhalt,
            'keywords' => 'Gesprächsleitfaden Stil Ablauf Antwort',
            // NIE automatisch aktiv: ein Mensch liest und gibt frei.
            'active' => false,
        ]);

        $this->newLine();
        $this->info('Entwurf angelegt (inaktiv): ' . $eintrag->title);
        $this->line('Freigeben unter /admin/ki-wissensbasis - erst dann nutzt der Assistent ihn.');

        return self::SUCCESS;
    }

    /** Anteil der Nachrichten, auf die ein Muster passt (in Prozent). */
    private function anteil($nachrichten, string $muster): int
    {
        $treffer = $nachrichten->filter(fn ($b) => preg_match($muster, $b) === 1)->count();

        return (int) round($treffer / max(1, $nachrichten->count()) * 100);
    }

    /** Der Entwurfstext - bewusst als Beobachtung formuliert, nicht als Gesetz. */
    private function guide(int $median, int $saetze, int $sie, int $begruessung, int $abschluss, int $rueckfrage, int $anzahl, int $tage): string
    {
        $laenge = $median < 250 ? 'kurz' : ($median < 600 ? 'mittellang' : 'ausfuehrlich');

        return implode("\n", array_filter([
            'Beobachteter Antwortstil des Teams (' . $anzahl . ' Antworten der letzten ' . $tage . ' Tage).',
            'Vor der Nutzung pruefen und anpassen - dies ist eine Messung, keine Vorgabe.',
            '',
            'Laenge: ' . $laenge . ', typisch etwa ' . $median . ' Zeichen bzw. ' . $saetze . ' Saetze.',
            $sie >= 70 ? 'Ansprache: durchgehend "Sie".' : null,
            $begruessung >= 50 ? 'Beginn: mit Begruessung ("Guten Tag ...").' : 'Beginn: meist direkt zur Sache, ohne lange Begruessung.',
            $abschluss >= 50 ? 'Abschluss: mit Gruss bzw. Angebot weiterer Hilfe.' : null,
            $rueckfrage >= 40
                ? 'Rueckfragen: das Team fragt aktiv nach, wenn Angaben fehlen.'
                : 'Rueckfragen: nur, wenn ohne die Angabe nicht weitergearbeitet werden kann.',
            '',
            'Unveraenderliche Regeln (unabhaengig vom Stil):',
            '- Nichts behaupten, was nicht in den Kundendaten oder in dieser Wissensbasis steht.',
            '- Keine Preise, Fristen oder Zusagen ohne hinterlegtes Angebot.',
            '- Verbindliches (Kuendigung, Genehmigung, Geld, Deckung) entscheidet immer ein Mitarbeiter.',
            '- Nie bestaetigen, ob eine Kundenangabe mit den gespeicherten Daten uebereinstimmt.',
        ], fn ($z) => $z !== null));
    }
}
