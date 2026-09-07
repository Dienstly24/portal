<?php

namespace App\Support;

/**
 * Betriebsart des KI-Assistenten (Auftrag Abschnitt 88).
 *
 * Bewusst eine Liste von Zeichenketten und kein PHP-Enum in der Spalte:
 * eine neue Betriebsart darf keine Migration kosten - dieselbe Regel wie
 * bei `Contract::TYPES` und den Nachrichtenarten.
 *
 * OFF und HUMAN_ONLY schweigen beide. Sie werden trotzdem
 * UNTERSCHIEDEN, weil sie Verschiedenes bedeuten: OFF heisst "dieser
 * Kanal nutzt die KI nicht", HUMAN_ONLY heisst "hier antwortet
 * ausdruecklich ein Mensch". Wer beides zu einem Wert zusammenzieht,
 * kann spaeter nicht mehr sagen, ob jemand die KI abgeschaltet oder eine
 * Betreuungsentscheidung getroffen hat.
 */
final class AiMode
{
    /** KI ist fuer diese Ebene nicht in Betrieb. */
    public const OFF = 'off';

    /** KI antwortet selbstaendig. */
    public const AUTO_REPLY = 'auto_reply';

    /** KI versucht es zuerst und uebergibt, wenn sie nicht weiterkommt. */
    public const AI_FIRST = 'ai_first';

    /** KI schreibt nur einen VORSCHLAG - gesendet wird er von einem Menschen. */
    public const AI_ASSIST = 'ai_assist';

    /** Bewusst ausschliesslich menschliche Betreuung. */
    public const HUMAN_ONLY = 'human_only';

    public const ALL = [
        self::OFF,
        self::AUTO_REPLY,
        self::AI_FIRST,
        self::AI_ASSIST,
        self::HUMAN_ONLY,
    ];

    public const LABELS = [
        self::OFF => 'Aus - keine KI auf dieser Ebene',
        self::AUTO_REPLY => 'Automatisch antworten',
        self::AI_FIRST => 'KI zuerst, dann Uebergabe',
        self::AI_ASSIST => 'Nur Antwortvorschlag fuer den Mitarbeiter',
        self::HUMAN_ONLY => 'Nur Mitarbeiter',
    ];

    public const DESCRIPTIONS = [
        self::OFF => 'Die KI wird nicht taetig. Die Nachricht geht direkt an den zustaendigen Mitarbeiter.',
        self::AUTO_REPLY => 'Die KI beantwortet, was sie aus Kundenakte und Wissensbasis belegen kann.',
        self::AI_FIRST => 'Wie "Automatisch antworten", aber jede Unsicherheit fuehrt sofort zur Uebergabe an einen Menschen.',
        self::AI_ASSIST => 'Die KI formuliert einen Vorschlag im Mitarbeiter-Panel. An den Kunden geht nichts ohne Klick.',
        self::HUMAN_ONLY => 'Ausdrueckliche Entscheidung: dieser Kunde bzw. Kanal wird nur persoenlich betreut.',
    ];

    /** Unbekannte Werte werden NIE geraten - sie gelten als "nicht gesetzt". */
    public static function valid(?string $mode): bool
    {
        return $mode !== null && in_array($mode, self::ALL, true);
    }

    public static function label(?string $mode): string
    {
        return self::LABELS[$mode] ?? 'Unbekannt';
    }

    /**
     * Darf in dieser Betriebsart eine Antwort AUTOMATISCH an den Kunden
     * gehen? Der Vorschlags-Modus zaehlt bewusst NICHT dazu - dort
     * entscheidet ein Mensch ueber das Senden.
     */
    public static function sendsAutomatically(?string $mode): bool
    {
        return in_array($mode, [self::AUTO_REPLY, self::AI_FIRST], true);
    }

    /** Wird das Modell ueberhaupt befragt (Antwort ODER Vorschlag)? */
    public static function usesModel(?string $mode): bool
    {
        return in_array($mode, [self::AUTO_REPLY, self::AI_FIRST, self::AI_ASSIST], true);
    }
}
