<?php
namespace App\Services\Ai\Assistant\Sales;

/**
 * Der Gespraechszustand (Spezifikation Abschnitt 12).
 *
 * Warum ein Feld und keine Auswertung des Nachrichtenverlaufs: nach einer
 * Stoerung, nach einer Uebernahme durch den Mitarbeiter und nach jedem
 * Neustart muss ohne Nachdenken feststehen, wo der Kunde steht. Ein
 * Verlauf laesst sich unterschiedlich lesen, ein Zustand nicht.
 *
 * WICHTIG: der Zustand wechselt NUR ueber erlaubte Uebergaenge und nur
 * ueber Werkzeuge - nie, weil das Modell im Fliesstext behauptet, man sei
 * jetzt beim Vertrag. So kann kein Sprung von "gerade begonnen" nach
 * "Vertrag fertig" entstehen.
 */
final class ConversationState
{
    public const NEW = 'NEW';
    public const IDENTIFYING_CUSTOMER = 'IDENTIFYING_CUSTOMER';
    public const COLLECTING_REQUIREMENTS = 'COLLECTING_REQUIREMENTS';
    public const COLLECTING_ADDRESS = 'COLLECTING_ADDRESS';
    public const WAITING_FOR_OFFER = 'WAITING_FOR_OFFER';
    public const OFFER_PRESENTED = 'OFFER_PRESENTED';
    public const WAITING_FOR_CUSTOMER_DECISION = 'WAITING_FOR_CUSTOMER_DECISION';
    public const CUSTOMER_ACCEPTED = 'CUSTOMER_ACCEPTED';
    public const COLLECTING_CONTRACT_DATA = 'COLLECTING_CONTRACT_DATA';
    public const VERIFYING_DATA = 'VERIFYING_DATA';
    public const VERIFICATION_PASSED = 'VERIFICATION_PASSED';
    public const CONTRACT_READY = 'CONTRACT_READY';
    public const COMPLETED = 'COMPLETED';

    /** Quer zu allem: der Mitarbeiter ist zustaendig. */
    public const HUMAN_REQUIRED = 'HUMAN_REQUIRED';

    /**
     * Erlaubte Folgezustaende. Aus jedem Zustand ist zusaetzlich
     * HUMAN_REQUIRED erlaubt (Uebergabe geht immer) - das steht nicht in
     * der Liste, sondern in allows().
     */
    public const TRANSITIONS = [
        self::NEW => [self::IDENTIFYING_CUSTOMER, self::COLLECTING_REQUIREMENTS, self::COMPLETED],
        self::IDENTIFYING_CUSTOMER => [self::COLLECTING_REQUIREMENTS, self::COMPLETED],
        self::COLLECTING_REQUIREMENTS => [self::COLLECTING_ADDRESS, self::WAITING_FOR_OFFER, self::COMPLETED],
        self::COLLECTING_ADDRESS => [self::COLLECTING_REQUIREMENTS, self::WAITING_FOR_OFFER],
        self::WAITING_FOR_OFFER => [self::OFFER_PRESENTED, self::COLLECTING_REQUIREMENTS],
        // Zustimmung darf direkt nach dem Vorstellen kommen: viele Kunden
        // antworten sofort mit "passt so" - ein Zwischenschritt waere nur
        // Buerokratie und wuerde die Zusage verschlucken.
        self::OFFER_PRESENTED => [self::WAITING_FOR_CUSTOMER_DECISION, self::CUSTOMER_ACCEPTED, self::WAITING_FOR_OFFER],
        self::WAITING_FOR_CUSTOMER_DECISION => [self::CUSTOMER_ACCEPTED, self::WAITING_FOR_OFFER, self::COMPLETED],
        self::CUSTOMER_ACCEPTED => [self::COLLECTING_CONTRACT_DATA],
        self::COLLECTING_CONTRACT_DATA => [self::VERIFYING_DATA],
        self::VERIFYING_DATA => [self::VERIFICATION_PASSED, self::COLLECTING_CONTRACT_DATA],
        self::VERIFICATION_PASSED => [self::CONTRACT_READY],
        self::CONTRACT_READY => [self::COMPLETED],
        self::COMPLETED => [self::NEW],
        // Nach der Uebernahme entscheidet der Mensch, wo es weitergeht.
        self::HUMAN_REQUIRED => [
            self::NEW, self::COLLECTING_REQUIREMENTS, self::COLLECTING_ADDRESS,
            self::WAITING_FOR_OFFER, self::OFFER_PRESENTED,
            self::WAITING_FOR_CUSTOMER_DECISION, self::CUSTOMER_ACCEPTED,
            self::COLLECTING_CONTRACT_DATA, self::VERIFYING_DATA,
            self::VERIFICATION_PASSED, self::CONTRACT_READY, self::COMPLETED,
        ],
    ];

    /** Anzeige in der Beraterwelt - kurz und in der Sprache des Betriebs. */
    public const LABELS = [
        self::NEW => 'Neu',
        self::IDENTIFYING_CUSTOMER => 'Kunde wird zugeordnet',
        self::COLLECTING_REQUIREMENTS => 'Bedarf wird erfasst',
        self::COLLECTING_ADDRESS => 'Anschrift wird erfasst',
        self::WAITING_FOR_OFFER => 'Wartet auf Angebot des Mitarbeiters',
        self::OFFER_PRESENTED => 'Angebot vorgestellt',
        self::WAITING_FOR_CUSTOMER_DECISION => 'Wartet auf Entscheidung des Kunden',
        self::CUSTOMER_ACCEPTED => 'Kunde hat zugestimmt',
        self::COLLECTING_CONTRACT_DATA => 'Vertragsdaten werden erfasst',
        self::VERIFYING_DATA => 'Angaben werden geprueft',
        self::VERIFICATION_PASSED => 'Angaben geprueft',
        self::CONTRACT_READY => 'Bereit zum Abschluss',
        self::COMPLETED => 'Abgeschlossen',
        self::HUMAN_REQUIRED => 'Mitarbeiter erforderlich',
    ];

    /**
     * Zustaende, in denen der Mitarbeiter am Zug ist - die KI wartet und
     * fragt den Kunden nicht weiter aus.
     */
    public const WAITS_FOR_STAFF = [
        self::WAITING_FOR_OFFER,
        self::CONTRACT_READY,
        self::HUMAN_REQUIRED,
    ];

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $state): string
    {
        return self::LABELS[$state ?? self::NEW] ?? (string) $state;
    }

    public static function exists(?string $state): bool
    {
        return $state !== null && isset(self::LABELS[$state]);
    }

    /** Ist der Uebergang erlaubt? Uebergabe an den Menschen immer. */
    public static function allows(?string $from, string $to): bool
    {
        if (!self::exists($to)) {
            return false;
        }
        $from = self::exists($from) ? $from : self::NEW;

        if ($to === self::HUMAN_REQUIRED || $to === $from) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Wartet dieser Zustand auf eine Mitarbeiter-Aktion? */
    public static function waitsForStaff(?string $state): bool
    {
        return in_array($state, self::WAITS_FOR_STAFF, true);
    }

    /**
     * Der naechste Schritt in Klartext - fuer die Beraterwelt und fuer den
     * Fall, dass die KI ausfaellt: der Mitarbeiter soll ohne Nachlesen
     * wissen, was zu tun ist (Abschnitt 13/15).
     */
    public static function nextAction(?string $state): string
    {
        return match ($state) {
            self::NEW, self::IDENTIFYING_CUSTOMER => 'Anliegen des Kunden klären',
            self::COLLECTING_REQUIREMENTS => 'Fehlende Angaben erfragen',
            self::COLLECTING_ADDRESS => 'Anschrift vervollständigen',
            self::WAITING_FOR_OFFER => 'Angebot hinterlegen (Mitarbeiter)',
            self::OFFER_PRESENTED, self::WAITING_FOR_CUSTOMER_DECISION => 'Entscheidung des Kunden abwarten',
            self::CUSTOMER_ACCEPTED, self::COLLECTING_CONTRACT_DATA => 'Vertragsdaten vervollständigen',
            self::VERIFYING_DATA => 'Pruefung der Angaben abwarten',
            self::VERIFICATION_PASSED, self::CONTRACT_READY => 'Vertrag abschliessen (Mitarbeiter)',
            self::COMPLETED => 'Nichts offen',
            self::HUMAN_REQUIRED => 'Unterhaltung übernehmen',
            default => 'Anliegen des Kunden klären',
        };
    }
}
