<?php

namespace App\Services\Ai\Training;

use App\Models\AiTrainingExample;
use App\Models\Channel;
use App\Models\CustomerMessage;
use App\Models\TrainingImport;
use App\Models\TrainingImportMessage;
use App\Models\User;
use App\Services\Messaging\ConversationEngine;
use Illuminate\Support\Facades\DB;

/**
 * Der Weg vom hochgeladenen Verlauf zum stummen Gespraechsverlauf und
 * zu geschwaerzten Uebungsbeispielen (Phase 3).
 *
 * ZWEI SCHRITTE, bewusst getrennt:
 *   analyze()  liest die Datei und legt einen ENTWURF ab - es entsteht
 *              keine einzige Nachricht.
 *   confirm()  macht daraus Nachrichten im Verlauf und Frage-Antwort-
 *              Paare.
 *
 * Dazwischen sieht ein Mensch, was erkannt wurde, ordnet die Kundenakte
 * zu und sagt, welcher Absender der BETRIEB ist. Beides wird nie
 * geraten.
 */
class TrainingImportService
{
    /**
     * Jedes wievielte Beispiel wird ZURUECKGEHALTEN.
     *
     * Der zurueckgehaltene Teil dient ausschliesslich der spaeteren
     * Kompetenzmessung (Auftrag Abschnitt 21). Ohne diese Trennung
     * prueft man die KI an genau den Antworten, die man ihr vorher
     * gegeben hat - das Ergebnis waere immer gut und immer wertlos.
     *
     * DETERMINISTISCH (jedes vierte), nicht zufaellig: zwei Laeufe auf
     * derselben Datei muessen dasselbe ergeben, sonst ist eine Messung
     * nicht wiederholbar.
     */
    public const HOLDOUT_EVERY = 4;

    /** Kuerzere Texte tragen keine Auskunft ("ok", "danke", "👍"). */
    private const MIN_LENGTH = 15;

    public function __construct(
        private readonly WhatsAppExportParser $parser,
        private readonly PiiRedactor $redactor,
        private readonly ConversationEngine $engine,
    ) {}

    /**
     * Die Datei lesen und als ENTWURF ablegen.
     *
     * Es wird NICHTS am Kundenverlauf geaendert. Der Rohtext der Datei
     * wird ebenfalls nicht gespeichert - nur die erkannten Zeilen
     * (Datenminimierung, dieselbe Regel wie bei der Dokumentanalyse).
     */
    public function analyze(string $inhalt, string $dateiname, User $benutzer): TrainingImport
    {
        $ergebnis = $this->parser->parse($inhalt);

        return DB::transaction(function () use ($ergebnis, $dateiname, $benutzer) {
            $import = TrainingImport::create([
                'user_id' => $benutzer->id,
                'file_name' => mb_substr($dateiname, 0, 200),
                'source_type' => TrainingImport::SOURCE_WHATSAPP,
                'status' => TrainingImport::STATUS_ENTWURF,
                'stats' => [
                    'senders' => $ergebnis['senders'],
                    'message_count' => count($ergebnis['messages']),
                    'system_lines' => $ergebnis['system_lines'],
                    'media_notes' => $ergebnis['media_notes'],
                    'unparsed_lines' => $ergebnis['unparsed_lines'],
                    'first_at' => $ergebnis['first_at'],
                    'last_at' => $ergebnis['last_at'],
                ],
            ]);

            foreach (array_chunk($ergebnis['messages'], 500) as $block) {
                $zeilen = [];
                foreach ($block as $nachricht) {
                    $zeilen[] = [
                        'training_import_id' => $import->id,
                        'row_number' => $nachricht['row'],
                        'sent_at' => $nachricht['sent_at'],
                        'sender_label' => mb_substr($nachricht['sender'], 0, 190),
                        'body' => $nachricht['body'],
                        'has_media_note' => $nachricht['has_media_note'],
                        'created_at' => now(),
                    ];
                }
                TrainingImportMessage::insert($zeilen);
            }

            return $import;
        });
    }

    /**
     * Den Entwurf uebernehmen.
     *
     * IDEMPOTENT: jede Zeile bekommt eine abgeleitete externe Kennung
     * (`training:<import>:<zeile>`), und auf (Unterhaltung, externe
     * Kennung) liegt ein UNIQUE. Ein zweiter Anlauf nach einem Abbruch
     * erzeugt deshalb keine doppelten Nachrichten - ein Import, den man
     * nicht wiederholen darf, ist bei einem Abbruch wertlos.
     *
     * @return array{messages:int, examples:int, skipped:int}
     */
    public function confirm(TrainingImport $import, User $benutzer): array
    {
        if ($import->blockers() !== []) {
            throw new \RuntimeException(implode(' ', $import->blockers()));
        }

        $kunde = $import->customer;
        $kanal = Channel::where('key', 'whatsapp')->firstOrFail();

        return DB::transaction(function () use ($import, $kunde, $kanal, $benutzer) {
            $unterhaltung = $this->engine->forCustomer($kunde, $kanal);

            /*
             * Die Gegenstelle nur ERGAENZEN, nie ueberschreiben. Hat der
             * Kunde eine Rufnummer, wird die Unterhaltung damit
             * fortsetzbar; hat er keine, bleibt sie ein reines Archiv -
             * und das ist ehrlicher als eine erfundene Adresse, die beim
             * ersten Antwortversuch stillschweigend ins Leere ginge.
             */
            $nummer = preg_replace('/\D+/', '', (string) ($kunde->mobile ?: $kunde->phone));
            if ($nummer && ! $unterhaltung->external_user_id) {
                $unterhaltung->forceFill(['external_user_id' => $nummer])->save();
            }

            $angelegt = 0;
            $uebersprungen = 0;

            foreach ($import->messages()->orderBy('row_number')->cursor() as $zeile) {
                $vonUns = $this->istBetrieb($zeile->sender_label, $import->business_sender);
                $kennung = 'training:'.$import->id.':'.$zeile->row_number;

                // Zweite Verteidigungslinie zum UNIQUE: bei einem zweiten
                // Anlauf soll nicht die Datenbank den Abbruch erzwingen.
                if (CustomerMessage::where('conversation_id', $unterhaltung->id)
                    ->where('external_message_id', $kennung)->exists()) {
                    $uebersprungen++;

                    continue;
                }

                $nachricht = CustomerMessage::create([
                    'conversation_id' => $unterhaltung->id,
                    'customer_id' => $kunde->id,
                    'channel_id' => $kanal->id,
                    'direction' => $vonUns
                        ? CustomerMessage::DIRECTION_OUTGOING
                        : CustomerMessage::DIRECTION_INCOMING,
                    // Getippt hat ein Mensch - nur eben nicht hier. Als
                    // `system` zu buchen waere falsch.
                    'sender_type' => $vonUns
                        ? CustomerMessage::SENDER_EMPLOYEE
                        : CustomerMessage::SENDER_CUSTOMER,
                    'sender_id' => $vonUns ? $benutzer->id : null,
                    'from_staff' => $vonUns,
                    'external_message_id' => $kennung,
                    'body' => $zeile->body,
                    'message_type' => CustomerMessage::TYPE_TEXT,
                    // STUMM: `historical` heisst sichtbar und durchsuchbar,
                    // aber kein Ereignis - keine KI-Antwort, kein
                    // Ungelesen-Stand, kein Versand. Genau deshalb kann
                    // ein Verlauf von vor Monaten gefahrlos hereinkommen.
                    'source' => CustomerMessage::SOURCE_HISTORICAL,
                    'status' => CustomerMessage::STATUS_DELIVERED,
                    'read_at' => now(),
                    'email_mode' => 'none',
                ]);

                // Der ECHTE Zeitpunkt. Ohne ihn stuende der ganze Verlauf
                // unter dem Datum des Imports und die Reihenfolge waere
                // Zufall.
                if ($zeile->sent_at) {
                    $nachricht->forceFill(['created_at' => $zeile->sent_at])->saveQuietly();
                }

                $angelegt++;
            }

            // Eine nachgelieferte Nachricht holt eine Unterhaltung NIE
            // nach oben: sie ist alt. Der Zeitpunkt wird nur gesetzt,
            // wenn es noch keinen gibt.
            if (! $unterhaltung->last_message_at && $import->stats['last_at'] ?? null) {
                $unterhaltung->forceFill(['last_message_at' => $import->stats['last_at']])->save();
            }

            $beispiele = $this->extractExamples($import);

            $import->forceFill([
                'status' => TrainingImport::STATUS_IMPORTIERT,
                'confirmed_at' => now(),
            ])->save();

            return ['messages' => $angelegt, 'examples' => $beispiele, 'skipped' => $uebersprungen];
        });
    }

    /**
     * Aus dem Verlauf GESCHWAERZTE Frage-Antwort-Paare gewinnen.
     *
     * Ein Paar entsteht nur aus "Kunde fragt -> Betrieb antwortet". Zwei
     * Kundennachrichten hintereinander sind keine Antwort, und zwei
     * unserer eigenen auch nicht.
     *
     * Jedes Paar entsteht OFFEN - nichts wird automatisch zur Auskunft
     * (Auftrag Abschnitt 19: nicht jede historische Antwort war richtig).
     */
    public function extractExamples(TrainingImport $import): int
    {
        $namen = $this->bekannteNamen($import);
        $offeneFrage = null;
        $anzahl = 0;

        foreach ($import->messages()->orderBy('row_number')->cursor() as $zeile) {
            // Ein Medienhinweis ist kein Text - "<Medien ausgeschlossen>"
            // als Frage oder Antwort waere ein leeres Beispiel.
            if ($zeile->has_media_note || mb_strlen(trim($zeile->body)) < self::MIN_LENGTH) {
                continue;
            }

            $vonUns = $this->istBetrieb($zeile->sender_label, $import->business_sender);

            if (! $vonUns) {
                // Schreibt der Kunde mehrfach, zaehlt die LETZTE Frage
                // vor der Antwort - sie ist die, auf die geantwortet wurde.
                $offeneFrage = $zeile->body;

                continue;
            }

            if ($offeneFrage === null) {
                continue;
            }

            $frage = $this->redactor->redact($offeneFrage, $namen);
            $antwort = $this->redactor->redact($zeile->body, $namen);

            AiTrainingExample::create([
                'training_import_id' => $import->id,
                // NUR die geschwaerzte Fassung - der Rohtext wird hier
                // nie gespeichert.
                'question' => $frage['text'],
                'answer' => $antwort['text'],
                'status' => AiTrainingExample::STATUS_OFFEN,
                'is_holdout' => (($anzahl + 1) % self::HOLDOUT_EVERY) === 0,
                'redaction_report' => [
                    'counts' => $this->summiere($frage['report']['counts'], $antwort['report']['counts']),
                    'warnings' => array_values(array_unique(array_merge(
                        $frage['report']['warnings'],
                        $antwort['report']['warnings']
                    ))),
                ],
            ]);

            $anzahl++;
            $offeneFrage = null;
        }

        return $anzahl;
    }

    /**
     * Die Namen, die SICHER geschwaerzt werden duerfen.
     *
     * Ausschliesslich Tatsachen: die Absender aus dem Export und der
     * Name der zugeordneten Kundenakte. Namen allgemein zu erkennen
     * waere Raten ("Mai" ist ein Monat und ein Nachname).
     *
     * @return array<int,string>
     */
    private function bekannteNamen(TrainingImport $import): array
    {
        $namen = array_keys($import->senders());

        $kunde = $import->customer;
        if ($kunde) {
            foreach ([$kunde->first_name, $kunde->last_name, $kunde->company_name] as $teil) {
                if ($teil) {
                    $namen[] = (string) $teil;
                }
            }
            // Der Anzeigename haengt am Benutzer, nicht am Kunden.
            if ($kunde->user?->name) {
                foreach (preg_split('/\s+/', $kunde->user->name) ?: [] as $wort) {
                    $namen[] = $wort;
                }
            }
        }

        return $namen;
    }

    /**
     * Ist dieser Absender der BETRIEB?
     *
     * Verglichen wird normalisiert, weil der Export denselben Namen je
     * nach Geraet mit oder ohne Zusatz schreibt. Geraten wird dabei
     * nicht: Massstab ist ausschliesslich der Name, den ein Mensch
     * ausgewaehlt hat.
     */
    private function istBetrieb(string $absender, ?string $betrieb): bool
    {
        if (! $betrieb) {
            return false;
        }

        return $this->normalisiere($absender) === $this->normalisiere($betrieb);
    }

    private function normalisiere(string $wert): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $wert) ?? $wert));
    }

    /**
     * @param  array<string,int>  $a
     * @param  array<string,int>  $b
     * @return array<string,int>
     */
    private function summiere(array $a, array $b): array
    {
        foreach ($b as $schluessel => $anzahl) {
            $a[$schluessel] = ($a[$schluessel] ?? 0) + $anzahl;
        }

        return $a;
    }
}
